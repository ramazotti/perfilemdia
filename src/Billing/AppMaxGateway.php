<?php

declare(strict_types=1);

namespace PerfilEmDia\Billing;

use PerfilEmDia\Config;
use PerfilEmDia\Logger;
use PerfilEmDia\Support\GuzzleHttpPoster;
use PerfilEmDia\Support\HttpPoster;
use PDO;
use RuntimeException;

/**
 * Cobrança na AppMax pela API v3. O access-token fica no servidor.
 * O número do cartão só atravessa a tokenização e não é gravado.
 */
final class AppMaxGateway implements PaymentGateway
{
    private ?string $lastUpsell = null;

    private ?int $lastCustomerId = null;

    public function __construct(
        private readonly PDO $pdo,
        private readonly ?HttpPoster $http = null,
        private readonly ?string $accessTokenOverride = null,
    ) {
    }

    public function name(): string
    {
        return 'appmax';
    }

    public static function configured(): bool
    {
        if (strtolower(Config::get('PAYMENT_GATEWAY', '')) !== 'appmax') {
            return false;
        }

        return self::storedToken() !== '';
    }

    public static function scriptUrl(): string
    {
        return 'https://scripts.appmax.com.br/appmax.min.js';
    }

    public static function externalId(): string
    {
        return '';
    }

    public function createPix(string $customerName, string $document, int $amountCents, string $reference): array
    {
        $row = $this->checkout($reference);
        $customerId = $this->customerId($row, $customerName, $document);
        $orderId = $this->openOrder($customerId, (string) $row['plan_name'], $amountCents, $reference);
        $expires = (new \DateTimeImmutable('now'))->modify('+30 minutes')->format('Y-m-d H:i:s');
        $pix = $this->api('POST', '/payment/pix', [
            'cart' => ['order_id' => $orderId],
            'customer' => ['customer_id' => $customerId],
            'payment' => [
                'pix' => [
                    'document_number' => $this->digits($document),
                    'expiration_date' => $expires,
                ],
            ],
        ]);
        $body = $this->body($pix);
        $payload = $this->pixCode($body);
        if ($payload === '') {
            throw new RuntimeException('A AppMax não devolveu o Pix.');
        }
        $returned = $this->pick($body, ['data.pix_expiration_date', 'data.payment.pix_expiration_date', 'pix_expiration_date']);
        if (is_scalar($returned) && (string) $returned !== '') {
            $expires = (string) $returned;
        }

        return [
            'external_id' => (string) $orderId,
            'payload' => $payload,
            'expires_at' => $expires,
            'qrcode' => $this->pixPng($body),
        ];
    }

    public function tokenize(string $holder, string $number, int $month, int $year, string $cvv): string
    {
        if ($year > 0 && $year < 100) {
            $year += 2000;
        }
        $response = $this->api('POST', '/tokenize/card', [
            'card' => [
                'name' => $holder,
                'number' => $number,
                'cvv' => $cvv,
                'month' => $month,
                'year' => $year,
            ],
        ]);
        $token = $this->pick($this->body($response), ['data.token', 'token']);
        if (!is_scalar($token) || (string) $token === '') {
            throw new RuntimeException('A AppMax não devolveu o token do cartão.');
        }

        return (string) $token;
    }

    public function chargeCard(string $token, int $amountCents, string $reference): array
    {
        $row = $this->checkout($reference);
        $customerId = $this->customerId($row);
        $orderId = $this->openOrder($customerId, (string) $row['plan_name'], $amountCents, $reference);
        $paid = $this->api('POST', '/payment/credit-card', [
            'cart' => ['order_id' => $orderId],
            'customer' => ['customer_id' => $customerId],
            'payment' => [
                'CreditCard' => [
                    'token' => $token,
                    'document_number' => $this->digits((string) $row['document']),
                    'installments' => 1,
                    'soft_descriptor' => 'PERFILEMDIA',
                ],
            ],
        ], true);
        $body = $this->body($paid);
        $this->lastCustomerId = $customerId;
        $upsell = $this->pick($body, ['data.upsell_hash', 'upsell_hash', 'data.payment.upsell_hash']);
        $this->lastUpsell = is_scalar($upsell) && (string) $upsell !== '' ? (string) $upsell : null;
        $status = $this->statusOf($body);
        if ($status === '') {
            $status = $this->orderStatus($orderId);
        }
        if ($status !== '' && !$this->isPaidStatus($status)) {
            throw new PaymentRefused('O banco recusou este cartão.');
        }
        $brand = $this->pick($body, ['data.card.brand', 'data.payment.credit_card.brand', 'data.brand']);
        $last4 = $this->pick($body, ['data.card.last4', 'data.payment.credit_card.final', 'data.last4']);

        return [
            'external_id' => (string) $orderId,
            'status' => 'pago',
            'brand' => is_scalar($brand) ? (string) $brand : null,
            'last4' => is_scalar($last4) ? substr($this->digits((string) $last4), -4) : null,
            'upsell' => $this->lastUpsell,
        ];
    }

