<?php

declare(strict_types=1);

namespace PerfilEmDia\Tests;

use PDO;
use PerfilEmDia\Domain\UserRepository;
use PerfilEmDia\Instagram\InstagramApiException;
use PerfilEmDia\Instagram\InstagramClient;
use PerfilEmDia\Instagram\InstagramOAuth;
use PerfilEmDia\Security\Crypto;
use PerfilEmDia\Support\HttpPoster;
use PHPUnit\Framework\TestCase;

final class InstagramClientTest extends TestCase
{
    protected function setUp(): void
    {
        $_ENV['INSTAGRAM_APP_ID'] = 'app-client-id';
        $_ENV['INSTAGRAM_APP_SECRET'] = 'app-secret';
        $_ENV['INSTAGRAM_REDIRECT_URI'] = 'https://example.com/oauth/instagram-callback.php';
        $_ENV['INSTAGRAM_SCOPES'] = 'instagram_business_basic,instagram_business_content_publish';
        $_ENV['INSTAGRAM_GRAPH_VERSION'] = 'v25.0';
        $_SERVER['INSTAGRAM_APP_ID'] = $_ENV['INSTAGRAM_APP_ID'];
        $_SERVER['INSTAGRAM_APP_SECRET'] = $_ENV['INSTAGRAM_APP_SECRET'];
        $_SERVER['INSTAGRAM_REDIRECT_URI'] = $_ENV['INSTAGRAM_REDIRECT_URI'];
        $_SERVER['INSTAGRAM_SCOPES'] = $_ENV['INSTAGRAM_SCOPES'];
        $_SERVER['INSTAGRAM_GRAPH_VERSION'] = $_ENV['INSTAGRAM_GRAPH_VERSION'];
        putenv('INSTAGRAM_APP_ID=app-client-id');
        putenv('INSTAGRAM_APP_SECRET=app-secret');
        putenv('INSTAGRAM_REDIRECT_URI=https://example.com/oauth/instagram-callback.php');
        putenv('INSTAGRAM_SCOPES=instagram_business_basic,instagram_business_content_publish');
        putenv('INSTAGRAM_GRAPH_VERSION=v25.0');
    }

    public function testAuthorizationUrlContainsClientIdAndState(): void
    {
        $users = new UserRepository(new PDO('sqlite::memory:'), new Crypto(sodium_crypto_secretbox_keygen()));
        $oauth = new InstagramOAuth(new InstagramClient(new FakeHttpPoster([])), $users);

        $url = $oauth->authorizationUrl('state-abc-123');

        $this->assertStringContainsString('client_id=app-client-id', $url);
        $this->assertStringContainsString('state=state-abc-123', $url);
        $this->assertStringStartsWith('https://www.instagram.com/oauth/authorize?', $url);
    }

    public function testExchangeCodeRemovesHashUnderscoreSuffix(): void
    {
        $fake = new FakeHttpPoster([
            [
                'status' => 200,
                'body' => ['access_token' => 'short-token', 'user_id' => '99'],
            ],
        ]);
        $client = new InstagramClient($fake);

        $result = $client->exchangeCode('auth-code-value#_');

        $this->assertSame('short-token', $result['access_token']);
        $this->assertSame('99', $result['user_id']);
        $this->assertSame('auth-code-value', $fake->requests[0]['options']['form_params']['code']);
    }

    public function testErrorCode190BecomesKindToken(): void
    {
        $fake = new FakeHttpPoster([
            [
                'status' => 400,
                'body' => [
                    'error' => [
                        'message' => 'Invalid OAuth access token',
                        'code' => 190,
                        'error_subcode' => 463,
                    ],
                ],
            ],
        ]);
        $client = new InstagramClient($fake);

        try {
            $client->me('bad-token');
            $this->fail('Expected InstagramApiException');
        } catch (InstagramApiException $e) {
            $this->assertSame('token', $e->kind);
            $this->assertSame(190, $e->errorCode);
            $this->assertFalse($e->retryable);
        }
    }
}

/**
 * @phpstan-type Response array{status:int, body:array<string, mixed>|string}
 */
final class FakeHttpPoster implements HttpPoster
{
    /** @var list<array{method:string, url:string, options:array<string, mixed>}> */
    public array $requests = [];

    /** @var list<Response> */
    private array $queue;

    /**
     * @param list<Response> $queue
     */
    public function __construct(array $queue)
    {
        $this->queue = $queue;
    }

    public function request(string $method, string $url, array $options = []): array
    {
        $this->requests[] = [
            'method' => $method,
            'url' => $url,
            'options' => $options,
        ];

        if ($this->queue === []) {
            return ['status' => 500, 'body' => ['error' => ['message' => 'no more fake responses']]];
        }

        return array_shift($this->queue);
    }
}
