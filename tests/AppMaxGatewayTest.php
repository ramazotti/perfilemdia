<?php

declare(strict_types=1);

namespace PerfilEmDia\Tests;

use PerfilEmDia\Billing\AppMaxGateway;
use PerfilEmDia\Billing\CheckoutService;
use PerfilEmDia\Billing\PaymentRefused;
use PerfilEmDia\Billing\PlanRepository;
use PerfilEmDia\Billing\Settings;
use PerfilEmDia\Db;
use PerfilEmDia\Support\HttpPoster;
use PHPUnit\Framework\TestCase;

final class AppMaxGatewayTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = Db::pdo();
        $this->pdo->beginTransaction();
        Settings::flush();
        $_SESSION['appmax_ip'] = '203.0.113.10';
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        Settings::flush();
        unset($_SESSION['appmax_ip']);
    }

    public function testPixUsesBrowserIpAndReturnsCopyPasteCode(): void
    {
        $http = new ScriptedPoster([
            ['status' => 200, 'body' => ['success' => true, 'data' => ['id' => 9]]],
            ['status' => 200, 'body' => ['success' => true, 'data' => ['id' => 44, 'status' => 'pendente']]],
            ['status' => 200, 'body' => ['success' => true, 'data' => [
                'pix_emv' => '000201PIX',
                'pix_expiration_date' => '2026-09-22 12:00:00',
            ]]],
        ]);
        $gateway = new AppMaxGateway($this->pdo, $http, 'v3-token');
        $pix = $gateway->createPix('Ana Souza', '52998224725', 299, $this->checkout());
        $this->assertSame('44', $pix['external_id']);
        $this->assertSame('000201PIX', $pix['payload']);
        $customer = $http->calls[0][2]['json'];
        $this->assertSame('203.0.113.10', $customer['ip']);
        $this->assertSame('Ana', $customer['firstname']);
        $this->assertSame('Souza', $customer['lastname']);
        $this->assertSame('v3-token', $customer['access-token']);
        $this->assertStringEndsWith('/customer', $http->calls[0][1]);
        $this->assertSame(2.99, $http->calls[1][2]['json']['products'][0]['price']);
        $this->assertSame('52998224725', $http->calls[2][2]['json']['payment']['pix']['document_number']);
    }

    public function testPixAcceptsActiveStatusAndKeepsTheQrImage(): void
    {
        $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
        $http = new ScriptedPoster([
            ['status' => 200, 'body' => ['success' => true, 'data' => ['id' => 9]]],
            ['status' => 200, 'body' => ['success' => true, 'data' => ['id' => 44, 'status' => 'pendente']]],
            ['status' => 200, 'body' => [
                'success' => 'ATIVA',
                'text' => 'Transacao efetuada com sucesso',
                'data' => [
                    'pix_emv' => '000201PIX',
                    'pix_qrcode' => $png,
                    'pix_expiration_date' => '2026-09-22 21:26:57',
                ],
            ]],
        ]);
        $gateway = new AppMaxGateway($this->pdo, $http, 'v3-token');
        $pix = $gateway->createPix('Ana Souza', '52998224725', 299, $this->checkout());
        $this->assertSame('000201PIX', $pix['payload']);
        $this->assertSame($png, $pix['qrcode']);
        $this->assertSame('2026-09-22 21:26:57', $pix['expires_at']);
    }

    public function testSettlementReadsPartnerTotalAsCents(): void
    {
        $http = new ScriptedPoster([
            ['status' => 200, 'body' => ['success' => true, 'data' => [
                'total' => 2.99,
                'partner_total' => 1.97,
                'status' => 'aprovado',
            ]]],
        ]);
        $gateway = new AppMaxGateway($this->pdo, $http, 'v3-token');

        $settlement = $gateway->settlement('127513372');

        $this->assertSame(['gross_cents' => 299, 'net_cents' => 197], $settlement);
        $this->assertStringEndsWith('/order/127513372', $http->calls[0][1]);
    }

    public function testCardChargeSendsTokenNotPan(): void
    {
        $http = new ScriptedPoster([
            ['status' => 200, 'body' => ['success' => true, 'data' => ['id' => 9]]],
            ['status' => 200, 'body' => ['success' => true, 'data' => ['id' => 45]]],
            ['status' => 200, 'body' => ['success' => true, 'data' => [
                'status' => 'aprovado',
                'upsell_hash' => 'up_1',
                'card' => ['brand' => 'visa', 'last4' => '0010'],
            ]]],
        ]);
        $gateway = new AppMaxGateway($this->pdo, $http, 'v3-token');
        $paid = $gateway->chargeCard('tok_once', 299, $this->checkout());
        $this->assertSame('pago', $paid['status']);
        $this->assertSame('45', $paid['external_id']);
        $this->assertSame('visa', $paid['brand']);
        $this->assertSame('0010', $paid['last4']);
        $this->assertSame('9|upsell|up_1', $gateway->arm('ignorado'));
        $card = $http->calls[2][2]['json']['payment']['CreditCard'];
        $this->assertSame('tok_once', $card['token']);
        $this->assertArrayNotHasKey('number', $card);
        $this->assertArrayNotHasKey('cvv', $card);
    }

    public function testDeclinedCardDoesNotMarkPaid(): void
    {
        $http = new ScriptedPoster([
            ['status' => 200, 'body' => ['success' => true, 'data' => ['id' => 9]]],
            ['status' => 200, 'body' => ['success' => true, 'data' => ['id' => 46]]],
            ['status' => 200, 'body' => ['success' => false, 'text' => 'recusado', 'data' => []]],
        ]);
        $gateway = new AppMaxGateway($this->pdo, $http, 'v3-token');
        $this->expectException(PaymentRefused::class);
        $gateway->chargeCard('tok_no', 299, $this->checkout());
    }

    public function testRenewalChargesTheSavedUpsell(): void
    {
        $http = new ScriptedPoster([
            ['status' => 200, 'body' => ['success' => true, 'data' => ['id' => 90]]],
            ['status' => 200, 'body' => ['success' => true, 'data' => ['status' => 'aprovado']]],
        ]);
        $gateway = new AppMaxGateway($this->pdo, $http, 'v3-token');
        $result = $gateway->renew('9|upsell|hash1', 'teste', 2900);
        $this->assertSame('pago', $result['status']);
        $this->assertSame('90', $result['external_id']);
        $this->assertSame(2900, $result['amount_cents']);
        $this->assertSame(29.0, $http->calls[0][2]['json']['products'][0]['price']);
        $this->assertSame('hash1', $http->calls[1][2]['json']['payment']['CreditCard']['upsell_hash']);
    }

    public function testStoredTokenRenewalChargesThatToken(): void
    {
        $http = new ScriptedPoster([
            ['status' => 200, 'body' => ['success' => true, 'data' => ['id' => 91]]],
            ['status' => 200, 'body' => ['success' => true, 'data' => ['status' => 'aprovado', 'upsell_hash' => 'up_new']]],
        ]);
        $gateway = new AppMaxGateway($this->pdo, $http, 'v3-token');
        $result = $gateway->renewWithToken(9, '52998224725', 'tok_saved', 2900, 'renovacao-9');
        $this->assertSame('pago', $result['status']);
        $this->assertSame('up_new', $result['upsell']);
        $this->assertSame(9, $result['appmax_customer_id']);
        $card = $http->calls[1][2]['json']['payment']['CreditCard'];
        $this->assertSame('tok_saved', $card['token']);
        $this->assertArrayNotHasKey('upsell_hash', $card);
    }

    private function checkout(): string
    {
        $plan = (new PlanRepository($this->pdo))->findBySlug('essencial');
        $this->assertNotNull($plan);
        $service = new CheckoutService($this->pdo, new AppMaxFixtureGateway());
        $checkout = $service->open($plan, 'mensal', [
            'name' => 'Ana Souza',
            'email' => 'ana-appmax@example.com',
            'phone' => '11987654321',
            'document' => '529.982.247-25',
        ], null);

        return (string) $checkout['public_id'];
    }
}

final class AppMaxFixtureGateway implements \PerfilEmDia\Billing\PaymentGateway
{
    public function name(): string
    {
        return 'fake';
    }

    public function createPix(string $customerName, string $document, int $amountCents, string $reference): array
    {
        return [
            'external_id' => 'ext-' . $reference,
            'payload' => 'pix',
            'expires_at' => date('Y-m-d H:i:s', time() + 1800),
        ];
    }

    public function chargeCard(string $token, int $amountCents, string $reference): array
    {
        return ['external_id' => 'card-' . $reference, 'status' => 'pago'];
    }
}

final class ScriptedPoster implements HttpPoster
{
    /** @var list<array{0:string,1:string,2:array<string, mixed>}> */
    public array $calls = [];

    /**
     * @param list<array{status:int, body:array<string, mixed>|string}> $responses
     */
    public function __construct(private array $responses)
    {
    }

    public function request(string $method, string $url, array $options = []): array
    {
        $this->calls[] = [$method, $url, $options];
        $next = array_shift($this->responses);
        if ($next === null) {
            throw new \RuntimeException('Sem resposta programada.');
        }

        return $next;
    }
}
