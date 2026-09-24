<?php

declare(strict_types=1);

namespace PerfilEmDia\Billing;

use PDO;
use PerfilEmDia\Config;
use PerfilEmDia\Domain\TicketService;
use PerfilEmDia\Domain\UserRepository;
use RuntimeException;

/**
 * Junta o que a pessoa vê e faz na conta: plano, cobrança, cartão, cancelamento e chamado.
 */
final class AccountOrchestrator
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly CheckoutService $billing,
        private readonly CustomerAccess $access,
        private readonly TicketService $tickets,
        private readonly UserRepository $users,
    ) {
    }

    public function linkForUser(int $userId): string
    {
        return $this->access->urlForUser($userId);
    }

    public function openToken(string $token): ?int
    {
        return $this->access->consume($token);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function view(int $customerId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM customers WHERE id = ? AND status <> 'excluido' LIMIT 1");
        $stmt->execute([$customerId]);
        $customer = $stmt->fetch();
        if ($customer === false) {
            return null;
        }
        $subscription = $this->billing->accountSubscription($customerId);
        $due = $subscription !== null && $this->billing->accountIsDue($subscription);
        $amount = $subscription !== null ? $this->billing->accountAmount($subscription) : 0;
        $userId = $customer['user_id'] !== null ? (int) $customer['user_id'] : 0;

        return [
            'customer' => [
                'id' => (int) $customer['id'],
                'name' => (string) $customer['name'],
                'email' => (string) $customer['email'],
                'phone' => (string) $customer['phone'],
                'document' => $this->mask((string) $customer['document']),
                'status' => (string) $customer['status'],
                'user_id' => $userId,
            ],
            'subscription' => $subscription,
            'due' => $due,
            'amount_cents' => $amount,
            'status_label' => $this->statusLabel($subscription),
            'usage' => $this->usage($userId),
            'payments' => $this->payments($customerId),
            'pix' => $due ? $this->pendingPix($subscription) : null,
            'plans' => (new PlanRepository($this->pdo))->active(),
            'instagram' => $this->instagram($userId),
            'tickets' => $this->ticketRows($userId),
            'appmax' => AppMaxGateway::configured(),
            'mutable' => $subscription !== null && in_array((string) $subscription['status'], ['ativa', 'inadimplente'], true),
        ];
    }

    public function choosePlan(int $customerId, int $planId): void
    {
        $this->billing->schedulePlan($customerId, $planId);
    }

    public function chooseCycle(int $customerId, string $cycle): void
    {
        $this->billing->scheduleCycle($customerId, $cycle);
    }

    public function cancel(int $customerId): void
    {
        $this->billing->scheduleCancel($customerId);
    }

    public function undoCancel(int $customerId): void
    {
        $this->billing->undoCancel($customerId);
    }

    public function saveContact(int $customerId, string $email, string $phone): void
    {
        $this->billing->updateContact($customerId, $email, $phone);
    }

    public function payCard(int $customerId, string $token, ?string $brand, ?string $last4): void
    {
        $this->billing->settleCard($customerId, $token, $brand, $last4);
    }

    public function saveCard(int $customerId, string $token, ?string $brand, ?string $last4): void
    {
        $this->billing->storeReplacementCard($customerId, $token, $brand, $last4);
    }

    public function beginPix(int $customerId): void
    {
        $checkout = $this->billing->openDueCheckout($customerId);
        $this->billing->startPix((string) $checkout['public_id']);
    }

    public function refreshPix(int $customerId): void
    {
        $subscription = $this->billing->accountSubscription($customerId);
        if ($subscription === null) {
            return;
        }
        $stmt = $this->pdo->prepare(
            "SELECT public_id FROM checkouts WHERE subscription_id = ? AND status = 'aberto' ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([(int) $subscription['id']]);
        $publicId = $stmt->fetchColumn();
        if ($publicId === false) {
            return;
        }
        $this->billing->pullPix((string) $publicId);
    }

    public function openTicket(int $customerId, string $body): int
    {
        $stmt = $this->pdo->prepare('SELECT user_id FROM customers WHERE id = ?');
        $stmt->execute([$customerId]);
        $userId = (int) ($stmt->fetchColumn() ?: 0);
        if ($userId < 1) {
            throw new RuntimeException('Ative a conta no Telegram antes de abrir um chamado.');
        }
        if (trim($body) === '') {
            throw new RuntimeException('Escreva o que aconteceu.');
        }

        return $this->tickets->open($userId, $body);
    }

    /**
     * @param array<string, mixed>|null $subscription
     */
    private function statusLabel(?array $subscription): string
    {
        if ($subscription === null) {
            return 'Sem assinatura';
        }
        if ((string) $subscription['status'] === 'ativa' && !empty($subscription['cancel_at'])) {
            return 'Cancelamento marcado';
        }
        if ((int) ($subscription['comp_forever'] ?? 0) === 1) {
            return 'Isenta';
        }
        $compUntil = (string) ($subscription['comp_until'] ?? '');
        if ($compUntil !== '' && $compUntil >= date('Y-m-d H:i:s')) {
            return 'Isenta';
        }

        return match ((string) $subscription['status']) {
            'ativa' => (string) $subscription['period_kind'] === 'teste' ? 'Teste' : 'Ativa',
            'inadimplente' => 'Pagamento pendente',
            'pendente' => 'Aguardando pagamento',
            'cancelada' => 'Cancelada',
            default => (string) $subscription['status'],
        };
    }

    private function usage(int $userId): string
    {
        if ($userId < 1) {
            return 'A conta ainda não está ligada ao Telegram.';
        }
        $window = $this->billing->postWindow($userId);
        if ($window === null) {
            return 'Nenhum período de posts ligado a esta conversa.';
        }
        if (($window['blocked'] ?? '') !== '') {
            return 'A publicação está pausada.';
        }
        $used = $this->pdo->prepare(
            "SELECT COUNT(*) FROM posts WHERE user_id = ? AND status = 'PUBLISHED' AND published_at >= ? AND published_at < ?"
        );
        $used->execute([$userId, $window['from'], $window['until']]);

        return (int) $used->fetchColumn() . ' de ' . (int) $window['limit'] . ' posts neste período.';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function payments(int $customerId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT py.created_at, py.amount_cents, py.method, py.status, py.brand, py.last4
             FROM payments py
             INNER JOIN subscriptions s ON s.id = py.subscription_id
             WHERE s.customer_id = ?
             ORDER BY py.id DESC
             LIMIT 30'
        );
        $stmt->execute([$customerId]);

        return $stmt->fetchAll() ?: [];
    }

    /**
     * @param array<string, mixed>|null $subscription
     * @return array<string, mixed>|null
     */
    private function pendingPix(?array $subscription): ?array
    {
        if ($subscription === null) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            "SELECT py.pix_payload, py.pix_expires_at
             FROM payments py
             INNER JOIN checkouts c ON c.id = py.checkout_id
             WHERE c.subscription_id = ? AND c.status = 'aberto' AND py.method = 'pix' AND py.status = 'pendente'
             ORDER BY py.id DESC
             LIMIT 1"
        );
        $stmt->execute([(int) $subscription['id']]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array{username:string,status:string,url:string}
     */
    private function instagram(int $userId): array
    {
        if ($userId < 1) {
            return ['username' => '', 'status' => '', 'url' => ''];
        }
        $stmt = $this->pdo->prepare('SELECT username, status FROM instagram_accounts WHERE user_id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch() ?: ['username' => '', 'status' => ''];
        $url = '';
        try {
            $state = $this->users->createOauthState($userId);
            $url = rtrim(Config::get('APP_URL', ''), '/') . '/conectar.php?t=' . $state;
        } catch (\Throwable) {
            $url = '';
        }

        return [
            'username' => (string) ($row['username'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'url' => $url,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function ticketRows(int $userId): array
    {
        if ($userId < 1) {
            return [];
        }
        $rows = [];
        foreach ($this->tickets->forUser($userId) as $ticket) {
            $ticket['label'] = TicketService::label((string) $ticket['status']);
            $ticket['messages'] = $this->tickets->messages((int) $ticket['id']);
            $rows[] = $ticket;
        }

        return $rows;
    }

    private function mask(string $document): string
    {
        $len = strlen($document);
        if ($len <= 4) {
            return $document;
        }

        return substr($document, 0, 3) . str_repeat('*', $len - 5) . substr($document, -2);
    }
}
