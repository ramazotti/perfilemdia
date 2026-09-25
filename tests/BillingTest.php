<?php

declare(strict_types=1);

namespace PerfilEmDia\Tests;

use PerfilEmDia\Billing\BrazilianDocument;
use PerfilEmDia\Billing\CheckoutService;
use PerfilEmDia\Billing\Coupon;
use PerfilEmDia\Billing\CouponRejected;
use PerfilEmDia\Billing\PaymentGateway;
use PerfilEmDia\Billing\Phone;
use PerfilEmDia\Billing\PaymentRefused;
use PerfilEmDia\Billing\PlanRepository;
use PerfilEmDia\Billing\Settings;
use PerfilEmDia\Billing\Trial;
use PerfilEmDia\Db;
use PHPUnit\Framework\TestCase;

final class BillingTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = Db::pdo();
        $this->pdo->beginTransaction();
        Settings::flush();
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        Settings::flush();
    }

    public function testOfficialAlphanumericCnpj(): void
    {
        $this->assertTrue(BrazilianDocument::isValid('12.ABC.345/01DE-35'));
        $this->assertFalse(BrazilianDocument::isValid('12.ABC.345/01DE-36'));
        $this->assertSame('cnpj', BrazilianDocument::type('12.ABC.345/01DE-35'));
        $this->assertSame('12ABC34501DE35', BrazilianDocument::canonical('12.ABC.345/01DE-35'));
    }

    public function testCpfRejectsRepeatedDigits(): void
    {
        $this->assertFalse(BrazilianDocument::isValid('111.111.111-11'));
        $this->assertTrue(BrazilianDocument::isValid('529.982.247-25'));
    }

    public function testBetaCouponOnlyOnMonthly(): void
    {
        $this->assertSame(0, Coupon::amountCents(2900, 'mensal', 'beta', 'BETA', 100, true));
        $this->assertSame(29000, Coupon::amountCents(2900, 'anual', 'BETA', 'BETA', 100, true));
        $this->assertSame(2900, Coupon::amountCents(2900, 'mensal', 'OUTRO', 'BETA', 100, true));
    }

    public function testWebhookIsIdempotentAndStoresNoCardNumber(): void
    {
        $plan = (new PlanRepository($this->pdo))->findBySlug('essencial');
        $this->assertNotNull($plan);
        $service = new CheckoutService($this->pdo, new FakeGateway());
        $checkout = $service->open($plan, 'mensal', [
            'name' => 'Ana Teste',
            'email' => 'ana@example.com',
            'phone' => '11999999999',
            'document' => '529.982.247-25',
        ], null);
        $pix = $service->startPix($checkout['public_id']);
        $this->assertTrue($service->confirmExternal('fake', (string) $pix['external_id'], '{"ok":true}'));
        $this->assertFalse($service->confirmExternal('fake', (string) $pix['external_id'], '{"ok":true}'));
        $again = $service->findByPublicId($checkout['public_id']);
        $this->assertSame('pago', $again['status']);
        $this->assertMatchesRegularExpression('/^PD[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{6}$/', (string) $again['activation_code']);

        $columns = $this->pdo->query('SHOW COLUMNS FROM payments')->fetchAll();
        $names = array_column($columns, 'Field');
        $this->assertNotContains('card_number', $names);
        $this->assertNotContains('cvv', $names);
        $row = $this->pdo->query('SELECT pix_payload, last4 FROM payments ORDER BY id DESC LIMIT 1')->fetch();
        $this->assertStringNotContainsString('4000000000000002', (string) $row['pix_payload']);
    }

    public function testPendingPixAppearsOnlyAfterItIsStarted(): void
    {
        $plan = (new PlanRepository($this->pdo))->findBySlug('essencial');
        $this->assertNotNull($plan);
        $service = new CheckoutService($this->pdo, new FakeGateway());
        $checkout = $service->open($plan, 'mensal', [
            'name' => 'Lia Teste',
            'email' => 'lia-pix@example.com',
            'phone' => '11988887777',
            'document' => '529.982.247-25',
        ], null);
        $this->assertNull($service->pendingPix($checkout['public_id']));
        $started = $service->startPix($checkout['public_id']);
        $pending = $service->pendingPix($checkout['public_id']);
        $this->assertNotNull($pending);
        $this->assertSame($started['id'], $pending['id']);
    }

    public function testFullCouponSkipsGatewayAndDeclinedCardCanRetry(): void
    {
        $plan = (new PlanRepository($this->pdo))->findBySlug('profissional');
        $service = new CheckoutService($this->pdo, new FakeGateway());
        $free = $service->open($plan, 'mensal', [
            'name' => 'Beto Teste',
            'email' => 'beto@example.com',
            'phone' => '11988887777',
            'document' => '12.ABC.345/01DE-35',
        ], 'BETA');
        $this->assertSame('pago', $free['status']);
        $this->assertSame(0, (int) $free['amount_cents']);
        $customer = $this->pdo->query("SELECT status FROM customers WHERE document = '12ABC34501DE35'")->fetch();
        $this->assertSame('aguardando_ativacao', $customer['status']);

        $paid = $service->open($plan, 'anual', [
            'name' => 'Beto Teste',
            'email' => 'beto@example.com',
            'phone' => '11988887777',
            'document' => '12.ABC.345/01DE-35',
        ], null);
        $this->assertSame(49000, (int) $paid['amount_cents']);
        $this->assertSame('aberto', $paid['status']);
        try {
            $service->chargeCard($paid['public_id'], 'sandbox:0002', 'visa', '0002');
            $this->fail('Cartão de teste deveria ser recusado.');
        } catch (PaymentRefused) {
        }
        $still = $service->findByPublicId($paid['public_id']);
        $this->assertSame('aberto', $still['status']);
    }

    public function testTrialThenFullMonthAndProportionalPosts(): void
    {
        $this->assertSame(2, Trial::posts(16, 3));
        $this->assertSame(4, Trial::posts(40, 3));
        $this->assertSame(1, Trial::posts(4, 3));

        $plan = (new PlanRepository($this->pdo))->findBySlug('essencial');
        $this->assertNotNull($plan);
        $service = new CheckoutService($this->pdo, new FakeGateway());
        $customer = [
            'name' => 'Lia Teste',
            'email' => 'lia.trial@example.com',
            'phone' => '44991035056',
            'document' => '529.982.247-25',
        ];
        $first = $service->open($plan, 'mensal', $customer, null);
        $this->assertSame(99, (int) $first['amount_cents']);
        $pix = $service->startPix($first['public_id']);
        $this->assertTrue($service->confirmExternal('fake', (string) $pix['external_id'], '{"ok":true}'));
        $sub = $this->pdo->query('SELECT period_kind, posts_limit, period_days, current_period_end, price_cents FROM subscriptions ORDER BY id DESC LIMIT 1')->fetch();
        $this->assertSame('teste', $sub['period_kind']);
        $this->assertSame(2, (int) $sub['posts_limit']);
        $this->assertSame(3, (int) $sub['period_days']);
        $this->assertSame(2900, (int) $sub['price_cents']);
        $end = new \DateTimeImmutable((string) $sub['current_period_end']);
        $this->assertGreaterThan(new \DateTimeImmutable('+2 days'), $end);
        $this->assertLessThan(new \DateTimeImmutable('+4 days'), $end);

        $second = $service->open($plan, 'mensal', $customer, null);
        $this->assertSame(2900, (int) $second['amount_cents']);
        $full = $this->pdo->query('SELECT period_kind, posts_limit FROM subscriptions ORDER BY id DESC LIMIT 1')->fetch();
        $this->assertSame('cheio', $full['period_kind']);
        $this->assertSame(16, (int) $full['posts_limit']);

        $year = $service->open($plan, 'anual', [
            'name' => 'Ano Teste',
            'email' => 'ano.trial@example.com',
            'phone' => '44991035056',
            'document' => '12.ABC.345/01DE-35',
        ], null);
        $this->assertSame(29000, (int) $year['amount_cents']);
    }

    public function testSubscriptionRenewsAfterTrialAndStopsWhenRefused(): void
    {
        $plan = (new PlanRepository($this->pdo))->findBySlug('essencial');
        $this->assertNotNull($plan);
        $service = new CheckoutService($this->pdo, new FakeGateway());
        $customer = [
            'name' => 'Rui Teste',
            'email' => 'rui.renew@example.com',
            'phone' => '44991035056',
            'document' => '529.982.247-25',
        ];
        $first = $service->open($plan, 'mensal', $customer, null);
        $pix = $service->startPix($first['public_id']);
        $this->assertTrue($service->confirmExternal('fake', (string) $pix['external_id'], '{"ok":true}'));
        $paidCheckout = $service->findByPublicId($first['public_id']);
        $this->assertNotNull($paidCheckout);
        $subId = (int) $paidCheckout['subscription_id'];
        $this->pdo->prepare(
            "UPDATE subscriptions SET renew_method = 'cartao', renew_token = 'sandbox:1111', current_period_end = ? WHERE id = ?"
        )->execute([(new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s'), $subId]);

        $this->assertSame(1, $service->renewDue());
        $sub = $this->pdo->prepare('SELECT period_kind, posts_limit, status, current_period_end FROM subscriptions WHERE id = ?');
        $sub->execute([$subId]);
        $row = $sub->fetch();
        $this->assertSame('cheio', $row['period_kind']);
        $this->assertSame(16, (int) $row['posts_limit']);
        $this->assertSame('ativa', $row['status']);
        $this->assertGreaterThan(new \DateTimeImmutable('+20 days'), new \DateTimeImmutable((string) $row['current_period_end']));
        $paid = $this->pdo->prepare('SELECT amount_cents FROM payments WHERE subscription_id = ? AND external_id LIKE ? ORDER BY id DESC LIMIT 1');
        $paid->execute([$subId, 'card-renew-%']);
        $this->assertSame(2900, (int) $paid->fetchColumn());

        $this->assertSame(0, $service->renewDue());

        $this->pdo->prepare(
            "UPDATE subscriptions SET renew_token = 'sandbox:0002', current_period_end = ? WHERE id = ?"
        )->execute([(new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s'), $subId]);
        $this->assertSame(0, $service->renewDue());
        $refused = $this->pdo->prepare('SELECT status FROM subscriptions WHERE id = ?');
        $refused->execute([$subId]);
        $this->assertSame('inadimplente', $refused->fetchColumn());
    }

    public function testPhoneAcceptsMobileUsedOnWhatsappOrTelegram(): void
    {
        $this->assertSame('44991035056', Phone::normalize('(44) 99103-5056'));
        $this->assertSame('44991035056', Phone::normalize('+55 (44) 99103-5056'));
        $this->assertSame('(44) 99103-5056', Phone::format('44991035056'));
        $this->assertNull(Phone::normalize('(44) 3103-5056'));
        $this->assertNull(Phone::normalize('4499103505'));
    }

    public function testCouponQuantityExpiryAndMonthlyOnly(): void
    {
        $row = [
            'code' => 'BETA',
            'percent' => 100,
            'max_uses' => 2,
            'used_count' => 2,
            'valid_until' => null,
            'monthly_only' => 1,
            'active' => 1,
        ];
        try {
            Coupon::evaluate(2900, 'mensal', 'BETA', $row, '2026-09-22 10:00:00');
            $this->fail('Cupom esgotado deveria ser recusado.');
        } catch (CouponRejected $e) {
            $this->assertStringContainsString('esgotou', $e->getMessage());
        }

        $row['used_count'] = 0;
        $row['valid_until'] = '2026-09-01 23:59:59';
        try {
            Coupon::evaluate(2900, 'mensal', 'BETA', $row, '2026-09-22 10:00:00');
            $this->fail('Cupom vencido deveria ser recusado.');
        } catch (CouponRejected $e) {
            $this->assertStringContainsString('venceu', $e->getMessage());
        }

        $row['valid_until'] = null;
        $ok = Coupon::evaluate(2900, 'mensal', 'BETA', $row, '2026-09-22 10:00:00');
        $this->assertSame(0, $ok['amount']);
        $this->assertTrue($ok['applied']);

        $plan = (new PlanRepository($this->pdo))->findBySlug('essencial');
        $service = new CheckoutService($this->pdo, new FakeGateway());
        $this->expectException(CouponRejected::class);
        $this->expectExceptionMessage('mensalidade');
        $service->open($plan, 'anual', [
            'name' => 'Lia Teste',
            'email' => 'lia@example.com',
            'phone' => '44991035056',
            'document' => '529.982.247-25',
        ], 'BETA');
    }
}

final class FakeGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'fake';
    }

    public function createPix(string $customerName, string $document, int $amountCents, string $reference): array
    {
        return [
            'external_id' => 'ext-' . $reference,
            'payload' => 'pix-' . $reference,
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
