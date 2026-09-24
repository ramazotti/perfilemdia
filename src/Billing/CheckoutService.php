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
    public function pullPix(string $publicId): void
    {
        if (!$this->gateway instanceof AppMaxGateway) {
            return;
        }
        $checkout = $this->findByPublicId($publicId);
        if ($checkout === null || (string) $checkout['status'] !== 'aberto') {
            return;
        }
        $pending = $this->pdo->prepare(
            "SELECT external_id FROM payments WHERE checkout_id = ? AND method = 'pix' AND status = 'pendente' AND external_id IS NOT NULL ORDER BY id DESC LIMIT 1"
        );
        $pending->execute([(int) $checkout['id']]);
        $externalId = (string) ($pending->fetchColumn() ?: '');
        if ($externalId === '' || !$this->gateway->orderIsPaid($externalId)) {
            return;
        }
        $this->confirmExternal('appmax', $externalId, 'consulta');
    }

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
            'INSERT INTO payments (checkout_id, subscription_id, method, status, amount_cents, gateway, external_id, pix_payload, pix_qrcode, pix_expires_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
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
            ($pix['qrcode'] ?? '') !== '' ? $pix['qrcode'] : null,
            $pix['expires_at'],
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $again = $this->pdo->prepare('SELECT * FROM payments WHERE id = ?');
        $again->execute([$id]);

        return $again->fetch() ?: [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function pendingPix(string $publicId): ?array
    {
        $checkout = $this->requireOpen($publicId);
        $existing = $this->pdo->prepare(
            "SELECT * FROM payments WHERE checkout_id = ? AND method = 'pix' AND status = 'pendente' ORDER BY id DESC LIMIT 1"
        );
        $existing->execute([(int) $checkout['id']]);
        $row = $existing->fetch();

        return $row === false ? null : $row;
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
        if (is_string($result['brand'] ?? null) && $result['brand'] !== '') {
            $brand = (string) $result['brand'];
        }
        if (is_string($result['last4'] ?? null) && $result['last4'] !== '') {
            $last4 = substr((string) $result['last4'], -4);
        }
        $this->markPaid((int) $checkout['id'], 'cartao', $result['external_id'], $brand, $last4, null);
        $savedToken = $this->gateway instanceof AppMaxGateway ? null : $token;
        $this->rememberRenewal((int) $checkout['subscription_id'], 'cartao', $savedToken, $brand, $last4);
        $this->armAppMax($checkout);
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
            $this->armAppMaxFromPayment($payment);

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
        $this->armAppMaxFromPayment($payment);

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
        $this->pdo->prepare(
            "UPDATE customers SET status = 'ativo', updated_at = NOW() WHERE id = ? AND status = 'inadimplente'"
        )->execute([(int) $row['customer_id']]);
        $this->pdo->prepare('UPDATE subscriptions SET cancel_at = NULL, updated_at = NOW() WHERE id = ?')->execute([(int) $row['subscription_id']]);
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
        if ($this->cancelIsDue($subscription)) {
            $this->closeSubscription($id, (int) $subscription['customer_id']);

            return false;
        }
        if ((int) ($subscription['comp_forever'] ?? 0) === 1) {
            $next = (new \DateTimeImmutable('now'))->modify('+10 years')->format('Y-m-d H:i:s');
            $this->pdo->prepare('UPDATE subscriptions SET current_period_end = ?, updated_at = NOW() WHERE id = ?')
                ->execute([$next, $id]);

            return true;
        }
        $compUntil = (string) ($subscription['comp_until'] ?? '');
        $periodEnd = (string) $subscription['current_period_end'];
        if ($compUntil !== '' && $compUntil > $periodEnd) {
            $this->pdo->prepare('UPDATE subscriptions SET current_period_end = ?, updated_at = NOW() WHERE id = ?')
                ->execute([$compUntil, $id]);

            return true;
        }
        $periodEnd = (string) $subscription['current_period_end'];
        $subscription = $this->applySchedule($subscription);
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
        if (($result['status'] ?? '') === 'sincronizado') {
            return $this->syncRenewedPeriod($subscription, (string) ($result['period_end'] ?? ''));
        }
        if (($result['status'] ?? '') !== 'pago') {
            return false;
        }
        if (isset($result['amount_cents'])) {
            $amount = (int) $result['amount_cents'];
        }
        $months = $cycle === 'anual' ? 12 : 1;
        $start = date('Y-m-d H:i:s');
        $end = (new \DateTimeImmutable($start))->modify('+' . $months . ' months')->format('Y-m-d H:i:s');
        $posts = (int) $subscription['plan_posts'];
        $updated = $this->pdo->prepare(
            "UPDATE subscriptions
             SET status = 'ativa', period_kind = 'cheio', posts_limit = ?, period_days = 0,
                 period_started_at = ?, current_period_end = ?, cancel_at = NULL, updated_at = NOW()
             WHERE id = ? AND status = 'ativa' AND current_period_end = ?"
        );
        $updated->execute([$posts, $start, $end, $id, $periodEnd]);
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
        $this->rememberRenewedCard($id, $result);

        return true;
    }

    /**
     * @param array<string, mixed> $subscription
     * @return array{external_id:string,status:string}
     */
    private function chargeRenewal(array $subscription, int $amountCents, string $reference): array
    {
        $gatewaySub = trim((string) ($subscription['gateway_subscription_id'] ?? ''));
        $savedToken = (string) ($subscription['renew_token'] ?? '');
        if ($gatewaySub !== '' && $this->gateway instanceof AppMaxGateway) {
            if (str_contains($gatewaySub, '|upsell|')) {
                return $this->gateway->renew($gatewaySub, (string) ($subscription['period_kind'] ?? 'cheio'), $amountCents);
            }
            if ($savedToken !== '') {
                $customerId = (int) explode('|', $gatewaySub)[0];

                return $this->gateway->renewWithToken(
                    $customerId,
                    $this->documentOf((int) $subscription['customer_id']),
                    $savedToken,
                    $amountCents,
                    $reference,
                );
            }
        }
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

    /**
     * @param array<string, mixed> $subscription
     */
    private function syncRenewedPeriod(array $subscription, string $periodEnd): bool
    {
        if ($periodEnd === '') {
            return false;
        }
        $updated = $this->pdo->prepare(
            "UPDATE subscriptions
             SET status = 'ativa', period_kind = 'cheio', posts_limit = ?, period_days = 0,
                 current_period_end = ?, updated_at = NOW()
             WHERE id = ? AND status = 'ativa' AND current_period_end = ?"
        );
        $updated->execute([
            (int) $subscription['plan_posts'],
            $periodEnd,
            (int) $subscription['id'],
            (string) $subscription['current_period_end'],
        ]);

        return $updated->rowCount() === 1;
    }

    /**
     * @param array<string, mixed> $checkout
     */
    private function armAppMax(array $checkout): void
    {
        if (!$this->gateway instanceof AppMaxGateway) {
            return;
        }
        try {
            $id = $this->gateway->arm((string) $checkout['public_id']);
            if ($id === null || $id === '') {
                return;
            }
            $this->pdo->prepare(
                'UPDATE subscriptions SET gateway_subscription_id = ?, updated_at = NOW()
                 WHERE id = ? AND gateway_subscription_id IS NULL'
            )->execute([$id, (int) $checkout['subscription_id']]);
        } catch (\Throwable $e) {
            \PerfilEmDia\Logger::get()->warning('AppMax não armou a renovação.', [
                'checkout' => (string) ($checkout['public_id'] ?? ''),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $payment
     */
    private function armAppMaxFromPayment(array $payment): void
    {
        if (!$this->gateway instanceof AppMaxGateway || ($payment['method'] ?? '') !== 'cartao' || $payment['checkout_id'] === null) {
            return;
        }
        $stmt = $this->pdo->prepare('SELECT public_id, subscription_id FROM checkouts WHERE id = ?');
        $stmt->execute([(int) $payment['checkout_id']]);
        $checkout = $stmt->fetch();
        if ($checkout === false) {
            return;
        }
        $this->armAppMax($checkout);
    }

    public function recordGatewayRenewal(string $gatewaySubscriptionId, string $externalId, int $amountCents): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.*, p.posts_limit AS plan_posts
             FROM subscriptions s
             INNER JOIN plans p ON p.id = s.plan_id
             WHERE s.gateway_subscription_id = ? LIMIT 1'
        );
        $stmt->execute([$gatewaySubscriptionId]);
        $subscription = $stmt->fetch();
        if ($subscription === false || !in_array((string) $subscription['status'], ['ativa', 'inadimplente'], true)) {
            return false;
        }
        if ($amountCents < 1) {
            $amountCents = (int) $subscription['price_cents'] * ((($subscription['cycle'] ?? '') === 'anual') ? 10 : 1);
        }
        $months = ($subscription['cycle'] ?? '') === 'anual' ? 12 : 1;
        $start = date('Y-m-d H:i:s');
        $end = (new \DateTimeImmutable($start))->modify('+' . $months . ' months')->format('Y-m-d H:i:s');
        try {
            $this->pdo->prepare(
                'INSERT INTO payments (checkout_id, subscription_id, method, status, amount_cents, gateway, external_id, brand, last4, created_at)
                 VALUES (NULL, ?, ?, \'pago\', ?, \'appmax\', ?, ?, ?, NOW())'
            )->execute([
                (int) $subscription['id'],
                (string) ($subscription['renew_method'] ?: 'cartao'),
                $amountCents,
                $externalId,
                $subscription['renew_brand'],
                $subscription['renew_last4'],
            ]);
        } catch (\PDOException) {
            return true;
        }
        $this->pdo->prepare(
            "UPDATE subscriptions
             SET status = 'ativa', period_kind = 'cheio', posts_limit = ?, period_days = 0,
                 period_started_at = ?, current_period_end = ?, updated_at = NOW()
             WHERE id = ?"
        )->execute([
            (int) $subscription['plan_posts'],
            $start,
            $end,
            (int) $subscription['id'],
        ]);
        $this->pdo->prepare(
            "UPDATE customers SET status = 'ativo', updated_at = NOW() WHERE id = ? AND status = 'inadimplente'"
        )->execute([(int) $subscription['customer_id']]);

        return true;
    }

    public function markGatewayDelinquent(string $gatewaySubscriptionId): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, customer_id, status FROM subscriptions WHERE gateway_subscription_id = ? LIMIT 1'
        );
        $stmt->execute([$gatewaySubscriptionId]);
        $subscription = $stmt->fetch();
        if ($subscription === false || (string) $subscription['status'] !== 'ativa') {
            return;
        }
        $this->pdo->prepare(
            "UPDATE subscriptions SET status = 'inadimplente', updated_at = NOW() WHERE id = ? AND status = 'ativa'"
        )->execute([(int) $subscription['id']]);
        $this->pdo->prepare(
            "UPDATE customers SET status = 'inadimplente', updated_at = NOW() WHERE id = ? AND status = 'ativo'"
        )->execute([(int) $subscription['customer_id']]);
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

    /**
     * @return array<string, mixed>|null
     */
    public function accountSubscription(int $customerId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.*, p.name AS plan_name, p.posts_limit AS plan_posts, p.price_cents AS plan_price, p.slug AS plan_slug
             FROM subscriptions s
             INNER JOIN plans p ON p.id = s.plan_id
             WHERE s.customer_id = ?
             ORDER BY s.id DESC
             LIMIT 1'
        );
        $stmt->execute([$customerId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function schedulePlan(int $customerId, int $planId): void
    {
        $plan = (new PlanRepository($this->pdo))->find($planId);
        if ($plan === null || (int) $plan['active'] !== 1) {
            throw new RuntimeException('Plano indisponível.');
        }
        $sub = $this->requireMutable($customerId);
        $id = (int) $sub['id'];
        if ((int) $plan['id'] === (int) $sub['plan_id'] && empty($sub['next_plan_id'])) {
            return;
        }
        if ($this->isDue($sub)) {
            $this->pdo->prepare(
                'UPDATE subscriptions
                 SET plan_id = ?, price_cents = ?, posts_limit = ?, next_plan_id = NULL, updated_at = NOW()
                 WHERE id = ?'
            )->execute([(int) $plan['id'], (int) $plan['price_cents'], (int) $plan['posts_limit'], $id]);
            $this->expireOpenCheckouts($id);

            return;
        }
        if ((int) $plan['id'] === (int) $sub['plan_id']) {
            $this->pdo->prepare('UPDATE subscriptions SET next_plan_id = NULL, updated_at = NOW() WHERE id = ?')->execute([$id]);

            return;
        }
        if ((int) $plan['posts_limit'] > (int) $sub['posts_limit']) {
            $this->pdo->prepare(
                'UPDATE subscriptions SET posts_limit = ?, next_plan_id = ?, updated_at = NOW() WHERE id = ?'
            )->execute([(int) $plan['posts_limit'], (int) $plan['id'], $id]);

            return;
        }
        $this->pdo->prepare('UPDATE subscriptions SET next_plan_id = ?, updated_at = NOW() WHERE id = ?')->execute([(int) $plan['id'], $id]);
    }

    public function scheduleCycle(int $customerId, string $cycle): void
    {
        if (!in_array($cycle, ['mensal', 'anual'], true)) {
            throw new RuntimeException('Ciclo inválido.');
        }
        $sub = $this->requireMutable($customerId);
        $id = (int) $sub['id'];
        if ($cycle === (string) $sub['cycle']) {
            $this->pdo->prepare('UPDATE subscriptions SET next_cycle = NULL, updated_at = NOW() WHERE id = ?')->execute([$id]);

            return;
        }
        if ($this->isDue($sub)) {
            $this->pdo->prepare('UPDATE subscriptions SET cycle = ?, next_cycle = NULL, updated_at = NOW() WHERE id = ?')->execute([$cycle, $id]);
            $this->expireOpenCheckouts($id);

            return;
        }
        $this->pdo->prepare('UPDATE subscriptions SET next_cycle = ?, updated_at = NOW() WHERE id = ?')->execute([$cycle, $id]);
    }

    public function scheduleCancel(int $customerId): void
    {
        $sub = $this->requireMutable($customerId);
        if ((string) $sub['status'] === 'inadimplente' || $this->isDue($sub)) {
            $this->closeSubscription((int) $sub['id'], $customerId);

            return;
        }
        $this->pdo->prepare(
            "UPDATE subscriptions SET cancel_at = ?, updated_at = NOW() WHERE id = ? AND status = 'ativa'"
        )->execute([(string) $sub['current_period_end'], (int) $sub['id']]);
    }

    public function undoCancel(int $customerId): void
    {
        $sub = $this->requireMutable($customerId);
        if ((string) $sub['status'] !== 'ativa' || empty($sub['cancel_at'])) {
            return;
        }
        $this->pdo->prepare('UPDATE subscriptions SET cancel_at = NULL, updated_at = NOW() WHERE id = ?')->execute([(int) $sub['id']]);
    }

    public function updateContact(int $customerId, string $email, string $phone): void
    {
        $email = trim($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Informe um e-mail válido.');
        }
        $normalized = Phone::normalize($phone);
        if ($normalized === null) {
            throw new RuntimeException('Informe um celular com DDD.');
        }
        $this->pdo->prepare('UPDATE customers SET email = ?, phone = ?, updated_at = NOW() WHERE id = ? AND status <> ?')->execute([
            $email,
            $normalized,
            $customerId,
            'excluido',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function openDueCheckout(int $customerId): array
    {
        $sub = $this->requireMutable($customerId);
        if (!$this->isDue($sub)) {
            throw new RuntimeException('Não há cobrança em aberto.');
        }
        $sub = $this->applySchedule($sub);
        $amount = $this->chargeAmount($sub);
        $existing = $this->pdo->prepare(
            "SELECT public_id, amount_cents FROM checkouts WHERE subscription_id = ? AND status = 'aberto' ORDER BY id DESC LIMIT 1"
        );
        $existing->execute([(int) $sub['id']]);
        $open = $existing->fetch();
        if ($open !== false && (int) $open['amount_cents'] === $amount) {
            $found = $this->findByPublicId((string) $open['public_id']);
            if ($found !== null) {
                return $found;
            }
        }
        if ($open !== false) {
            $this->expireOpenCheckouts((int) $sub['id']);
        }
        $publicId = bin2hex(random_bytes(16));
        $this->pdo->prepare(
            'INSERT INTO checkouts (public_id, customer_id, subscription_id, plan_id, cycle, coupon_code, amount_cents, status, created_at)
             VALUES (?, ?, ?, ?, ?, NULL, ?, ?, NOW())'
        )->execute([
            $publicId,
            $customerId,
            (int) $sub['id'],
            (int) $sub['plan_id'],
            (string) $sub['cycle'],
            $amount,
            'aberto',
        ]);
        $found = $this->findByPublicId($publicId);
        if ($found === null) {
            throw new RuntimeException('Não foi possível abrir a cobrança.');
        }

        return $found;
    }

    public function settleCard(int $customerId, string $token, ?string $brand, ?string $last4): void
    {
        $checkout = $this->openDueCheckout($customerId);
        $this->chargeCard((string) $checkout['public_id'], $token, $brand, $last4);
        if ($this->gateway instanceof AppMaxGateway) {
            $armed = $this->gateway->arm((string) $checkout['public_id']);
            if ($armed !== null && $armed !== '') {
                $this->pdo->prepare(
                    'UPDATE subscriptions
                     SET gateway_subscription_id = ?, renew_token = NULL, renew_method = ?, renew_brand = ?, renew_last4 = ?, updated_at = NOW()
                     WHERE id = ?'
                )->execute([$armed, 'cartao', $brand, $last4, (int) $checkout['subscription_id']]);
            }

            return;
        }
        $this->pdo->prepare(
            'UPDATE subscriptions
             SET renew_method = ?, renew_token = ?, renew_brand = ?, renew_last4 = ?, updated_at = NOW()
             WHERE id = ?'
        )->execute(['cartao', $token, $brand, $last4, (int) $checkout['subscription_id']]);
    }

    public function storeReplacementCard(int $customerId, string $token, ?string $brand, ?string $last4): void
    {
        $sub = $this->requireMutable($customerId);
        if ($this->isDue($sub)) {
            throw new RuntimeException('Há uma cobrança em aberto. Pague com o cartão novo.');
        }
        if ($token === '') {
            throw new RuntimeException('Não foi possível ler o cartão.');
        }
        if ($this->gateway instanceof AppMaxGateway) {
            $customer = $this->customerRow($customerId);
            $appmaxId = $this->gateway->ensureCustomer(
                (string) $customer['name'],
                (string) $customer['email'],
                (string) $customer['phone'],
                (string) $customer['document'],
            );
            $this->pdo->prepare(
                'UPDATE subscriptions
                 SET gateway_subscription_id = ?, renew_method = ?, renew_token = ?, renew_brand = ?, renew_last4 = ?, updated_at = NOW()
                 WHERE id = ?'
            )->execute([$appmaxId . '|card|swap', 'cartao', $token, $brand, $last4, (int) $sub['id']]);

            return;
        }
        $this->pdo->prepare(
            'UPDATE subscriptions
             SET renew_method = ?, renew_token = ?, renew_brand = ?, renew_last4 = ?, updated_at = NOW()
             WHERE id = ?'
        )->execute(['cartao', $token, $brand, $last4, (int) $sub['id']]);
    }

    /**
     * @param array<string, mixed> $subscription
     */
    public function accountIsDue(array $subscription): bool
    {
        return $this->isDue($subscription);
    }

    public function accountAmount(array $subscription): int
    {
        $cycle = (string) ($subscription['next_cycle'] ?: $subscription['cycle']);
        $cents = (int) $subscription['price_cents'];
        $nextPlan = (int) ($subscription['next_plan_id'] ?? 0);
        if ($nextPlan > 0) {
            $plan = (new PlanRepository($this->pdo))->find($nextPlan);
            if ($plan !== null) {
                $cents = (int) $plan['price_cents'];
            }
        }

        return $cycle === 'anual' ? $cents * 10 : $cents;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function rememberRenewedCard(int $subscriptionId, array $result): void
    {
        $upsell = $result['upsell'] ?? null;
        $appmaxId = (int) ($result['appmax_customer_id'] ?? 0);
        if (!is_string($upsell) || $upsell === '' || $appmaxId < 1) {
            return;
        }
        $brand = is_string($result['brand'] ?? null) ? $result['brand'] : null;
        $last4 = is_string($result['last4'] ?? null) ? $result['last4'] : null;
        $this->pdo->prepare(
            'UPDATE subscriptions
             SET gateway_subscription_id = ?, renew_token = NULL, renew_method = ?, renew_brand = COALESCE(?, renew_brand), renew_last4 = COALESCE(?, renew_last4), updated_at = NOW()
             WHERE id = ?'
        )->execute([$appmaxId . '|upsell|' . $upsell, 'cartao', $brand, $last4, $subscriptionId]);
    }

    /**
     * @param array<string, mixed> $subscription
     */
    private function cancelIsDue(array $subscription): bool
    {
        $cancelAt = (string) ($subscription['cancel_at'] ?? '');

        return $cancelAt !== '' && $cancelAt <= date('Y-m-d H:i:s');
    }

    /**
     * @param array<string, mixed> $subscription
     */
    private function isDue(array $subscription): bool
    {
        if ((string) ($subscription['status'] ?? '') === 'inadimplente') {
            return true;
        }
        if ((string) ($subscription['status'] ?? '') !== 'ativa') {
            return false;
        }
        $end = (string) ($subscription['current_period_end'] ?? '');

        return $end !== '' && $end <= date('Y-m-d H:i:s');
    }

    /**
     * @return array<string, mixed>
     */
    private function requireMutable(int $customerId): array
    {
        $sub = $this->accountSubscription($customerId);
        if ($sub === null || !in_array((string) $sub['status'], ['ativa', 'inadimplente'], true)) {
            throw new RuntimeException('Não há assinatura para alterar.');
        }

        return $sub;
    }

    /**
     * @param array<string, mixed> $subscription
     * @return array<string, mixed>
     */
    private function applySchedule(array $subscription): array
    {
        $planId = (int) ($subscription['next_plan_id'] ?? 0);
        $cycle = (string) ($subscription['next_cycle'] ?? '');
        if ($planId < 1 && $cycle === '') {
            return $subscription;
        }
        $currentPlan = (int) $subscription['plan_id'];
        $price = (int) $subscription['price_cents'];
        $posts = (int) ($subscription['plan_posts'] ?? $subscription['posts_limit']);
        if ($planId > 0) {
            $plan = (new PlanRepository($this->pdo))->find($planId);
            if ($plan !== null && (int) $plan['active'] === 1) {
                $currentPlan = (int) $plan['id'];
                $price = (int) $plan['price_cents'];
                $posts = (int) $plan['posts_limit'];
                $subscription['plan_name'] = (string) $plan['name'];
            }
        }
        $currentCycle = (string) $subscription['cycle'];
        if ($cycle === 'mensal' || $cycle === 'anual') {
            $currentCycle = $cycle;
        }
        $this->pdo->prepare(
            'UPDATE subscriptions
             SET plan_id = ?, price_cents = ?, posts_limit = ?, cycle = ?, next_plan_id = NULL, next_cycle = NULL, updated_at = NOW()
             WHERE id = ?'
        )->execute([$currentPlan, $price, $posts, $currentCycle, (int) $subscription['id']]);
        $subscription['plan_id'] = $currentPlan;
        $subscription['price_cents'] = $price;
        $subscription['posts_limit'] = $posts;
        $subscription['plan_posts'] = $posts;
        $subscription['cycle'] = $currentCycle;
        $subscription['next_plan_id'] = null;
        $subscription['next_cycle'] = null;

        return $subscription;
    }

    /**
     * @param array<string, mixed> $subscription
     */
    private function chargeAmount(array $subscription): int
    {
        $cents = (int) $subscription['price_cents'];

        return (string) $subscription['cycle'] === 'anual' ? $cents * 10 : $cents;
    }

    private function closeSubscription(int $subscriptionId, int $customerId): void
    {
        $this->pdo->prepare(
            "UPDATE subscriptions SET status = 'cancelada', cancel_at = NULL, updated_at = NOW() WHERE id = ? AND status IN ('ativa', 'inadimplente')"
        )->execute([$subscriptionId]);
        $this->pdo->prepare(
            "UPDATE customers SET status = 'cancelado', updated_at = NOW() WHERE id = ? AND status IN ('ativo', 'inadimplente')"
        )->execute([$customerId]);
        $this->expireOpenCheckouts($subscriptionId);
    }

    private function expireOpenCheckouts(int $subscriptionId): void
    {
        $this->pdo->prepare(
            "UPDATE checkouts SET status = 'expirado' WHERE subscription_id = ? AND status = 'aberto'"
        )->execute([$subscriptionId]);
    }

    /**
     * @return array<string, mixed>
     */
    private function customerRow(int $customerId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM customers WHERE id = ? LIMIT 1');
        $stmt->execute([$customerId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new RuntimeException('Cliente não encontrado.');
        }

        return $row;
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
            'SELECT p.name AS plan_name, s.cycle, s.status, s.current_period_end, s.cancel_at, s.renew_method, s.renew_last4
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
            'cancel_at' => $row['cancel_at'] !== null ? (string) $row['cancel_at'] : null,
            'renew_method' => $row['renew_method'] !== null ? (string) $row['renew_method'] : '',
            'renew_last4' => $row['renew_last4'] !== null ? (string) $row['renew_last4'] : '',
        ];
    }

}
