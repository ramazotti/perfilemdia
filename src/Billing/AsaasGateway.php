<?php

declare(strict_types=1);

namespace PerfilEmDia\Billing;

use PerfilEmDia\Config;
use PerfilEmDia\Support\GuzzleHttpPoster;
use PerfilEmDia\Support\HttpPoster;
use RuntimeException;

/**
 * Cobrança no Asaas. O número do cartão não passa por aqui: só o token gerado pelo gateway.
 */
final class AsaasGateway implements PaymentGateway
{
    public function __construct(private readonly ?HttpPoster $http = null)
    {
    }

    public function name(): string
    {
        return 'asaas';
    }

    public function createPix(string $customerName, string $document, int $amountCents, string $reference): array
    {
        $customer = $this->customerId($customerName, $document);
        $payment = $this->request('POST', '/payments', [
            'customer' => $customer,
            'billingType' => 'PIX',
            'value' => $amountCents / 100,
            'dueDate' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
            'externalReference' => $reference,
        ]);
        $id = (string) ($payment['id'] ?? '');
        if ($id === '') {
            throw new RuntimeException('Asaas não devolveu o pagamento.');
        }
        $qr = $this->request('GET', '/payments/' . rawurlencode($id) . '/pixQrCode');

        return [
            'external_id' => $id,
            'payload' => (string) ($qr['payload'] ?? ''),
            'expires_at' => (new \DateTimeImmutable('now'))->modify('+30 minutes')->format('Y-m-d H:i:s'),
        ];
    }

    public function chargeCard(string $token, int $amountCents, string $reference): array
    {
        throw new RuntimeException('A cobrança com cartão usa o token do Asaas e ainda precisa da chave de produção.');
    }

    private function customerId(string $name, string $document): string
    {
        $found = $this->request('GET', '/customers?cpfCnpj=' . rawurlencode($document));
        $data = $found['data'] ?? [];
        if (is_array($data) && isset($data[0]['id'])) {
            return (string) $data[0]['id'];
        }
        $created = $this->request('POST', '/customers', [
            'name' => $name,
            'cpfCnpj' => $document,
        ]);

        return (string) ($created['id'] ?? '');
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $base = Config::get('PAYMENT_ENV', 'sandbox') === 'production'
            ? 'https://api.asaas.com/v3'
            : 'https://api-sandbox.asaas.com/v3';
        $http = $this->http ?? new GuzzleHttpPoster();
        $options = [
            'headers' => [
                'access_token' => Config::get('PAYMENT_API_KEY', ''),
                'Content-Type' => 'application/json',
                'User-Agent' => 'PerfilEmDia',
            ],
        ];
        if ($body !== null) {
            $options['json'] = $body;
        }
        $response = $http->request($method, $base . $path, $options);
        $decoded = is_array($response['body'] ?? null) ? $response['body'] : [];
        $status = (int) ($response['status'] ?? 0);
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Asaas respondeu ' . $status);
        }

        return $decoded;
    }
}