    /**
     * Guarda o cliente e o upsell da AppMax para a próxima cobrança no cartão.
     */
    public function arm(string $reference): ?string
    {
        unset($reference);
        if ($this->lastCustomerId === null || $this->lastUpsell === null || $this->lastUpsell === '') {
            return null;
        }

        return $this->lastCustomerId . '|upsell|' . $this->lastUpsell;
    }

    /**
     * @return array{external_id:string,status:string,amount_cents?:int}
     */
    public function renew(string $subscriptionId, string $periodKind, int $amountCents = 0): array
    {
        unset($periodKind);
        $parts = explode('|', $subscriptionId, 3);
        if (count($parts) !== 3 || !ctype_digit($parts[0]) || $parts[1] !== 'upsell' || $parts[2] === '') {
            throw new PaymentRefused('Não há um cartão salvo para renovar.');
        }
        $customerId = (int) $parts[0];
        if ($amountCents < 1) {
            $amountCents = $this->storedAmount($subscriptionId);
        }
        if ($amountCents < 1) {
            throw new PaymentRefused('Não há um valor para renovar.');
        }
        $orderId = $this->openOrder($customerId, 'Assinatura Perfil em Dia', $amountCents, 'renovacao-' . $customerId);
        $paid = $this->api('POST', '/payment/credit-card', [
            'cart' => ['order_id' => $orderId],
            'customer' => ['customer_id' => $customerId],
            'payment' => [
                'CreditCard' => [
                    'upsell_hash' => $parts[2],
                    'installments' => 1,
                    'soft_descriptor' => 'PERFILEMDIA',
                ],
            ],
        ], true);
        $status = $this->statusOf($this->body($paid));
        if ($status === '') {
            $status = $this->orderStatus($orderId);
        }
        if ($status !== '' && !$this->isPaidStatus($status)) {
            throw new PaymentRefused('O banco recusou a renovação.');
        }

        return [
            'external_id' => (string) $orderId,
            'status' => 'pago',
            'amount_cents' => $amountCents,
        ];
    }

    public function ensureCustomer(string $name, string $email, string $phone, string $document): int
    {
        return $this->customerId([
            'customer_name' => $name,
            'customer_email' => $email,
            'phone' => $phone,
            'document' => $document,
        ], $name, $document);
    }

    /**
     * @return array{external_id:string,status:string,amount_cents:int,upsell:?string,appmax_customer_id:int,brand:?string,last4:?string}
     */
    public function renewWithToken(int $customerId, string $document, string $token, int $amountCents, string $reference): array
    {
        if ($customerId < 1 || $token === '' || $amountCents < 1) {
            throw new PaymentRefused('Não há um cartão salvo para renovar.');
        }
        $orderId = $this->openOrder($customerId, 'Assinatura Perfil em Dia', $amountCents, $reference);
        $paid = $this->api('POST', '/payment/credit-card', [
            'cart' => ['order_id' => $orderId],
            'customer' => ['customer_id' => $customerId],
            'payment' => [
                'CreditCard' => [
                    'token' => $token,
                    'document_number' => $this->digits($document),
                    'installments' => 1,
                    'soft_descriptor' => 'PERFILEMDIA',
                ],
            ],
        ], true);
        $body = $this->body($paid);
        $this->lastCustomerId = $customerId;
        $upsell = $this->pick($body, ['data.upsell_hash', 'upsell_hash', 'data.payment.upsell_hash']);
        $this->lastUpsell = is_scalar($upsell) && (string) $upsell !== '' ? (string) $upsell : null;
        $status = $this->statusOf($body);
        if ($status === '') {
            $status = $this->orderStatus($orderId);
        }
        if ($status !== '' && !$this->isPaidStatus($status)) {
            throw new PaymentRefused('O banco recusou a renovação.');
        }
        $brand = $this->pick($body, ['data.card.brand', 'data.payment.credit_card.brand', 'data.brand']);
        $last4 = $this->pick($body, ['data.card.last4', 'data.payment.credit_card.final', 'data.last4']);

        return [
            'external_id' => (string) $orderId,
            'status' => 'pago',
            'amount_cents' => $amountCents,
            'upsell' => $this->lastUpsell,
            'appmax_customer_id' => $customerId,
            'brand' => is_scalar($brand) ? (string) $brand : null,
            'last4' => is_scalar($last4) ? substr($this->digits((string) $last4), -4) : null,
        ];
    }

