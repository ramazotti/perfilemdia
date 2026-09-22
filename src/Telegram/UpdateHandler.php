<?php

declare(strict_types=1);

namespace PerfilEmDia\Telegram;

use PerfilEmDia\Billing\CheckoutService;
use PerfilEmDia\Channel\ChannelInterface;
use PerfilEmDia\Config;
use PerfilEmDia\Domain\OnboardingService;
use PerfilEmDia\Domain\PostRepository;
use PerfilEmDia\Domain\PostService;
use PerfilEmDia\Domain\UserRepository;
use PerfilEmDia\Messages;

final class UpdateHandler
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly PostRepository $posts,
        private readonly ChannelInterface $channel,
        private readonly OnboardingService $onboarding,
        private readonly PostService $postsService,
        private readonly ?CheckoutService $billing = null,
    ) {
    }

    /**
     * @param array<string, mixed> $update
     */
    public function handle(array $update): void
    {
        if (isset($update['callback_query']) && is_array($update['callback_query'])) {
            $this->handleCallback($update['callback_query']);

            return;
        }
        if (isset($update['message']) && is_array($update['message'])) {
            $this->handleMessage($update['message']);
        }
    }

    /**
     * @param array<string, mixed> $message
     */
    private function handleMessage(array $message): void
    {
        $from = $message['from'] ?? null;
        $chat = $message['chat'] ?? null;
        if (!is_array($from) || !is_array($chat)) {
            return;
        }
        $telegramUserId = (int) ($from['id'] ?? 0);
        $chatId = (int) ($chat['id'] ?? 0);
        if ($telegramUserId === 0 || $chatId === 0) {
            return;
        }

        $username = isset($from['username']) ? (string) $from['username'] : null;
        $user = $this->users->findByTelegramId($telegramUserId);
        if ($user === null) {
            $id = $this->users->create($telegramUserId, $chatId, $username);
            $user = $this->users->find($id);
            if ($user === null) {
                return;
            }
        } else {
            $this->users->update((int) $user['id'], [
                'telegram_chat_id' => $chatId,
                'telegram_username' => $username,
            ]);
            $user = $this->users->find((int) $user['id']) ?? $user;
        }

        $text = isset($message['text']) ? (string) $message['text'] : '';
        if ($text !== '' && str_starts_with($text, '/')) {
            $this->handleCommand($user, $chatId, $text);

            return;
        }

        if (preg_match('/^PD[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{6}$/i', trim($text)) === 1) {
            $this->startCommand($user, $chatId, trim($text));

            return;
        }

        if ($this->hasMedia($message)) {
            $this->postsService->handleIncomingMedia($user, $chatId, $message);

            return;
        }

        if ($text !== '') {
            if ($this->onboarding->handleText($user, $chatId, $text)) {
                return;
            }
            $user = $this->users->find((int) $user['id']) ?? $user;
            if ($this->postsService->handleThemeText($user, $chatId, $text)) {
                return;
            }
        }
    }

    /**
     * @param array<string, mixed> $user
     */
    private function handleCommand(array $user, int $chatId, string $text): void
    {
        $parts = preg_split('/\s+/', trim($text), 2) ?: [];
        $command = strtolower((string) ($parts[0] ?? ''));
        $command = explode('@', $command)[0];

        match ($command) {
            '/start' => $this->startCommand($user, $chatId, (string) ($parts[1] ?? '')),
            '/novo' => $this->channel->sendText($chatId, Messages::novo()),
            '/perfil' => $this->channel->sendText($chatId, Messages::perfil($user), Keyboards::perfilFields()),
            '/conectar' => $this->cmdConectar($user, $chatId),
            '/status' => $this->cmdStatus($user, $chatId),
            '/assinatura' => $this->cmdAssinatura($user, $chatId),
            '/cancelar' => $this->postsService->cancelPending($user, $chatId),
            '/ajuda' => $this->channel->sendText($chatId, Messages::ajuda()),
            '/excluirconta' => $this->channel->sendText(
                $chatId,
                Messages::deleteConfirm(),
                Keyboards::deleteConfirm()
            ),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $user
     */
    private function startCommand(array $user, int $chatId, string $arg): void
    {
        $code = strtoupper(trim($arg));
        if (preg_match('/^PD[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{6}$/', $code) === 1) {
            if ($this->billing !== null && $this->billing->activate($code, (int) $user['id'])) {
                $this->channel->sendText($chatId, Messages::activated());
                $fresh = $this->users->find((int) $user['id']) ?? $user;
                $this->onboarding->start($fresh, $chatId);

                return;
            }
            $this->channel->sendText($chatId, Messages::activationInvalid());

            return;
        }

        $this->onboarding->start($user, $chatId);
    }

    /**
     * @param array<string, mixed> $user
     */
    private function cmdAssinatura(array $user, int $chatId): void
    {
        $summary = $this->billing?->summaryForUser((int) $user['id']);
        if ($summary === null) {
            $url = rtrim(Config::get('APP_URL', 'https://perfilemdia.com.br'), '/') . '/planos';
            $this->channel->sendText($chatId, Messages::noSubscription($url));

            return;
        }

        $this->channel->sendText($chatId, Messages::subscription(
            (string) $summary['plan'],
            (string) $summary['cycle'],
            (string) $summary['status'],
            $summary['until'] !== null ? (string) $summary['until'] : null,
        ));
    }

    /**
     * @param array<string, mixed> $callback
     */
    private function handleCallback(array $callback): void
    {
        $callbackId = (string) ($callback['id'] ?? '');
        $data = (string) ($callback['data'] ?? '');
        $from = $callback['from'] ?? null;
        $message = $callback['message'] ?? null;
        if (!is_array($from) || $callbackId === '') {
            return;
        }
        $telegramUserId = (int) ($from['id'] ?? 0);
        $chatId = is_array($message) ? (int) ($message['chat']['id'] ?? 0) : 0;
        if ($chatId === 0 && isset($callback['message']) && is_array($callback['message'])) {
            $chatId = (int) ($callback['message']['chat']['id'] ?? 0);
        }
        $user = $this->users->findByTelegramId($telegramUserId);
        if ($user === null) {
            $this->channel->answerCallback($callbackId);

            return;
        }
        if ($chatId === 0) {
            $chatId = (int) $user['telegram_chat_id'];
        }

        if ($data === 'del:sim') {
            $this->channel->answerCallback($callbackId);
            $paths = $this->users->deleteAccount((int) $user['id']);
            foreach ($paths as $path) {
                $this->deleteMediaPath($path);
            }
            $this->channel->sendText($chatId, Messages::deleted());

            return;
        }
        if ($data === 'del:nao') {
            $this->channel->answerCallback($callbackId);
            $this->channel->sendText($chatId, Messages::cancelled());

            return;
        }
        if ($data === 'novo:sim' || $data === 'novo:nao') {
            $this->channel->answerCallback($callbackId);
            $this->postsService->handleReplaceDecision($user, $chatId, $data === 'novo:sim');

            return;
        }
        if ($data === 'como:como') {
            $this->channel->answerCallback($callbackId);
            $this->channel->sendText($chatId, Messages::instagramHowTo());

            return;
        }
        if (str_starts_with($data, 'tom:')) {
            $this->channel->answerCallback($callbackId);
            $this->onboarding->handleTone($user, $chatId, substr($data, 4));

            return;
        }
        if (str_starts_with($data, 'pular:')) {
            $this->channel->answerCallback($callbackId);
            $this->onboarding->handleSkip($user, $chatId, substr($data, 6));

            return;
        }
        if (str_starts_with($data, 'perfil:')) {
            $this->channel->answerCallback($callbackId);
            $this->onboarding->beginEdit($user, $chatId, substr($data, 7));

            return;
        }
        if (preg_match('/^a:(pub|adj|reg|man|can):(\d+)$/', $data, $m) === 1) {
            $this->postsService->handleApprovalCallback($user, $chatId, $callbackId, $m[1], (int) $m[2]);

            return;
        }

        $this->channel->answerCallback($callbackId, Messages::callbackHelp());
    }

    /**
     * @param array<string, mixed> $user
     */
    private function cmdConectar(array $user, int $chatId): void
    {
        if ((string) ($user['onboarding_step'] ?? '') !== 'done') {
            $this->onboarding->continueOnboarding($user, $chatId);

            return;
        }
        $this->onboarding->offerInstagram($user, $chatId);
    }

    /**
     * @param array<string, mixed> $user
     */
    private function cmdStatus(array $user, int $chatId): void
    {
        $ig = $this->users->instagramAccount((int) $user['id']);
        $username = is_array($ig) ? (string) ($ig['username'] ?? '') : '';
        if ($this->billing !== null) {
            $window = $this->billing->postWindow((int) $user['id']);
            if ($window !== null) {
                if ($window['scope'] === 'encerrado') {
                    $text = ($window['blocked'] ?? '') === 'recusado'
                        ? Messages::renewalRefused($window['plan'])
                        : Messages::periodEnded(
                            $window['plan'],
                            (int) $window['next_cents'],
                            (int) $window['days'],
                            $window['kind'],
                        );
                    $this->channel->sendText($chatId, $text);

                    return;
                }
                $used = $this->posts->countPublishedInMonth((int) $user['id'], $window['from'], $window['until']);
                $text = $window['scope'] === 'teste'
                    ? Messages::trialStatus($username, $used, (int) $window['limit'], (int) $window['days'])
                    : Messages::status($username, $used, (int) $window['limit']);
                $this->channel->sendText($chatId, $text);

                return;
            }
        }

        $limit = (int) Config::get('LIMIT_POSTS_PER_MONTH', '30');
        $tz = new \DateTimeZone('America/Sao_Paulo');
        $now = new \DateTimeImmutable('now', $tz);
        $monthStart = $now->modify('first day of this month')->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $nextMonth = $now->modify('first day of next month')->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $used = $this->posts->countPublishedInMonth((int) $user['id'], $monthStart, $nextMonth);
        $this->channel->sendText($chatId, Messages::status($username, $used, $limit));
    }

    /**
     * @param array<string, mixed> $message
     */
    private function hasMedia(array $message): bool
    {
        return !empty($message['photo']) || !empty($message['document']);
    }

    private function deleteMediaPath(string $path): void
    {
        if ($path !== '' && is_file($path)) {
            @unlink($path);

            return;
        }
        if ($path === '') {
            return;
        }
        $candidate = Config::root() . '/public/m/' . $path . '.jpg';
        if (is_file($candidate)) {
            @unlink($candidate);
        }
    }
}
