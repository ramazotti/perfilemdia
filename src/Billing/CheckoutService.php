<?php

declare(strict_types=1);

namespace PerfilEmDia\Billing;

use PDO;
use RuntimeException;

final class CheckoutService
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(
        private readonly PDO $pdo,
        private readonly PaymentGateway $gateway,
    ) {
    }

    /**
     * @param array<string, mixed> $plan
     * @param array{name:string,email:string,phone:string,document:string} $customer
     * @return array<string, mixed>
     */
    public function open(array $plan, string $cycle, array $customer, ?string $coupon): array
    {
        $type = BrazilianDocument::type($customer['document']);
        if ($type === null) {
            throw new RuntimeException('Documento inválido.');
        }
        $document = BrazilianDocument::canonical($customer['document']);
        $trial = Trial::applies($plan, $cycle) && !$this->alreadyPaid($document);
        $listCents = $trial
            ? (int) $plan['trial_price_cents']
            : (int) ($cycle === 'anual' ? $plan['price_cents'] * 10 : $plan['price_cents']);
        $quoted = $this->quoteCoupon($trial ? (int) $plan['trial_price_cents'] : (int) $plan['price_cents'], $cycle, $coupon);
        $amount = $quoted['amount'];
        $now = date('Y-m-d H:i:s');
        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            $customerId = $this->upsertCustomer($customer['name'], $customer['email'], $customer['phone'], $document, $type, $now);
            $periodKind = $trial ? 'teste' : 'cheio';
            $periodDays = $trial ? (int) $plan['trial_days'] : 0;
            $postsLimit = $trial
                ? Trial::posts((int) $plan['posts_limit'], (int) $plan['trial_days'])
                : (int) $plan['posts_limit'];
            $sub = $this->pdo->prepare(
                'INSERT INTO subscriptions (customer_id, plan_id, cycle, period_kind, status, price_cents, posts_limit, period_days, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $sub->execute([
                $customerId,
                (int) $plan['id'],
                $cycle,
                $periodKind,
                'pendente',
                (int) $plan['price_cents'],
                $postsLimit,
                $periodDays,
                $now,
                $now,
            ]);
            $subscriptionId = (int) $this->pdo->lastInsertId();
            $publicId = bin2hex(random_bytes(16));
            $ck = $this->pdo->prepare(
                'INSERT INTO checkouts (public_id, customer_id, subscription_id, plan_id, cycle, coupon_code, amount_cents, status, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $ck->execute([
                $publicId,
                $customerId,
                $subscriptionId,
                (int) $plan['id'],
                $cycle,
                $quoted['applied'] && $amount < $listCents ? strtoupper(trim((string) $coupon)) : null,
                $amount,
                'aberto',
                $now,
            ]);
            $checkoutId = (int) $this->pdo->lastInsertId();
            if ($quoted['applied'] && $quoted['coupon_id'] !== null) {
                if (!(new CouponRepository($this->pdo))->consume($quoted['coupon_id'])) {
                    throw new CouponRejected('Este cupom esgotou.');
                }
            }
            if ($amount === 0) {
                $this->markPaid($checkoutId, 'cupom', null, null, null, null);
            }
            if ($own) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $this->findByPublicId($publicId) ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByPublicId(string $publicId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.*, p.name AS plan_name, p.price_cents AS plan_price, cu.name AS customer_name, cu.email AS customer_email
             FROM checkouts c
             JOIN plans p ON p.id = c.plan_id
             JOIN customers cu ON cu.id = c.customer_id
             WHERE c.public_id = ? LIMIT 1'
        );
        $stmt->execute([$publicId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>
     */
    public function startPix(string $publicId): array
    {
        $checkout = $this->requireOpen($publicId);
        $existing = $this->pdo->prepare(
            "SELECT * FROM payments WHERE checkout_id = ? AND method = 'pix' AND status = 'pendente' ORDER BY id DESC LIMIT 1"
        );
        $existing->execute([(int) $checkout['id']]);
        $row = $existing->fetch();
        if ($row !== false) {
            return $row;
        }
        $pix = $this->gateway->createPix(
            (string) $checkout['customer_name'],
            (string) $this->documentOf((int) $checkout['customer_id']),
            (int) $checkout['amount_cents'],
            $publicId,
        );
        $stmt = $this->pdo->prepare(
            'INSERT INTO payments (checkout_id, subscription_id, method, status, amount_cents, gateway, external_id, pix_payload, pix_expires_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            (int) $checkout['id'],
            (int) $checkout['subscription_id'],
            'pix',
            'pendente',
            (int) $checkout['amount_cents'],
            $this->gateway->name(),
            $pix['external_id'],
            $pix['payload'],
            $pix['expires_at'],
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $again = $this->pdo->prepare('SELECT * FROM payments WHERE id = ?');
        $again->execute([$id]);

        return $again->fetch() ?: [];
    }

    public function chargeCard(string $publicId, string $token, ?string $brand, ?string $last4): void
    {
        $checkout = $this->requireOpen($publicId);
        try {
            $result = $this->gateway->chargeCard($token, (int) $checkout['amount_cents'], $publicId);
        } catch (PaymentRefused $e) {
            $this->recordRefusal($checkout, $brand, $last4);
            throw $e;
        }
        if (($result['status'] ?? '') !== 'pago') {
            $this->recordRefusal($checkout, $brand, $last4);
            throw new PaymentRefused('O banco recusou este cartão.');
        }
        $this->markPaid((int) $checkout['id'], 'cartao', $result['external_id'], $brand, $last4, null);
        $this->rememberRenewal((int) $checkout['subscription_id'], 'cartao', $token, $brand, $last4);
    }

    public function confirmExternal(string $gateway, string $externalId, string $rawPayload): bool
    {
        $insert = $this->pdo->prepare(
            'INSERT INTO gateway_webhooks (gateway, external_id, payload, created_at) VALUES (?, ?, ?, NOW())'
        );
        try {
            $insert->execute([$gateway, $externalId, $rawPayload]);
        } catch (\PDOException) {
            return false;
        }
        $pay = $this->pdo->prepare('SELECT * FROM payments WHERE gateway = ? AND external_id = ? LIMIT 1');
        $pay->execute([$gateway, $externalId]);
        $payment = $pay->fetch();
        if ($payment === false || $payment['checkout_id'] === null) {
            return true;
        }
        if ($payment['status'] === 'pago') {
            return true;
        }
        $this->pdo->prepare("UPDATE payments SET status = 'pago' WHERE id = ?")->execute([(int) $payment['id']]);
        $this->markPaid((int) $payment['checkout_id'], (string) $payment['method'], $externalId, $payment['brand'], $payment['last4'], (int) $payment['id']);
        $this->rememberRenewal(
            (int) $payment['subscription_id'],
            (string) $payment['method'],
            null,
            $payment['brand'] !== null ? (string) $payment['brand'] : null,
            $payment['last4'] !== null ? (string) $payment['last4'] : null,
        );

        return true;
    }

    public function renewDue(): int
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            "SELECT s.*, p.posts_limit AS plan_posts
             FROM subscriptions s
             INNER JOIN plans p ON p.id = s.plan_id
             WHERE s.status = 'ativa' AND s.current_period_end IS NOT NULL AND s.current_period_end <= ?"
        );
        $stmt->execute([$now]);
        $renewed = 0;
        foreach ($stmt->fetchAll() as $subscription) {
            if ($this->renewOne($subscription)) {
                $renewed++;
            }
        }

        return $renewed;
    }

    public function activate(string $code, int $userId): bool
    {
        $code = strtoupper(trim($code));
        $stmt = $this->pdo->prepare(
            "SELECT c.* FROM checkouts c WHERE c.activation_code = ? AND c.status = 'pago' LIMIT 1"
        );
        $stmt->execute([$code]);
        $checkout = $stmt->fetch();
        if ($checkout === false) {
            return false;
        }
        $this->pdo->prepare(
            "UPDATE customers SET user_id = ?, status = 'ativo', updated_at = NOW() WHERE id = ? AND status <> 'excluido'"
        )->execute([$userId, (int) $checkout['customer_id']]);

        return true;
    }

    /**
     * @param array<string, mixed> $checkout
     */
    private function recordRefusal(array $checkout, ?string $brand, ?string $last4): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO payments (checkout_id, subscription_id, method, status, amount_cents, gateway, brand, last4, created_at)
             VALUES (?, ?, 'cartao', 'recusado', ?, ?, ?, ?, NOW())"
        );
        $stmt->execute([
            (int) $checkout['id'],
            (int) $checkout['subscription_id'],
            (int) $checkout['amount_cents'],
            $this->gateway->name(),
            $brand,
            $last4,
        ]);
    }

    private function markPaid(int $checkoutId, string $method, ?string $externalId, ?string $brand, ?string $last4, ?int $paymentId): void
    {
        $checkout = $this->pdo->prepare('SELECT * FROM checkouts WHERE id = ?');
        $checkout->execute([$checkoutId]);
        $row = $checkout->fetch();
        if ($row === false || $row['status'] === 'pago') {
            return;
        }
        $code = $this->uniqueCode();
        $sub = $this->pdo->prepare('SELECT period_kind, period_days FROM subscriptions WHERE id = ?');
        $sub->execute([(int) $row['subscription_id']]);
        $subscription = $sub->fetch() ?: ['period_kind' => 'cheio', 'period_days' => 0];
        if ((string) $subscription['period_kind'] === 'teste') {
            $end = (new \DateTimeImmutable('now'))->modify('+' . max(1, (int) $subscription['period_days']) . ' days')->format('Y-m-d H:i:s');
        } else {
            $months = $row['cycle'] === 'anual' ? 12 : 1;
            $end = (new \DateTimeImmutable('now'))->modify('+' . $months . ' months')->format('Y-m-d H:i:s');
        }
        $this->pdo->prepare(
            "UPDATE checkouts SET status = 'pago', activation_code = ?, paid_at = NOW() WHERE id = ? AND status = 'aberto'"
        )->execute([$code, $checkoutId]);
        $this->pdo->prepare(
            "UPDATE subscriptions SET status = 'ativa', current_period_end = ?, period_started_at = NOW(), updated_at = NOW() WHERE id = ?"
        )->execute([$end, (int) $row['subscription_id']]);
        $this->pdo->prepare(
            "UPDATE customers SET status = 'aguardando_ativacao', updated_at = NOW() WHERE id = ? AND status = 'aguardando_ativacao'"
        )->execute([(int) $row['customer_id']]);
        if ($paymentId === null && $method === 'cupom') {
            $this->pdo->prepare(
                "INSERT INTO payments (checkout_id, subscription_id, method, status, amount_cents, gateway, created_at)
                 VALUES (?, ?, 'cupom', 'pago', 0, 'cupom', NOW())"
            )->execute([$checkoutId, (int) $row['subscription_id']]);
            $this->rememberRenewal((int) $row['subscription_id'], 'cupom', null, null, null);
        }
        if ($paymentId === null && $method === 'cartao') {
            $this->pdo->prepare(
                "INSERT INTO payments (checkout_id, subscription_id, method, status, amount_cents, gateway, external_id, brand, last4, created_at)
                 VALUES (?, ?, 'cartao', 'pago', ?, ?, ?, ?, ?, NOW())"
            )->execute([
                $checkoutId,
                (int) $row['subscription_id'],
                (int) $row['amount_cents'],
                $this->gateway->name(),
                $externalId,
                $brand,
                $last4,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $subscription
     */
    private function renewOne(array $subscription): bool
    {
        $id = (int) $subscription['id'];
        $cycle = (string) $subscription['cycle'];
        $amount = $cycle === 'anual' ? (int) $subscription['price_cents'] * 10 : (int) $subscription['price_cents'];
        $reference = 'renew-' . $id . '-' . str_replace([' ', ':'], '', (string) $subscription['current_period_end']);
        try {
            $result = $this->chargeRenewal($subscription, $amount, $reference);
        } catch (PaymentRefused) {
            $this->pdo->prepare(
                "UPDATE subscriptions SET status = 'inadimplente', updated_at = NOW() WHERE id = ? AND status = 'ativa'"
            )->execute([$id]);
            $this->pdo->prepare(
                "UPDATE customers SET status = 'inadimplente', updated_at = NOW() WHERE id = ? AND status = 'ativo'"
            )->execute([(int) $subscription['customer_id']]);

            return false;
        }
        if (($result['status'] ?? '') !== 'pago') {
            return false;
        }
        $months = $cycle === 'anual' ? 12 : 1;
        $start = date('Y-m-d H:i:s');
        $end = (new \DateTimeImmutable($start))->modify('+' . $months . ' months')->format('Y-m-d H:i:s');
        $posts = (int) $subscription['plan_posts'];
        $updated = $this->pdo->prepare(
            "UPDATE subscriptions
             SET status = 'ativa', period_kind = 'cheio', posts_limit = ?, period_days = 0,
                 period_started_at = ?, current_period_end = ?, updated_at = NOW()
             WHERE id = ? AND status = 'ativa' AND current_period_end = ?"
        );
        $updated->execute([$posts, $start, $end, $id, (string) $subscription['current_period_end']]);
        if ($updated->rowCount() !== 1) {
            return false;
        }
        $this->pdo->prepare(
            'INSERT INTO payments (checkout_id, subscription_id, method, status, amount_cents, gateway, external_id, brand, last4, created_at)
             VALUES (NULL, ?, ?, \'pago\', ?, ?, ?, ?, ?, NOW())'
        )->execute([
            $id,
            (string) ($subscription['renew_method'] ?: 'pix'),
            $amount,
            $this->gateway->name(),
            (string) $result['external_id'],
            $subscription['renew_brand'],
            $subscription['renew_last4'],
        ]);
        $this->pdo->prepare(
            "UPDATE customers SET status = 'ativo', updated_at = NOW() WHERE id = ? AND status = 'inadimplente'"
        )->execute([(int) $subscription['customer_id']]);

        return true;
    }

    /**
     * @param array<string, mixed> $subscription
     * @return array{external_id:string,status:string}
     */
    private function chargeRenewal(array $subscription, int $amountCents, string $reference): array
    {
        $method = (string) ($subscription['renew_method'] ?? '');
        $token = (string) ($subscription['renew_token'] ?? '');
        if ($method === 'cartao' && $token !== '') {
            return $this->gateway->chargeCard($token, $amountCents, $reference);
        }
        if ($this->gateway->name() === 'sandbox') {
            return [
                'external_id' => 'sandbox_renew_' . $reference,
                'status' => 'pago',
            ];
        }

        throw new PaymentRefused('Não há um meio salvo para renovar.');
    }

    private function rememberRenewal(int $subscriptionId, string $method, ?string $token, ?string $brand, ?string $last4): void
    {
        if (!in_array($method, ['pix', 'cartao', 'cupom'], true)) {
            return;
        }
        $this->pdo->prepare(
            'UPDATE subscriptions
             SET renew_method = ?, renew_token = ?, renew_brand = ?, renew_last4 = ?, updated_at = NOW()
             WHERE id = ? AND renew_method IS NULL'
        )->execute([$method, $token, $brand, $last4, $subscriptionId]);
    }

    /**
     * @return array<string, mixed>
     */
    private function requireOpen(string $publicId): array
    {
        $checkout = $this->findByPublicId($publicId);
        if ($checkout === null || $checkout['status'] !== 'aberto') {
            throw new RuntimeException('Este checkout não está aberto.');
        }
        if ((int) $checkout['amount_cents'] === 0) {
            throw new RuntimeException('Este checkout já foi quitado pelo cupom.');
        }

        return $checkout;
    }

    /**
     * @return array{scope:string,limit:int,from:string,until:string,days:int,plan:string,next_cents:int,kind:string,blocked:string}|null
     */
    public function postWindow(int $userId): ?array
    {
        return (new PlanAccess($this->pdo))->window($userId);
    }

    public function alreadyPaid(string $document): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM checkouts c
             INNER JOIN customers cu ON cu.id = c.customer_id
             WHERE cu.document = ? AND c.status = \'pago\'
             LIMIT 1'
        );
        $stmt->execute([$document]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * @return array{amount:int,applied:bool,coupon_id:?int}
     */
    private function quoteCoupon(int $monthlyCents, string $cycle, ?string $code): array
    {
        $given = strtoupper(trim((string) $code));
        $row = $given === '' ? null : (new CouponRepository($this->pdo))->findByCode($given);
        $quoted = Coupon::evaluate($monthlyCents, $cycle, $given, $row, date('Y-m-d H:i:s'));

        return [
            'amount' => $quoted['amount'],
            'applied' => $quoted['applied'],
            'coupon_id' => $quoted['applied'] && $row !== null ? (int) $row['id'] : null,
        ];
    }

    private function upsertCustomer(string $name, string $email, string $phone, string $document, string $type, string $now): int
    {
        $find = $this->pdo->prepare('SELECT id, status FROM customers WHERE document = ? LIMIT 1');
        $find->execute([$document]);
        $row = $find->fetch();
        if ($row !== false && $row['status'] !== 'excluido') {
            $this->pdo->prepare(
                'UPDATE customers SET name = ?, email = ?, phone = ?, updated_at = ? WHERE id = ?'
            )->execute([$name, $email, $phone, $now, (int) $row['id']]);

            return (int) $row['id'];
        }
        $this->pdo->prepare(
            'INSERT INTO customers (name, email, phone, document, document_type, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$name, $email, $phone, $document, $type, 'aguardando_ativacao', $now, $now]);

        return (int) $this->pdo->lastInsertId();
    }

    private function documentOf(int $customerId): string
    {
        $stmt = $this->pdo->prepare('SELECT document FROM customers WHERE id = ?');
        $stmt->execute([$customerId]);

        return (string) ($stmt->fetchColumn() ?: '');
    }

    private function uniqueCode(): string
    {
        for ($n = 0; $n < 8; $n++) {
            $code = 'PD';
            $max = strlen(self::ALPHABET) - 1;
            for ($i = 0; $i < 6; $i++) {
                $code .= self::ALPHABET[random_int(0, $max)];
            }
            $stmt = $this->pdo->prepare('SELECT id FROM checkouts WHERE activation_code = ?');
            $stmt->execute([$code]);
            if ($stmt->fetch() === false) {
                return $code;
            }
        }

        throw new RuntimeException('Não foi possível gerar o código de ativação.');
    }

    /**
     * @return array{plan:string,cycle:string,status:string,until:?string}|null
     */
    public function summaryForUser(int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.name AS plan_name, s.cycle, s.status, s.current_period_end
             FROM customers c
             JOIN subscriptions s ON s.customer_id = c.id
             JOIN plans p ON p.id = s.plan_id
             WHERE c.user_id = ? AND c.status <> ?
             ORDER BY s.id DESC
             LIMIT 1'
        );
        $stmt->execute([$userId, 'excluido']);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        return [
            'plan' => (string) $row['plan_name'],
            'cycle' => (string) $row['cycle'],
            'status' => (string) $row['status'],
            'until' => $row['current_period_end'] !== null ? (string) $row['current_period_end'] : null,
        ];
    }

}
