<?php

declare(strict_types=1);

namespace PerfilEmDia\Domain;

use DateTimeImmutable;
use DateTimeZone;
use PerfilEmDia\Ai\CaptionException;
use PerfilEmDia\Ai\CaptionGeneratorInterface;
use PerfilEmDia\Billing\PlanAccess;
use PerfilEmDia\Channel\ChannelInterface;
use PerfilEmDia\Config;
use PerfilEmDia\Image\ImageNormalizerInterface;
use PerfilEmDia\Instagram\InstagramApiException;
use PerfilEmDia\Instagram\InstagramPublisherInterface;
use PerfilEmDia\Logger;
use PerfilEmDia\Messages;
use PerfilEmDia\Telegram\Keyboards;
use RuntimeException;

class PostService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly PostRepository $posts,
        private readonly ChannelInterface $channel,
        private readonly ImageNormalizerInterface $normalizer,
        private readonly CaptionGeneratorInterface $captions,
        private readonly InstagramPublisherInterface $publisher,
        private readonly ?PlanAccess $access = null,
    ) {
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $message
     */
    public function handleIncomingMedia(array $user, int $chatId, array $message): void
    {
        if (!$this->guardsPass($user, $chatId)) {
            return;
        }

        $pending = $this->posts->findPendingForUser((int) $user['id']);
        $userPending = (string) ($user['pending_action'] ?? '');
        if ($pending !== null && !str_starts_with($userPending, 'newpost:')) {
            $draftId = $this->createDraftFromMessage($user, $message);
            $this->users->update((int) $user['id'], [
                'pending_action' => 'newpost:' . $draftId,
            ]);
            $this->channel->sendText($chatId, Messages::replacePending(), Keyboards::yesNoPending());

            return;
        }

        $mediaGroupId = isset($message['media_group_id']) ? (string) $message['media_group_id'] : null;
        if ($mediaGroupId !== null) {
            $this->handleAlbumItem($user, $chatId, $message, $mediaGroupId);

            return;
        }

        $this->startSinglePost($user, $chatId, $message);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function handleReplaceDecision(array $user, int $chatId, bool $replace): void
    {
        $pendingAction = (string) ($user['pending_action'] ?? '');
        if (!str_starts_with($pendingAction, 'newpost:')) {
            return;
        }
        $draftId = (int) substr($pendingAction, strlen('newpost:'));
        $this->users->update((int) $user['id'], ['pending_action' => null]);

        if (!$replace) {
            $draft = $this->posts->find($draftId);
            if ($draft !== null && (string) $draft['status'] === PostStatus::Collecting->value) {
                $this->posts->transition($draftId, PostStatus::Collecting, PostStatus::Cancelled);
            }
            $old = $this->posts->findPendingForUser((int) $user['id']);
            if ($old !== null) {
                $this->channel->sendText($chatId, Messages::replacePending());
            }

            return;
        }

        $old = $this->posts->findPendingForUser((int) $user['id']);
        if ($old !== null) {
            $from = PostStatus::from((string) $old['status']);
            if ($from->isPending()) {
                $this->posts->transition((int) $old['id'], $from, PostStatus::Cancelled);
            }
        }

        $draft = $this->posts->find($draftId);
        if ($draft === null) {
            return;
        }

        $theme = $draft['theme_text'] !== null ? (string) $draft['theme_text'] : null;
        $this->channel->sendText($chatId, Messages::received());
        if ($theme === null || trim($theme) === '') {
            if (!$this->posts->transition($draftId, PostStatus::Collecting, PostStatus::AwaitingTheme)) {
                return;
            }
            $this->channel->sendText($chatId, Messages::askTheme());

            return;
        }

        if (!$this->posts->transition($draftId, PostStatus::Collecting, PostStatus::Generating)) {
            return;
        }
        $this->generateAndPreview((int) $user['id'], $chatId, $draftId);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function handleThemeText(array $user, int $chatId, string $text): bool
    {
        $pending = $this->posts->findPendingForUser((int) $user['id']);
        if ($pending === null) {
            return false;
        }
        $status = PostStatus::from((string) $pending['status']);
        $postId = (int) $pending['id'];

        if ($status === PostStatus::AwaitingTheme) {
            $theme = trim(strip_tags($text));
            if ($theme === '') {
                $this->channel->sendText($chatId, Messages::askTheme());

                return true;
            }
            $this->posts->update($postId, ['theme_text' => mb_substr($theme, 0, 1000)]);
            if (!$this->posts->transition($postId, PostStatus::AwaitingTheme, PostStatus::Generating)) {
                return true;
            }
            $this->channel->sendText($chatId, Messages::received());
            $this->generateAndPreview((int) $user['id'], $chatId, $postId);

            return true;
        }

        if ($status === PostStatus::AwaitingFeedback) {
            $feedback = trim(strip_tags($text));
            $this->posts->update($postId, [
                'last_feedback' => mb_substr($feedback, 0, 1000),
                'regen_count' => ((int) $pending['regen_count']) + 1,
            ]);
            if (!$this->posts->transition($postId, PostStatus::AwaitingFeedback, PostStatus::Generating)) {
                return true;
            }
            $this->generateAndPreview((int) $user['id'], $chatId, $postId);

            return true;
        }

        if ($status === PostStatus::AwaitingManualEdit) {
            $caption = trim(strip_tags($text));
            $this->posts->update($postId, [
                'caption' => $caption,
                'caption_version' => ((int) $pending['caption_version']) + 1,
            ]);
            if (!$this->posts->transition($postId, PostStatus::AwaitingManualEdit, PostStatus::AwaitingApproval)) {
                return true;
            }
            $this->sendPreview($user, $chatId, $postId);

            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $user
     */
    public function handleApprovalCallback(
        array $user,
        int $chatId,
        string $callbackId,
        string $action,
        int $postId,
    ): void {
        $this->channel->answerCallback($callbackId);
        $post = $this->posts->find($postId);
        if ($post === null || (int) $post['user_id'] !== (int) $user['id']) {
            return;
        }

        match ($action) {
            'pub' => $this->publish($user, $chatId, $post),
            'adj', 'reg', 'man', 'can' => $this->handleApprovalAction($user, $chatId, $action, $post),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $post
     */
    private function handleApprovalAction(array $user, int $chatId, string $action, array $post): void
    {
        if ((string) $post['status'] !== PostStatus::AwaitingApproval->value) {
            $this->channel->sendText($chatId, Messages::alreadyProcessed());

            return;
        }

        match ($action) {
            'adj' => $this->askAdjust($chatId, $post),
            'reg' => $this->regen($user, $chatId, $post),
            'man' => $this->askManual($chatId, $post),
            'can' => $this->cancelPost($chatId, $post),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $user
     */
    public function cancelPending(array $user, int $chatId): void
    {
        $pending = $this->posts->findPendingForUser((int) $user['id']);
        if ($pending === null) {
            return;
        }
        $from = PostStatus::from((string) $pending['status']);
        if (!$from->isPending()) {
            return;
        }
        if (!$this->posts->transition((int) $pending['id'], $from, PostStatus::Cancelled)) {
            return;
        }
        $this->channel->sendText($chatId, Messages::cancelled());
    }

    public function finalizeCollecting(int $postId): void
    {
        $post = $this->posts->find($postId);
        if ($post === null || (string) $post['status'] !== PostStatus::Collecting->value) {
            return;
        }
        $user = $this->users->find((int) $post['user_id']);
        if ($user === null) {
            return;
        }
        $chatId = (int) $user['telegram_chat_id'];
        $theme = $post['theme_text'] !== null ? trim((string) $post['theme_text']) : '';
        $target = $theme === '' ? PostStatus::AwaitingTheme : PostStatus::Generating;
        if (!$this->posts->transition($postId, PostStatus::Collecting, $target)) {
            return;
        }
        $this->channel->sendText($chatId, Messages::received());
        if ($target === PostStatus::AwaitingTheme) {
            $this->channel->sendText($chatId, Messages::askTheme());

            return;
        }
        $this->generateAndPreview((int) $user['id'], $chatId, $postId);
    }

    protected function waitForAlbum(): void
    {
        sleep(4);
    }

    protected function backoff(int $attempt): void
    {
        $seconds = [1 => 2, 2 => 5, 3 => 15][$attempt] ?? 15;
        sleep($seconds);
    }

    /**
     * @param array<string, mixed> $user
     */
    private function guardsPass(array $user, int $chatId): bool
    {
        if ((string) ($user['onboarding_step'] ?? '') !== 'done') {
            $this->channel->sendText($chatId, Messages::needOnboarding());

            return false;
        }

        $ig = $this->users->instagramAccount((int) $user['id']);
        if ($ig === null || (string) ($ig['status'] ?? '') !== 'active') {
            $this->channel->sendText($chatId, Messages::needInstagram());

            return false;
        }

        $window = $this->access?->window((int) $user['id']);
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

                return false;
            }
            $used = $this->posts->countPublishedInMonth((int) $user['id'], $window['from'], $window['until']);
            if ($used >= $window['limit']) {
                $text = $window['scope'] === 'teste'
                    ? Messages::trialLimit((int) $window['limit'], (int) $window['days'])
                    : Messages::monthLimit((int) $window['limit']);
                $this->channel->sendText($chatId, $text);

                return false;
            }

            return true;
        }

        $limit = (int) Config::get('LIMIT_POSTS_PER_MONTH', '30');
        $tz = new DateTimeZone('America/Sao_Paulo');
        $now = new DateTimeImmutable('now', $tz);
        $monthStart = $now->modify('first day of this month')->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $nextMonth = $now->modify('first day of next month')->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $used = $this->posts->countPublishedInMonth((int) $user['id'], $monthStart, $nextMonth);
        if ($used >= $limit) {
            $this->channel->sendText($chatId, Messages::monthLimit($limit));

            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $message
     */
    private function createDraftFromMessage(array $user, array $message): int
    {
        $theme = $this->extractTheme($message);
        $mediaGroupId = isset($message['media_group_id']) ? (string) $message['media_group_id'] : null;
        $postId = $this->posts->create((int) $user['id'], PostStatus::Collecting, $theme, $mediaGroupId);
        $file = $this->extractFile($message);
        if ($file !== null) {
            $this->posts->addMedia($postId, 0, $file['file_id'], (int) ($message['message_id'] ?? 0));
        }

        return $postId;
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $message
     */
    private function startSinglePost(array $user, int $chatId, array $message): void
    {
        $theme = $this->extractTheme($message);
        $file = $this->extractFile($message);
        if ($file === null) {
            return;
        }

        $this->channel->sendText($chatId, Messages::received());

        $status = ($theme === null || $theme === '') ? PostStatus::AwaitingTheme : PostStatus::Generating;
        $postId = $this->posts->create((int) $user['id'], $status, $theme);
        $this->posts->addMedia($postId, 0, $file['file_id'], (int) ($message['message_id'] ?? 0));

        if ($status === PostStatus::AwaitingTheme) {
            $this->channel->sendText($chatId, Messages::askTheme());

            return;
        }

        $this->generateAndPreview((int) $user['id'], $chatId, $postId);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $message
     */
    private function handleAlbumItem(array $user, int $chatId, array $message, string $mediaGroupId): void
    {
        $existing = $this->posts->findByMediaGroup($mediaGroupId);
        $file = $this->extractFile($message);
        if ($file === null) {
            return;
        }
        $theme = $this->extractTheme($message);
        $messageId = (int) ($message['message_id'] ?? 0);

        if ($existing === null) {
            $postId = $this->posts->create((int) $user['id'], PostStatus::Collecting, $theme, $mediaGroupId);
            $this->posts->addMedia($postId, 0, $file['file_id'], $messageId);
            $this->waitForAlbum();
            $this->tryFinalizeAlbum($postId, $messageId);

            return;
        }

        $postId = (int) $existing['id'];
        if ((string) $existing['status'] !== PostStatus::Collecting->value) {
            return;
        }

        $media = $this->posts->media($postId);
        if (count($media) >= 10) {
            $this->channel->sendText($chatId, Messages::carouselOverflow());

            return;
        }

        if ($theme !== null && $theme !== '') {
            $this->posts->update($postId, ['theme_text' => $theme]);
        }

        $this->posts->addMedia($postId, count($media), $file['file_id'], $messageId);
        $this->waitForAlbum();
        $this->tryFinalizeAlbum($postId, $messageId);
    }

    private function tryFinalizeAlbum(int $postId, int $messageId): void
    {
        $post = $this->posts->find($postId);
        if ($post === null || (string) $post['status'] !== PostStatus::Collecting->value) {
            return;
        }

        $media = $this->posts->media($postId);
        $maxMsgId = 0;
        foreach ($media as $row) {
            $maxMsgId = max($maxMsgId, (int) $row['telegram_msg_id']);
        }
        if ($messageId < $maxMsgId) {
            return;
        }

        $user = $this->users->find((int) $post['user_id']);
        if ($user === null) {
            return;
        }
        $chatId = (int) $user['telegram_chat_id'];
        $theme = $post['theme_text'] !== null ? trim((string) $post['theme_text']) : '';
        $target = $theme === '' ? PostStatus::AwaitingTheme : PostStatus::Generating;
        if (!$this->posts->transition($postId, PostStatus::Collecting, $target)) {
            return;
        }
        $this->channel->sendText($chatId, Messages::received());
        if ($target === PostStatus::AwaitingTheme) {
            $this->channel->sendText($chatId, Messages::askTheme());

            return;
        }
        $this->generateAndPreview((int) $user['id'], $chatId, $postId);
    }

    private function generateAndPreview(int $userId, int $chatId, int $postId): void
    {
        $post = $this->posts->find($postId);
        $user = $this->users->find($userId);
        if ($post === null || $user === null) {
            return;
        }
        if ((string) $post['status'] !== PostStatus::Generating->value) {
            return;
        }

        try {
            $mediaRows = $this->posts->media($postId);
            $sourcePaths = [];
            foreach ($mediaRows as $index => $row) {
                $ext = 'jpg';
                $dest = Config::root() . '/storage/media/' . $postId . '_' . $index . '.' . $ext;
                if (empty($row['original_path']) || !is_file((string) $row['original_path'])) {
                    $this->channel->download((string) $row['telegram_file_id'], $dest);
                    $this->posts->updateMedia((int) $row['id'], ['original_path' => $dest]);
                } else {
                    $dest = (string) $row['original_path'];
                }
                $sourcePaths[] = $dest;
            }

            $publicDir = Config::root() . '/public/m';
            if (!is_dir($publicDir) && !mkdir($publicDir, 0775, true) && !is_dir($publicDir)) {
                throw new RuntimeException('Nao foi possivel criar public/m');
            }

            $normalized = $this->normalizer->normalize($sourcePaths, $publicDir);
            foreach ($normalized as $i => $image) {
                $mediaId = (int) ($mediaRows[$i]['id'] ?? 0);
                if ($mediaId > 0) {
                    $this->posts->updateMedia($mediaId, [
                        'public_name' => $image->publicName,
                        'width' => $image->width,
                        'height' => $image->height,
                    ]);
                }
            }

            $jpegPaths = array_map(static fn ($img) => $img->absolutePath, $normalized);
            $profile = [
                'display_name' => $user['display_name'] ?? null,
                'profession' => $user['profession'] ?? null,
                'city' => $user['city'] ?? null,
                'tone' => $user['tone'] ?? null,
                'contact_cta' => $user['contact_cta'] ?? null,
                'about' => $user['about'] ?? null,
                'fixed_hashtags' => $user['fixed_hashtags'] ?? null,
            ];
            $previous = $post['caption'] !== null ? (string) $post['caption'] : null;
            $feedback = $post['last_feedback'] !== null ? (string) $post['last_feedback'] : null;
            $result = $this->captions->generate(
                $profile,
                (string) ($post['theme_text'] ?? ''),
                $jpegPaths,
                $previous,
                $feedback,
            );

            $this->posts->update($postId, [
                'caption' => $result->caption,
                'alt_text' => $result->altText,
                'caption_version' => ((int) $post['caption_version']) + 1,
                'last_feedback' => null,
            ]);
            $this->posts->recordAiUsage(
                $userId,
                $postId,
                $result->model,
                $result->inputTokens,
                $result->outputTokens,
            );

            if (!$this->posts->transition($postId, PostStatus::Generating, PostStatus::AwaitingApproval)) {
                return;
            }
            $this->sendPreview($user, $chatId, $postId);
        } catch (CaptionException $e) {
            if ($e->kind === 'conteudo_inadequado') {
                $this->failPost($postId, PostStatus::Generating, 'inappropriate', $e->getMessage());
                $this->channel->sendText($chatId, Messages::inappropriate());

                return;
            }
            $this->failPost($postId, PostStatus::Generating, $e->kind, $e->getMessage());
            Logger::get()->error('Caption falhou', ['post_id' => $postId, 'error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            if ($e::class === 'PerfilEmDia\\Image\\ImageUnsupportedException') {
                $this->failPost($postId, PostStatus::Generating, 'image_unsupported', $e->getMessage());
                $this->channel->sendText($chatId, Messages::heicUnsupported());

                return;
            }
            Logger::get()->error('Geracao falhou', ['post_id' => $postId, 'error' => $e->getMessage()]);
            $this->failPost($postId, PostStatus::Generating, 'generate_error', $e->getMessage());
            throw new RuntimeException($e->getMessage(), 0, $e);
        }
    }

    /**
     * @param array<string, mixed> $user
     */
    private function sendPreview(array $user, int $chatId, int $postId): void
    {
        $post = $this->posts->find($postId);
        if ($post === null) {
            return;
        }
        $media = $this->posts->media($postId);
        $first = $media[0] ?? null;
        if ($first === null || empty($first['public_name'])) {
            return;
        }
        $path = Config::root() . '/public/m/' . $first['public_name'] . '.jpg';
        $caption = (string) ($post['caption'] ?? '');
        $limit = (int) Config::get('LIMIT_REGENERATIONS_PER_POST', '5');
        $allowRegen = ((int) $post['regen_count']) < $limit;
        if (!$allowRegen) {
            $this->channel->sendText($chatId, Messages::regenLimit());
        }
        $buttons = Keyboards::approval($postId, $allowRegen);
        $previewId = $this->channel->sendPhoto($chatId, $path, $caption, $buttons);
        $this->posts->update($postId, ['preview_message_id' => $previewId]);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $post
     */
    private function publish(array $user, int $chatId, array $post): void
    {
        $postId = (int) $post['id'];
        if (!$this->posts->transition($postId, PostStatus::AwaitingApproval, PostStatus::Publishing)) {
            $this->channel->sendText($chatId, Messages::alreadyProcessed());

            return;
        }

        $previewId = $post['preview_message_id'] !== null ? (int) $post['preview_message_id'] : null;
        if ($previewId !== null) {
            $this->channel->editButtons($chatId, $previewId, null);
        }
        $this->channel->sendText($chatId, Messages::publishing());

        $ig = $this->users->instagramAccount((int) $user['id']);
        if ($ig === null) {
            $this->posts->transition($postId, PostStatus::Publishing, PostStatus::AwaitingApproval);
            $this->channel->sendText($chatId, Messages::needInstagram());

            return;
        }

        $media = $this->posts->media($postId);
        $appUrl = rtrim(Config::get('APP_URL'), '/');
        $urls = [];
        foreach ($media as $row) {
            if (empty($row['public_name'])) {
                continue;
            }
            $urls[] = $appUrl . '/m/' . $row['public_name'] . '.jpg';
        }

        $attempt = 0;
        while ($attempt < 3) {
            $attempt++;
            try {
                $published = $this->publisher->publish(
                    (string) $ig['ig_user_id'],
                    (string) $ig['access_token'],
                    $urls,
                    (string) ($post['caption'] ?? ''),
                    $post['alt_text'] !== null ? (string) $post['alt_text'] : null,
                );
                if (!$this->posts->transition($postId, PostStatus::Publishing, PostStatus::Published)) {
                    return;
                }
                $this->posts->update($postId, [
                    'ig_container_id' => $published->containerId,
                    'ig_media_id' => $published->mediaId,
                    'ig_permalink' => $published->permalink,
                    'published_at' => (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))
                        ->format('Y-m-d H:i:s'),
                ]);
                $this->posts->recordEvent((int) $user['id'], $postId, 'post_published', [
                    'media_id' => $published->mediaId,
                ]);
                $msgId = $previewId ?? 0;
                if ($msgId > 0) {
                    $this->channel->editText(
                        $chatId,
                        $msgId,
                        Messages::published(),
                        Keyboards::openUrl('Ver no Instagram', $published->permalink),
                    );
                } else {
                    $this->channel->sendText(
                        $chatId,
                        Messages::published(),
                        Keyboards::openUrl('Ver no Instagram', $published->permalink),
                    );
                }

                return;
            } catch (InstagramApiException $e) {
                $this->handlePublishError($user, $chatId, $postId, $previewId, $e, $attempt);
                if ($e->kind !== 'network' || $attempt >= 3) {
                    return;
                }
                $this->backoff($attempt);
            }
        }
    }

    /**
     * @param array<string, mixed> $user
     */
    private function handlePublishError(
        array $user,
        int $chatId,
        int $postId,
        ?int $previewId,
        InstagramApiException $e,
        int $attempt,
    ): void {
        $this->posts->update($postId, [
            'error_code' => $e->errorCode !== null ? (string) $e->errorCode : $e->kind,
            'error_message' => $e->getMessage(),
            'attempts' => $attempt,
        ]);

        if ($e->kind === 'token') {
            $this->users->markInstagramStatus((int) $user['id'], 'expired');
            $this->posts->transition($postId, PostStatus::Publishing, PostStatus::AwaitingApproval);
            $state = $this->users->createOauthState((int) $user['id']);
            $url = rtrim(Config::get('APP_URL'), '/') . '/conectar.php?t=' . $state;
            $this->channel->sendText($chatId, Messages::tokenExpired(), Keyboards::openUrl('Reconectar Instagram', $url));

            return;
        }

        if ($e->kind === 'rate_limit') {
            $this->posts->transition($postId, PostStatus::Publishing, PostStatus::AwaitingApproval);
            $this->channel->sendText($chatId, Messages::rateLimited());

            return;
        }

        if ($e->kind === 'media_invalid') {
            Logger::get()->error('Media invalida no Instagram', [
                'post_id' => $postId,
                'code' => $e->errorCode,
                'subcode' => $e->subcode,
            ]);
            $this->posts->transition($postId, PostStatus::Publishing, PostStatus::Failed);
            $this->channel->sendText($chatId, Messages::publishFailed());

            return;
        }

        if ($e->kind === 'network') {
            if ($attempt < 3) {
                return;
            }
            $this->posts->transition($postId, PostStatus::Publishing, PostStatus::AwaitingApproval);
            $this->channel->sendText($chatId, Messages::publishRetry());

            return;
        }

        $this->posts->transition($postId, PostStatus::Publishing, PostStatus::Failed);
        $this->channel->sendText($chatId, Messages::publishFailed());
    }

    /**
     * @param array<string, mixed> $post
     */
    private function askAdjust(int $chatId, array $post): void
    {
        $limit = (int) Config::get('LIMIT_REGENERATIONS_PER_POST', '5');
        if (((int) $post['regen_count']) >= $limit) {
            $this->channel->sendText($chatId, Messages::regenLimit());

            return;
        }
        if (!$this->posts->transition((int) $post['id'], PostStatus::AwaitingApproval, PostStatus::AwaitingFeedback)) {
            $this->channel->sendText($chatId, Messages::alreadyProcessed());

            return;
        }
        $this->channel->sendText($chatId, Messages::askFeedback());
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $post
     */
    private function regen(array $user, int $chatId, array $post): void
    {
        $limit = (int) Config::get('LIMIT_REGENERATIONS_PER_POST', '5');
        if (((int) $post['regen_count']) >= $limit) {
            $this->channel->sendText($chatId, Messages::regenLimit());

            return;
        }
        $this->posts->update((int) $post['id'], [
            'last_feedback' => null,
            'regen_count' => ((int) $post['regen_count']) + 1,
        ]);
        if (!$this->posts->transition((int) $post['id'], PostStatus::AwaitingApproval, PostStatus::Generating)) {
            $this->channel->sendText($chatId, Messages::alreadyProcessed());

            return;
        }
        $this->generateAndPreview((int) $user['id'], $chatId, (int) $post['id']);
    }

    /**
     * @param array<string, mixed> $post
     */
    private function askManual(int $chatId, array $post): void
    {
        if (!$this->posts->transition((int) $post['id'], PostStatus::AwaitingApproval, PostStatus::AwaitingManualEdit)) {
            $this->channel->sendText($chatId, Messages::alreadyProcessed());

            return;
        }
        $this->channel->sendText($chatId, Messages::askManual());
    }

    /**
     * @param array<string, mixed> $post
     */
    private function cancelPost(int $chatId, array $post): void
    {
        if (!$this->posts->transition((int) $post['id'], PostStatus::AwaitingApproval, PostStatus::Cancelled)) {
            $this->channel->sendText($chatId, Messages::alreadyProcessed());

            return;
        }
        $this->channel->sendText($chatId, Messages::cancelled());
    }

    private function failPost(int $postId, PostStatus $from, string $code, string $message): void
    {
        $this->posts->transition($postId, $from, PostStatus::Failed);
        $this->posts->update($postId, [
            'error_code' => $code,
            'error_message' => $message,
        ]);
    }

    /**
     * @param array<string, mixed> $message
     */
    private function extractTheme(array $message): ?string
    {
        $caption = isset($message['caption']) ? trim(strip_tags((string) $message['caption'])) : '';
        if ($caption !== '') {
            return mb_substr($caption, 0, 1000);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $message
     * @return array{file_id:string}|null
     */
    private function extractFile(array $message): ?array
    {
        if (!empty($message['photo']) && is_array($message['photo'])) {
            $photos = $message['photo'];
            $last = $photos[array_key_last($photos)];
            if (is_array($last) && !empty($last['file_id'])) {
                return ['file_id' => (string) $last['file_id']];
            }
        }

        if (!empty($message['document']) && is_array($message['document'])) {
            $mime = strtolower((string) ($message['document']['mime_type'] ?? ''));
            $allowed = [
                'image/jpeg',
                'image/png',
                'image/heic',
                'image/heif',
                'image/webp',
            ];
            if (in_array($mime, $allowed, true) && !empty($message['document']['file_id'])) {
                return ['file_id' => (string) $message['document']['file_id']];
            }
        }

        return null;
    }
}
