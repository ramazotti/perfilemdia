<?php

declare(strict_types=1);

namespace PerfilEmDia\Instagram;

use DateTimeImmutable;
use DateTimeZone;
use PerfilEmDia\Billing\PlanAccess;
use PerfilEmDia\Config;
use PerfilEmDia\Domain\UserRepository;
use RuntimeException;

final class InstagramOAuth
{
    public function __construct(
        private readonly InstagramClient $client,
        private readonly UserRepository $users,
        private readonly ?PlanAccess $access = null,
    ) {
    }

    public function authorizationUrl(string $state): string
    {
        Config::load();

        $query = http_build_query([
            'client_id' => Config::get('INSTAGRAM_APP_ID'),
            'redirect_uri' => Config::get('INSTAGRAM_REDIRECT_URI'),
            'response_type' => 'code',
            'scope' => Config::get('INSTAGRAM_SCOPES'),
            'state' => $state,
        ]);

        return 'https://www.instagram.com/oauth/authorize?' . $query;
    }

    /**
     * @return array{user_id:int, username:string, account_id:int, connect_mode:string}
     */
    public function handleCallback(string $code, string $state): array
    {
        $ctx = $this->users->consumeOauthState($state);
        if ($ctx === null) {
            throw new RuntimeException('OAuth state invalid or expired');
        }
        $userId = $ctx['user_id'];

        $short = $this->client->exchangeCode($code);
        $long = $this->client->exchangeLongLived($short['access_token']);
        $me = $this->client->me($long['access_token']);
        $accountType = strtoupper(str_replace(' ', '_', (string) ($me['account_type'] ?? '')));
        if (in_array($accountType, ['PERSONAL', 'MEDIA_PERSONAL'], true)) {
            throw new InstagramApiException('A conta do Instagram ainda é pessoal.', null, null, false, 'personal');
        }

        $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))
            ->modify('+' . (int) $long['expires_in'] . ' seconds');

        $max = $this->access !== null ? $this->access->maxInstagramAccounts($userId) : 1;
        $accountId = $this->users->saveInstagramAccount(
            $userId,
            $me['user_id'],
            $me['username'],
            $me['account_type'],
            $long['access_token'],
            $expiresAt,
            $max,
        );

        return [
            'user_id' => $userId,
            'username' => $me['username'],
            'account_id' => $accountId,
            'connect_mode' => $ctx['connect_mode'],
        ];
    }
}