    public function orderIsPaid(string $orderId): bool
    {
        if ($orderId === '' || !ctype_digit($orderId)) {
            return false;
        }
        $response = $this->call('GET', '/order/' . $orderId);
        $body = $this->body($response);
        if (($body['success'] ?? false) !== true) {
            return false;
        }

        return $this->isPaidStatus($this->statusOf($body));
    }

    public function orderIsRefused(string $orderId): bool
    {
        if ($orderId === '' || !ctype_digit($orderId)) {
            return false;
        }
        $response = $this->call('GET', '/order/' . $orderId);
        $body = $this->body($response);
        if (($body['success'] ?? false) !== true) {
            return false;
        }

        return $this->isRefusedStatus($this->statusOf($body));
    }

    private function openOrder(int $customerId, string $name, int $amountCents, string $reference): int
    {
        $order = $this->api('POST', '/order', [
            'customer_id' => $customerId,
            'products' => [[
                'sku' => 'ped-' . substr(preg_replace('/[^a-zA-Z0-9]/', '', $reference) ?? 'pedido', 0, 40),
                'name' => $name,
                'qty' => 1,
                'price' => round($amountCents / 100, 2),
                'digital_product' => 1,
            ]],
        ]);
        $orderId = $this->pick($this->body($order), ['data.id', 'data.order.id', 'id']);
        if (!is_scalar($orderId) || (int) $orderId < 1) {
            throw new RuntimeException('A AppMax não devolveu o pedido.');
        }

        return (int) $orderId;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function customerId(array $row, ?string $name = null, ?string $document = null): int
    {
        if ($this->lastCustomerId !== null && $name === null) {
            return $this->lastCustomerId;
        }
        $full = trim($name ?? (string) $row['customer_name']);
        $parts = preg_split('/\s+/', $full) ?: [];
        $first = (string) ($parts[0] ?? 'Cliente');
        $last = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : 'Cliente';
        $phone = $this->digits((string) $row['phone']);
        if (strlen($phone) > 11) {
            $phone = substr($phone, -11);
        }
        $created = $this->api('POST', '/customer', [
            'firstname' => $first,
            'lastname' => $last,
            'email' => (string) $row['customer_email'],
            'telephone' => $phone,
            'document_number' => $this->digits($document ?? (string) $row['document']),
            'ip' => $this->clientIp(),
        ]);
        $id = $this->pick($this->body($created), ['data.id', 'data.customer.id', 'id']);
        if (!is_scalar($id) || (int) $id < 1) {
            throw new RuntimeException('A AppMax não devolveu o cliente.');
        }
        $this->lastCustomerId = (int) $id;

        return $this->lastCustomerId;
    }

    private function storedAmount(string $subscriptionId): int
    {
        $stmt = $this->pdo->prepare('SELECT price_cents, cycle FROM subscriptions WHERE gateway_subscription_id = ? LIMIT 1');
        $stmt->execute([$subscriptionId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return 0;
        }
        $cents = (int) $row['price_cents'];

        return ($row['cycle'] ?? '') === 'anual' ? $cents * 10 : $cents;
    }

    private function orderStatus(int $orderId): string
    {
        $response = $this->call('GET', '/order/' . $orderId);
        $body = $this->body($response);
        if (($body['success'] ?? false) !== true) {
            return '';
        }

        return $this->statusOf($body);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function statusOf(array $body): string
    {
        $status = $this->pick($body, ['data.status', 'data.order.status', 'status']);

        return strtolower((string) ($status ?? ''));
    }

    private function isPaidStatus(string $status): bool
    {
        return in_array($status, ['aprovado', 'pago', 'paid', 'approved', 'integrado', 'autorizado', 'authorized'], true);
    }

    private function isRefusedStatus(string $status): bool
    {
        return in_array($status, [
            'recusado',
            'refused',
            'failed',
            'falhou',
            'cancelado',
            'canceled',
            'cancelled',
            'estornado',
            'unauthorized',
        ], true);
    }

    /**
     * @return array<string, mixed>
     */
    private function checkout(string $reference): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.id, c.public_id, c.cycle, c.amount_cents, p.name AS plan_name, p.price_cents AS plan_price, p.slug,
                    cu.name AS customer_name, cu.email AS customer_email, cu.phone, cu.document
             FROM checkouts c
             JOIN plans p ON p.id = c.plan_id
             JOIN customers cu ON cu.id = c.customer_id
             WHERE c.public_id = ? LIMIT 1'
        );
        $stmt->execute([$reference]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new RuntimeException('Checkout não encontrado para a AppMax.');
        }

        return $row;
    }

    private function clientIp(): string
    {
        $ip = trim((string) ($_SESSION['appmax_ip'] ?? ''));
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new RuntimeException('Ainda não coletamos o IP do navegador.');
        }

        return $ip;
    }

