<?php

declare(strict_types=1);

namespace PerfilEmDia\Tests;

use PerfilEmDia\Billing\AccountOrchestrator;
use PerfilEmDia\Billing\CheckoutService;
use PerfilEmDia\Billing\CustomerAccess;
use PerfilEmDia\Billing\PlanRepository;
use PerfilEmDia\Channel\WebStudioChannel;
use PerfilEmDia\Db;
use PerfilEmDia\Domain\TicketService;
use PerfilEmDia\Domain\UserRepository;
use PerfilEmDia\Security\Crypto;
use PerfilEmDia\Site\PortalOrchestrator;
use PHPUnit\Framework\TestCase;

final class PortalOrchestratorTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = Db::pdo();
        $this->pdo->beginTransaction();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testWebChannelCopiesLocalFile(): void
    {
        $source = sys_get_temp_dir() . '/portal-web-' . bin2hex(random_bytes(4)) . '.txt';
        file_put_contents($source, 'ok');
        $dest = sys_get_temp_dir() . '/portal-web-' . bin2hex(random_bytes(4)) . '.txt';
        $channel = new WebStudioChannel();
        $channel->download(WebStudioChannel::FILE_PREFIX . $source, $dest);
        $this->assertSame('ok', file_get_contents($dest));
        unlink($source);
        unlink($dest);
    }

    public function testSaveProfileUpdatesInstagramRow(): void
    {
        $orch = $this->orchestrator();
        $customerId = $this->customerWithUser();
        $userId = $orch->userIdForCustomer($customerId);
        $accountId = $this->seedInstagram($userId);
        $this->users()->update($userId, ['active_instagram_account_id' => $accountId]);
        $orch->saveProfile($customerId, [
            'display_name' => 'Web Nome',
            'profession' => 'Consultoria',
            'city' => 'Maringá',
            'tone' => 'profissional',
            'contact_cta' => 'WhatsApp',
            'about' => 'Sobre web',
            'brand_style' => 'Verde',
        ]);
        $stmt = $this->pdo->prepare('SELECT display_name, profession FROM instagram_profiles WHERE instagram_account_id = ?');
        $stmt->execute([$accountId]);
        $row = $stmt->fetch();
        $this->assertSame('Web Nome', $row['display_name']);
        $this->assertSame('Consultoria', $row['profession']);
    }

    private function orchestrator(): PortalOrchestrator
    {
        $users = $this->users();

        return new PortalOrchestrator(
            $this->pdo,
            new AccountOrchestrator(
                $this->pdo,
                new CheckoutService($this->pdo, new PortalGateway()),
                new CustomerAccess($this->pdo),
                new TicketService($this->pdo, $users),
                $users,
            ),
        );
    }

    private function users(): UserRepository
    {
        return new UserRepository($this->pdo, new Crypto(sodium_crypto_secretbox_keygen()));
    }

    private function customerWithUser(): int
    {
        $users = $this->users();
        $userId = $users->create(930000 + random_int(1, 99999), 930000, 'webportal');
        $plan = (new PlanRepository($this->pdo))->findBySlug('essencial');
        $this->assertNotNull($plan);
        $suffix = bin2hex(random_bytes(3));
        $checkout = (new CheckoutService($this->pdo, new PortalGateway()))->open($plan, 'mensal', [
            'name' => 'Web Portal',
            'email' => 'web.' . $suffix . '@example.com',
            'phone' => '44991035056',
            'document' => '529.982.247-25',
        ], null);
        $pix = (new CheckoutService($this->pdo, new PortalGateway()))->startPix($checkout['public_id']);
        (new CheckoutService($this->pdo, new PortalGateway()))->confirmExternal('portal', (string) $pix['external_id'], '{}');
        $paid = (new CheckoutService($this->pdo, new PortalGateway()))->findByPublicId($checkout['public_id']);
        $this->assertNotNull($paid);
        (new CheckoutService($this->pdo, new PortalGateway()))->activate((string) $paid['activation_code'], $userId);
        $stmt = $this->pdo->prepare('SELECT id FROM customers WHERE user_id = ? LIMIT 1');
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn();
    }

    private function seedInstagram(int $userId): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO instagram_accounts (user_id, ig_user_id, username, status) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$userId, 'ig' . $userId, 'conta_web', 'active']);

        return (int) $this->pdo->lastInsertId();
    }
}
