<?php

declare(strict_types=1);

namespace PerfilEmDia\Telegram;

use PerfilEmDia\Ai\OpenRouterSpeechTranscriber;
use PerfilEmDia\Ai\SpeechTranscriber;
use PerfilEmDia\Billing\CheckoutService;
use PerfilEmDia\Billing\CustomerAccess;
use PerfilEmDia\Db;
use PerfilEmDia\Channel\ChannelInterface;
use PerfilEmDia\Config;
use PerfilEmDia\Domain\OnboardingService;
use PerfilEmDia\Domain\PostRepository;
use PerfilEmDia\Domain\PostService;
use PerfilEmDia\Domain\TicketService;
use PerfilEmDia\Domain\UserRepository;
use PerfilEmDia\Logger;
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
        private readonly ?TicketService $tickets = null,
        private readonly ?SpeechTranscriber $speech = null,
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

        $spoken = $this->spokenText($chatId, $message);
        if ($spoken !== null) {
            if ($spoken === '') {
                return;
            }
            $text = $spoken;
        }

        if ($this->inTicketDraft($user)) {
            if ($spoken === null && $this->hasMedia($message)) {
                $this->channel->sendText($chatId, Messages::ticketNeedText(), Keyboards::ticketCompose());

                return;
            }
            if ($text !== '') {
                $this->tickets?->receive($user, $chatId, $text, $this->channel);

                return;
            }
        }

        if ($spoken === null && $this->hasMedia($message)) {
            $this->postsService->handleIncomingMedia($user, $chatId, $message);

            return;
        }

        if ($text !== '') {
            if ($this->onboarding->handleText($user, $chatId, $text)) {
                return;
            }
            $user = $this->users->find((int) $user['id']) ?? $user;
            if ($this->postsService->handleScheduleText($user, $chatId, $text)) {
                return;
            }
            if ($this->postsService->handleLogoWait($user, $chatId)) {
                return;
            }
            if ($this->postsService->handleAiVideoText($user, $chatId, $text)) {
                return;
            }
            if ($this->postsService->handlePhraseText($user, $chatId, $text)) {
                return;
            }
            if ($this->postsService->handleIdeaText($user, $chatId, $text)) {
                return;
            }
            if ($this->postsService->handleThemeText($user, $chatId, $text)) {
                return;
            }
            $this->postsService->replyWhenIdle($user, $chatId);
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
        if (!in_array($command, ['/chamado', '/suporte'], true)) {
            $this->leaveTicketDraft($user);
        }

        match ($command) {
            '/start' => $this->startCommand($user, $chatId, (string) ($parts[1] ?? '')),
            '/novo' => $this->postsService->askPostKind($chatId, $user),
            '/perfil' => $this->channel->sendText($chatId, Messages::perfil($user), Keyboards::perfilFields()),
            '/conectar' => $this->cmdConectar($user, $chatId),
            '/status' => $this->cmdStatus($user, $chatId),
            '/assinatura' => $this->cmdAssinatura($user, $chatId),
            '/cancelar' => $this->postsService->cancelPending($user, $chatId),
            '/chamado', '/suporte' => $this->tickets === null
                ? $this->channel->sendText($chatId, Messages::ajuda())
                : $this->tickets->showMenu($user, $chatId, $this->channel),
            '/ajuda', '/help' => $this->channel->sendText($chatId, Messages::ajuda()),
            '/ideia' => $this->postsService->showIdea($user, $chatId),
            '/resultado' => $this->postsService->report($user, $chatId),
            '/marca' => $this->onboarding->beginEdit($user, $chatId, 'marca'),
            '/excluirconta' => $this->channel->sendText(
                $chatId,
                Messages::deleteConfirm(),
                Keyboards::deleteConfirm()
            ),
            default => $this->channel->sendText($chatId, Messages::ajuda()),
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

        $url = $this->accountUrl((int) $user['id']);
        $status = match ((string) $summary['status']) {
            'ativa' => 'Ativa',
            'inadimplente' => 'Pagamento pendente',
            'pendente' => 'Aguardando pagamento',
            'cancelada' => 'Cancelada',
            default => (string) $summary['status'],
        };
        $note = '';
        if (!empty($summary['cancel_at'])) {
            $status = 'Cancelamento marcado';
            $note = 'O acesso segue até o fim do período pago.';
        } elseif ((string) ($summary['renew_method'] ?? '') !== 'cartao') {
            $note = 'Não há cartão salvo para a próxima cobrança.';
        }
        $buttons = $url !== '' ? Keyboards::openUrl('Minha conta', $url) : null;
        $this->channel->sendText($chatId, Messages::subscription(
            (string) $summary['plan'],
            (string) $summary['cycle'],
            $status,
            $summary['until'] !== null ? (string) $summary['until'] : null,
            $url,
            $note,
        ), $buttons);
    }

    private function accountUrl(int $userId): string
    {
        try {
            return (new CustomerAccess(Db::pdo()))->urlForUser($userId);
        } catch (\Throwable) {
            return '';
        }
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
        if (!str_starts_with($data, 'ch:')) {
            $this->leaveTicketDraft($user);
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
        if (str_starts_with($data, 'pk:')) {
            $this->channel->answerCallback($callbackId);
            $this->postsService->choosePostKind($user, $chatId, substr($data, 3));

            return;
        }
        if (preg_match('/^vd:(4|5|6|8|15)$/', $data, $m) === 1) {
            $this->postsService->chooseVideoSeconds($user, $chatId, $callbackId, (int) $m[1]);

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
        if ($this->tickets !== null && (
            $data === 'ch:novo' || $data === 'ch:sair' || $data === 'ch:menu'
            || str_starts_with($data, 'ch:ver:') || str_starts_with($data, 'ch:resp:')
        )) {
            $this->channel->answerCallback($callbackId);
            $this->tickets->handleCallback($user, $chatId, $data, $this->channel);

            return;
        }
        if (preg_match('/^s:(t18|n9|n18|in):(\d+)$/', $data, $m) === 1) {
            $this->postsService->chooseSchedule($user, $chatId, $callbackId, $m[1], (int) $m[2]);

            return;
        }
        if (preg_match('/^f:(cursiva|classica|limpa|forte|balao|caixa):(\d+)$/', $data, $m) === 1) {
            $this->postsService->choosePhraseStyle($user, $chatId, $callbackId, $m[1], (int) $m[2]);

            return;
        }
        if (preg_match('/^pc:(dourado|branco|preto):(\d+)$/', $data, $m) === 1) {
            $this->postsService->choosePhraseColor($user, $chatId, $callbackId, $m[1], (int) $m[2]);

            return;
        }
        if (preg_match('/^pp:(topo|meio|rodape):(\d+)$/', $data, $m) === 1) {
            $this->postsService->choosePhrasePlace($user, $chatId, $callbackId, $m[1], (int) $m[2]);

            return;
        }
        if (preg_match('/^m:(ig|up|ok):(\d+)$/', $data, $m) === 1) {
            $this->postsService->chooseMarkSource($user, $chatId, $callbackId, $m[1], (int) $m[2]);

            return;
        }
        if (preg_match('/^w:(tl|tr|bl|br|c):(ig|lg):(\d+)$/', $data, $m) === 1) {
            $this->postsService->placeMark($user, $chatId, $callbackId, $m[1], $m[2], (int) $m[3]);

            return;
        }
        if ($data === 'id:ia' || $data === 'id:on' || $data === 'id:off' || $data === 'id:sur') {
            $this->channel->answerCallback($callbackId);
            if ($data === 'id:sur') {
                $this->postsService->surprise($user, $chatId);
            } elseif ($data === 'id:ia') {
                $this->postsService->createFromStoredIdea($user, $chatId);
            } else {
                $this->postsService->setDailyIdeas($user, $chatId, $data === 'id:on');
            }

            return;
        }
        if (preg_match('/^a:(pub|adj|reg|man|can|img|txt|wm|sch|uns|sty|pic):(\d+)$/', $data, $m) === 1) {
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
                    $accountUrl = $this->accountUrl((int) $user['id']);
                    $text = ($window['blocked'] ?? '') === 'recusado'
                        ? Messages::renewalRefused($window['plan'], $accountUrl)
                        : Messages::periodEnded(
                            $window['plan'],
                            (int) $window['next_cents'],
                            (int) $window['days'],
                            $window['kind'],
                            $accountUrl,
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
    /**
     * @param array<string, mixed> $user
     */
    private function inTicketDraft(array $user): bool
    {
        return $this->tickets !== null
            && str_starts_with((string) ($user['pending_action'] ?? ''), 'chamado:');
    }

    /**
     * @param array<string, mixed> $user
     */
    private function leaveTicketDraft(array &$user): void
    {
        if (!$this->inTicketDraft($user)) {
            return;
        }
        $this->users->update((int) $user['id'], ['pending_action' => null]);
        $user['pending_action'] = null;
    }

    /**
     * @param array<string, mixed> $message
     */
    /**
     * @param array<string, mixed> $message
     */
    private function spokenText(int $chatId, array $message): ?string
    {
        $speech = SpeechMessage::from($message);
        if ($speech === null) {
            return null;
        }
        if (SpeechMessage::tooLong($speech)) {
            $this->channel->sendText($chatId, Messages::audioTooLong());

            return '';
        }

        $temp = tempnam(sys_get_temp_dir(), 'pdv');
        if ($temp === false) {
            $this->channel->sendText($chatId, Messages::audioFailed());

            return '';
        }

        try {
            $this->channel->download($speech['file_id'], $temp);
            $bytes = file_get_contents($temp);
            if (!is_string($bytes) || $bytes === '') {
                $this->channel->sendText($chatId, Messages::audioFailed());

                return '';
            }
            if (strlen($bytes) > SpeechMessage::MAX_BYTES) {
                $this->channel->sendText($chatId, Messages::audioTooLong());

                return '';
            }
            if ($speech['duration'] <= 0) {
                $measured = SpeechDuration::seconds($bytes, $speech['format']);
                if ($measured === null || $measured > SpeechMessage::MAX_SECONDS) {
                    $this->channel->sendText($chatId, Messages::audioTooLong());

                    return '';
                }
            }
            $text = trim(strip_tags(($this->speech ?? new OpenRouterSpeechTranscriber())->transcribe($bytes, $speech['format'])));
        } catch (\Throwable $e) {
            Logger::get()->error('Transcricao falhou', ['error' => $e->getMessage()]);
            $this->channel->sendText($chatId, Messages::audioFailed());

            return '';
        } finally {
            if (is_file($temp)) {
                unlink($temp);
            }
        }

        if (str_starts_with($text, '/')) {
            $text = ltrim(substr($text, 1));
        }
        $text = mb_substr($text, 0, 2000);
        if ($text === '') {
            $this->channel->sendText($chatId, Messages::audioEmpty());

            return '';
        }
        $this->channel->sendText($chatId, Messages::audioHeard($text));

        return $text;
    }

    /**
     * @param array<string, mixed> $message
     */
    private function hasMedia(array $message): bool
    {
        return !empty($message['photo'])
            || !empty($message['document'])
            || !empty($message['video'])
            || !empty($message['video_note']);
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
        foreach (['.jpg', '.mp4'] as $ext) {
            $candidate = Config::root() . '/public/m/' . $path . $ext;
            if (is_file($candidate)) {
                @unlink($candidate);
            }
        }
    }
}
