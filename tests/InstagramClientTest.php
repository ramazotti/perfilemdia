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

    public function testExchangeCodeReadsTokenInsideData(): void
    {
        $fake = new FakeHttpPoster([
            [
                'status' => 200,
                'body' => [
                    'data' => [[
                        'access_token' => 'short-token',
                        'user_id' => '1784',
                        'permissions' => 'instagram_business_basic',
                    ]],
                ],
            ],
        ]);

        $result = (new InstagramClient($fake))->exchangeCode('auth-code');

        $this->assertSame('short-token', $result['access_token']);
        $this->assertSame('1784', $result['user_id']);
    }

    public function testOauthErrorMessageIsKept(): void
    {
        $fake = new FakeHttpPoster([
            [
                'status' => 400,
                'body' => [
                    'error_type' => 'OAuthException',
                    'code' => 400,
                    'error_message' => 'Matching code was not found or was already used',
                ],
            ],
        ]);

        try {
            (new InstagramClient($fake))->exchangeCode('auth-code');
            $this->fail('Expected InstagramApiException');
        } catch (InstagramApiException $e) {
            $this->assertSame('oauth', $e->kind);
            $this->assertSame('Matching code was not found or was already used', $e->getMessage());
        }
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

    public function testProfilePictureDownloadsTheHttpsFile(): void
    {
        $jpeg = $this->tinyJpeg();
        $fake = new FakeHttpPoster([
            [
                'status' => 200,
                'body' => ['profile_picture_url' => 'https://cdn.example/avatar.jpg'],
            ],
            [
                'status' => 200,
                'body' => $jpeg,
            ],
        ]);

        $bytes = (new InstagramClient($fake))->profilePicture('token');

        $this->assertSame($jpeg, $bytes);
        $this->assertSame('profile_picture_url', $fake->requests[0]['options']['query']['fields']);
        $this->assertSame('https://cdn.example/avatar.jpg', $fake->requests[1]['url']);
    }

    private function tinyJpeg(): string
    {
        $image = imagecreatetruecolor(8, 8);
        $this->assertNotFalse($image);
        ob_start();
        imagejpeg($image);
        $jpeg = ob_get_clean();
        imagedestroy($image);
        $this->assertIsString($jpeg);

        return $jpeg;
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
