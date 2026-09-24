<?php

declare(strict_types=1);

namespace PerfilEmDia\Tests;

use PerfilEmDia\Billing\AccountOrchestrator;
use PerfilEmDia\Billing\CheckoutService;
use PerfilEmDia\Billing\CustomerAccess;
use PerfilEmDia\Billing\PaymentGateway;
use PerfilEmDia\Billing\PaymentRefused;
use PerfilEmDia\Billing\PlanRepository;
use PerfilEmDia\Db;
use PerfilEmDia\Domain\TicketService;
use PerfilEmDia\Domain\UserRepository;
use PerfilEmDia\Messages;
use PerfilEmDia\Security\Crypto;
use PHPUnit\Framework\TestCase;

final class AccountPortalTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = Db::pdo();
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testLinkExpiresAndANewerLinkReplacesIt(): void
    {
        $customerId = $this->customerId();
        $access = new CustomerAccess($this->pdo);
        $first = $this->token($access->urlForCustomer($customerId));
        $second = $this->token($access->urlForCustomer($customerId));
        $this->assertNull($access->consume($first));
        $this->assertSame($customerId, $access->consume($second));
        $this->pdo->prepare('UPDATE customer_access_tokens SET expires_at = ? WHERE customer_id = ?')->execute([
            '2000-01-01 00:00:00',
            $customerId,
        ]);
        $this->assertNull($access->consume($second));
    }

    public function testCancelSkipsTheChargeAndUndoRenews(): void
    {
        $orch = $this->orchestrator();
        $subId = $this->paidSubscription();
        $before = (int) $this->pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn();
        $customerId = $this->customerOf($subId);
        $orch->cancel($customerId);
        $cancelAt = $this->pdo->prepare('SELECT cancel_at, status FROM subscriptions WHERE id = ?');
        $cancelAt->execute([$subId]);
        $marked = $cancelAt->fetch();
        $this->assertSame('ativa', $marked['status']);
        $this->assertNotEmpty($marked['cancel_at']);

        $past = (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s');
        $this->pdo->prepare('UPDATE subscriptions SET current_period_end = ?, cancel_at = ? WHERE id = ?')->execute([$past, $past, $subId]);
        $this->assertSame(0, $this->service()->renewDue());
        $closed = $this->pdo->prepare('SELECT status FROM subscriptions WHERE id = ?');
        $closed->execute([$subId]);
        $this->assertSame('cancelada', $closed->fetchColumn());
        $this->assertSame($before, (int) $this->pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn());
    }

    public function testUndoCancelLetsTheRenewalCharge(): void
    {
        $orch = $this->orchestrator();
        $subId = $this->paidSubscription();
        $customerId = $this->customerOf($subId);
        $orch->cancel($customerId);
        $orch->undoCancel($customerId);
        $orch->saveCard($customerId, 'sandbox:1111', 'visa', '1111');
        $past = (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s');
        $this->pdo->prepare('UPDATE subscriptions SET current_period_end = ? WHERE id = ?')->execute([$past, $subId]);
        $this->assertSame(1, $this->service()->renewDue());
        $status = $this->pdo->prepare('SELECT status, period_kind FROM subscriptions WHERE id = ?');
        $status->execute([$subId]);
        $row = $status->fetch();
        $this->assertSame('ativa', $row['status']);
        $this->assertSame('cheio', $row['period_kind']);
    }

    public function testUpgradeAppliesOnTheNextChargeAndRaisesThePostLimitNow(): void
    {
        $orch = $this->orchestrator();
        $subId = $this->paidSubscription();
        $customerId = $this->customerOf($subId);
        $pro = (new PlanRepository($this->pdo))->findBySlug('profissional');
        $this->assertNotNull($pro);
        $orch->choosePlan($customerId, (int) $pro['id']);
        $now = $this->pdo->prepare('SELECT posts_limit, price_cents, next_plan_id FROM subscriptions WHERE id = ?');
        $now->execute([$subId]);
        $row = $now->fetch();
        $this->assertSame(40, (int) $row['posts_limit']);
        $this->assertSame(2900, (int) $row['price_cents']);
        $this->assertSame((int) $pro['id'], (int) $row['next_plan_id']);

        $orch->saveCard($customerId, 'sandbox:1111', 'visa', '1111');
        $past = (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s');
        $this->pdo->prepare('UPDATE subscriptions SET current_period_end = ? WHERE id = ?')->execute([$past, $subId]);
        $this->assertSame(1, $this->service()->renewDue());
        $after = $this->pdo->prepare('SELECT price_cents, posts_limit, next_plan_id, status FROM subscriptions WHERE id = ?');
        $after->execute([$subId]);
        $done = $after->fetch();
        $this->assertSame(4900, (int) $done['price_cents']);
        $this->assertSame(40, (int) $done['posts_limit']);
        $this->assertNull($done['next_plan_id']);
        $paid = $this->pdo->prepare("SELECT amount_cents FROM payments WHERE subscription_id = ? AND status = 'pago' ORDER BY id DESC LIMIT 1");
        $paid->execute([$subId]);
        $this->assertSame(4900, (int) $paid->fetchColumn());
    }

    public function testPayingTheOpenBalanceRestoresTheSubscription(): void
    {
        $orch = $this->orchestrator();
        $subId = $this->paidSubscription();
        $customerId = $this->customerOf($subId);
        $past = (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s');
        $this->pdo->prepare("UPDATE subscriptions SET status = 'inadimplente', current_period_end = ? WHERE id = ?")->execute([$past, $subId]);
        $this->pdo->prepare("UPDATE customers SET status = 'inadimplente' WHERE id = ?")->execute([$customerId]);
        $orch->payCard($customerId, 'sandbox:4242', 'visa', '4242');
        $sub = $this->pdo->prepare('SELECT status FROM subscriptions WHERE id = ?');
        $sub->execute([$subId]);
        $this->assertSame('ativa', $sub->fetchColumn());
        $customer = $this->pdo->prepare('SELECT status FROM customers WHERE id = ?');
        $customer->execute([$customerId]);
        $this->assertSame('ativo', $customer->fetchColumn());
    }

    public function testPausedMessageIncludesTheAccountPath(): void
    {
        $text = Messages::renewalRefused('Essencial', 'https://perfilemdia.com.br/minha-conta/acesso/abc');
        $this->assertStringContainsString('/minha-conta/acesso/abc', $text);
    }

    private function orchestrator(): AccountOrchestrator
    {
        $users = new UserRepository($this->pdo, new Crypto(sodium_crypto_secretbox_keygen()));

        return new AccountOrchestrator(
            $this->pdo,
            $this->service(),
            new CustomerAccess($this->pdo),
            new TicketService($this->pdo, $users),
            $users,
        );
    }

    private function service(): CheckoutService
    {
        return new CheckoutService($this->pdo, new PortalGateway());
    }

    private function customerId(): int
    {
        return $this->customerOf($this->paidSubscription());
    }

    private function paidSubscription(): int
    {
        $plan = (new PlanRepository($this->pdo))->findBySlug('essencial');
        $this->assertNotNull($plan);
        $suffix = bin2hex(random_bytes(3));
        $checkout = $this->service()->open($plan, 'mensal', [
            'name' => 'Lia Portal',
            'email' => 'lia.' . $suffix . '@example.com',
            'phone' => '44991035056',
            'document' => '529.982.247-25',
        ], null);
        $pix = $this->service()->startPix($checkout['public_id']);
        $this->assertTrue($this->service()->confirmExternal('portal', (string) $pix['external_id'], '{}'));

        return (int) $checkout['subscription_id'];
    }

    private function customerOf(int $subscriptionId): int
    {
        $stmt = $this->pdo->prepare('SELECT customer_id FROM subscriptions WHERE id = ?');
        $stmt->execute([$subscriptionId]);

        return (int) $stmt->fetchColumn();
    }

    private function token(string $url): string
    {
        $parts = explode('/', rtrim($url, '/'));

        return (string) end($parts);
    }
}

final class PortalGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'portal';
    }

    public function createPix(string $customerName, string $document, int $amountCents, string $reference): array
    {
        return [
            'external_id' => 'pix-' . $reference,
            'payload' => '000201' . $reference,
            'expires_at' => date('Y-m-d H:i:s', time() + 1800),
        ];
    }

    public function chargeCard(string $token, int $amountCents, string $reference): array
    {
        if (str_ends_with($token, '0002')) {
            throw new PaymentRefused('recusado');
        }

        return ['external_id' => 'card-' . $reference, 'status' => 'pago'];
    }
}