    private function digits(string $value): string
    {
        return (string) preg_replace('/\D/', '', $value);
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array{status:int, body:array<string, mixed>|string}
     */
    private function api(string $method, string $path, ?array $payload = null, bool $payment = false): array
    {
        $response = $this->call($method, $path, $payload);
        $body = $this->body($response);
        $success = $this->callAccepted($body);
        $httpOk = $response['status'] >= 200 && $response['status'] < 300;
        if ($httpOk && $success) {
            return $response;
        }
        Logger::get()->warning('AppMax recusou a chamada.', [
            'path' => $path,
            'status' => $response['status'],
        ]);
        if ($payment) {
            throw new PaymentRefused('O banco recusou este cartão.');
        }
        throw new RuntimeException('A AppMax não concluiu a cobrança.');
    }

    /**
     * O Pix da AppMax devolve success como o status da cobrança, por exemplo ATIVA.
     *
     * @param array<string, mixed> $body
     */
    private function callAccepted(array $body): bool
    {
        if (!array_key_exists('success', $body)) {
            return true;
        }
        $success = $body['success'];
        if ($success === true || $success === 1 || $success === '1') {
            return true;
        }
        if ($this->pixCode($body) !== '') {
            return true;
        }
        if (!is_string($success)) {
            return false;
        }

        return in_array(strtolower($success), [
            'ativa',
            'ativo',
            'ok',
            'success',
            'true',
            'aprovado',
            'pago',
            'paid',
            'approved',
            'autorizado',
            'authorized',
            'pendente',
        ], true);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function pixCode(array $body): string
    {
        $payload = $this->pick($body, [
            'data.pix_emv',
            'data.emv',
            'data.payment.pix_emv',
            'pix_emv',
        ]);

        return is_scalar($payload) ? trim((string) $payload) : '';
    }

    /**
     * @param array<string, mixed> $body
     */
    private function pixPng(array $body): string
    {
        $raw = $this->pick($body, ['data.pix_qrcode', 'data.qrcode', 'pix_qrcode']);
        if (!is_string($raw) || $raw === '') {
            return '';
        }
        $comma = strrpos($raw, ',');
        if ($comma !== false) {
            $raw = substr($raw, $comma + 1);
        }
        $raw = preg_replace('/\s+/', '', $raw) ?? '';
        if (!str_starts_with($raw, 'iVBORw0KGgo') || preg_match('/^[A-Za-z0-9+\/=]+$/', $raw) !== 1) {
            return '';
        }

        return $raw;
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array{status:int, body:array<string, mixed>|string}
     */
    private function call(string $method, string $path, ?array $payload = null): array
    {
        $token = $this->accessToken();
        $headers = [
            'Accept' => 'application/json',
            'access-token' => $token,
        ];
        $options = ['headers' => $headers, 'timeout' => 45];
        if ($payload !== null) {
            $payload['access-token'] = $token;
            $options['json'] = $payload;
        }

        return $this->poster()->request($method, 'https://admin.appmax.com.br/api/v3' . $path, $options);
    }

    private function accessToken(): string
    {
        if ($this->accessTokenOverride !== null && $this->accessTokenOverride !== '') {
            return $this->accessTokenOverride;
        }
        $token = self::storedToken();
        if ($token === '') {
            throw new RuntimeException('A AppMax não autenticou a loja.');
        }

        return $token;
    }

    private static function storedToken(): string
    {
        $env = Config::get('APPMAX_ACCESS_TOKEN', '');

        return $env !== '' ? $env : Settings::get('appmax_access_token', '');
    }

    /**
     * @param array{status:int, body:array<string, mixed>|string} $response
     * @return array<string, mixed>
     */
    private function body(array $response): array
    {
        return is_array($response['body']) ? $response['body'] : [];
    }

    /**
     * @param array<string, mixed> $body
     * @param list<string> $paths
     */
    private function pick(array $body, array $paths): mixed
    {
        foreach ($paths as $path) {
            $cursor = $body;
            foreach (explode('.', $path) as $part) {
                if (!is_array($cursor) || !array_key_exists($part, $cursor)) {
                    $cursor = null;
                    break;
                }
                $cursor = $cursor[$part];
            }
            if ($cursor !== null && $cursor !== '') {
                return $cursor;
            }
        }

        return null;
    }

    private function poster(): HttpPoster
    {
        return $this->http ?? new GuzzleHttpPoster();
    }
}
