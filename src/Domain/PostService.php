<?php

declare(strict_types=1);

namespace PerfilEmDia\Domain;

use DateTimeImmutable;
use DateTimeZone;
use PerfilEmDia\Ai\CaptionException;
use PerfilEmDia\Ai\CaptionGeneratorInterface;
use PerfilEmDia\Billing\CustomerAccess;
use PerfilEmDia\Billing\PlanAccess;
use PerfilEmDia\Channel\ChannelInterface;
use PerfilEmDia\Config;
use PerfilEmDia\Db;
use PerfilEmDia\Growth\BenefitOrchestrator;
use PerfilEmDia\Image\IdeaImage;
use PerfilEmDia\Image\IdeaImageGenerator;
use PerfilEmDia\Image\ImageEditException;
use PerfilEmDia\Image\ImageEditRequest;
use PerfilEmDia\Image\ImageEditorInterface;
use PerfilEmDia\Image\ImageNormalizerInterface;
use PerfilEmDia\Image\OpenRouterImageEditor;
use PerfilEmDia\Image\PhotoMark;
use PerfilEmDia\Image\PhotoPhrase;
use PerfilEmDia\Image\VideoFrame;
use PerfilEmDia\Instagram\InstagramApiException;
use PerfilEmDia\Instagram\InstagramClient;
use PerfilEmDia\Instagram\InstagramPublisherInterface;
use PerfilEmDia\Logger;
use PerfilEmDia\Messages;
use PerfilEmDia\Telegram\Keyboards;
use RuntimeException;

class PostService
{
    private const VIDEO_MIN_SECONDS = 3;
    private const VIDEO_MAX_SECONDS = 90;
    private const VIDEO_MAX_BYTES = 20971520;

    public function __construct(
        private readonly UserRepository $users,
        private readonly PostRepository $posts,
        private readonly ChannelInterface $channel,
        private readonly ImageNormalizerInterface $normalizer,
        private readonly CaptionGeneratorInterface $captions,
        private readonly InstagramPublisherInterface $publisher,
        private readonly ?PlanAccess $access = null,
        private readonly ?ImageEditorInterface $images = null,
        private readonly ?IdeaImageGenerator $ideas = null,
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

        $userPending = (string) ($user['pending_action'] ?? '');
        if (str_starts_with($userPending, 'edit:')) {
            $this->users->update((int) $user['id'], ['pending_action' => null]);
            $user['pending_action'] = null;
            $userPending = '';
        }

        $pending = $this->posts->findPendingForUser((int) $user['id']);
        if ($pending !== null && !str_starts_with($userPending, 'newpost:')) {
            $block = $this->incomingBlock($user, $message);
            if ($block !== null) {
                $this->channel->sendText($chatId, $block);

                return;
            }
            $draftId = $this->createDraftFromMessage($user, $message);
            $this->users->update((int) $user['id'], [
                'pending_action' => 'newpost:' . $draftId,
            ]);
            $this->channel->sendText($chatId, Messages::replacePending(), Keyboards::yesNoPending());

            return;
        }

        $kind = $this->selectedKind($user);
        if ($kind === null) {
            $this->askPostKind($chatId);

            return;
        }

        if ($kind === 'ia') {
            $idea = $this->extractTheme($message);
            if ($idea === null || $idea === '') {
                $this->channel->sendText($chatId, Messages::kindIaNeedText());

                return;
            }
            $this->startIdea($user, $chatId, $idea, $message);

            return;
        }
        $block = $this->incomingBlock($user, $message);
        if ($block !== null) {
            $this->channel->sendText($chatId, $block);

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
                $this->discardPublicMedia($draftId);
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
                if ($this->posts->transition((int) $old['id'], $from, PostStatus::Cancelled)) {
                    $this->discardPublicMedia((int) $old['id']);
                }
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
            if (!$this->posts->transition($postId, PostStatus::AwaitingFeedback, PostStatus::Generating)) {
                return true;
            }
            $this->posts->update($postId, [
                'last_feedback' => mb_substr($feedback, 0, 1000),
                'regen_count' => ((int) $pending['regen_count']) + 1,
            ]);
            $this->generateAndPreview((int) $user['id'], $chatId, $postId);

            return true;
        }

        if ($status === PostStatus::AwaitingManualEdit) {
            $caption = trim(strip_tags($text));
            $this->posts->update($postId, [
                'caption' => $caption,
                'caption_version' => ((int) $pending['caption_version']) + 1,
            ]);
            if (!$this->returnToMenu($postId, PostStatus::AwaitingManualEdit)) {
                return true;
            }
            $this->sendPreview($user, $chatId, $postId);

            return true;
        }

        if ($status === PostStatus::AwaitingImageEdit) {
            $this->editPhoto($user, $chatId, $pending, $text);

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
        $post = $this->ensureMenu($post);
        if ($post === null) {
            $this->channel->sendText($chatId, Messages::alreadyProcessed());

            return;
        }

        match ($action) {
            'pub' => $this->publish($user, $chatId, $post, 'feed'),
            'sty' => $this->publish($user, $chatId, $post, 'story'),
            'sch' => $this->askSchedule($user, $chatId, $post),
            'uns' => $this->unschedule($user, $chatId, $post),
            'txt' => $this->askPhotoPhrase($user, $chatId, $post),
            'wm' => $this->askMark($user, $chatId, $post),
            'img' => $this->askPhotoEdit($user, $chatId, $post),
            'can' => (string) $post['status'] === PostStatus::Scheduled->value
                ? $this->unschedule($user, $chatId, $post)
                : $this->handleApprovalAction($user, $chatId, $action, $post),
            'pic' => $this->regenIdea($user, $chatId, $post),
            'adj', 'reg', 'man' => $this->handleApprovalAction($user, $chatId, $action, $post),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $post
     */
    private function handleApprovalAction(array $user, int $chatId, string $action, array $post): void
    {
        if (!$this->inMenu($post)) {
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
        $this->discardPublicMedia((int) $pending['id']);
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
        $this->users->update((int) $user['id'], ['pending_action' => null]);
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

        return $this->subscriptionAllows($user, $chatId);
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
     * @param array<string, mixed> $user
     */
    private function subscriptionAllows(array $user, int $chatId): bool
    {
        $window = $this->access?->window((int) $user['id']);
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
            $this->rememberMedia($postId, 0, $file, (int) ($message['message_id'] ?? 0));
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
        $this->users->update((int) $user['id'], ['pending_action' => null]);
        $postId = $this->posts->create((int) $user['id'], $status, $theme);
        $this->rememberMedia($postId, 0, $file, (int) ($message['message_id'] ?? 0));

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
            $this->rememberMedia($postId, 0, $file, $messageId);
            $this->waitForAlbum();
            $this->tryFinalizeAlbum($postId, $messageId);

            return;
        }

        $postId = (int) $existing['id'];
        if ((string) $existing['status'] !== PostStatus::Collecting->value) {
            return;
        }

        if ($theme !== null && $theme !== '') {
            $this->posts->update($postId, ['theme_text' => $theme]);
        }

        if ($this->posts->addMediaIfRoom($postId, $file['file_id'], $messageId, 10) === null) {
            $this->channel->sendText($chatId, Messages::carouselOverflow());

            return;
        }
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
        $this->users->update((int) $user['id'], ['pending_action' => null]);
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
            $isVideo = ($mediaRows[0]['kind'] ?? 'image') === 'video';
            if ($isVideo) {
                $jpegPaths = $this->stageVideo($postId, $mediaRows[0]);
            } else {
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
            }
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
            $theme = (string) ($post['theme_text'] ?? '');
            if ((int) ($post['creative'] ?? 0) === 1) {
                $theme = "[[criacao]]\n" . $theme;
            }
            if ($isVideo && $jpegPaths === []) {
                $theme .= "\nIsto é um vídeo curto. Escreva a legenda só com o que a pessoa contou, sem inventar o que aparece.";
            }
            $result = $this->captions->generate(
                $profile,
                $theme,
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

            if (!$this->returnToMenu($postId, PostStatus::Generating)) {
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
            $this->channel->sendText($chatId, Messages::captionFailed());
        } catch (\Throwable $e) {
            if ($e::class === 'PerfilEmDia\\Image\\ImageUnsupportedException') {
                $this->failPost($postId, PostStatus::Generating, 'image_unsupported', $e->getMessage());
                $this->channel->sendText($chatId, Messages::heicUnsupported());

                return;
            }
            Logger::get()->error('Geracao falhou', ['post_id' => $postId, 'error' => $e->getMessage()]);
            $this->failPost($postId, PostStatus::Generating, 'generate_error', $e->getMessage());
            $this->channel->sendText($chatId, Messages::captionFailed());
        }
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $post
     */
    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $post
     */
    private function askSchedule(array $user, int $chatId, array $post): void
    {
        if (!$this->inMenu($post)) {
            $this->channel->sendText($chatId, Messages::alreadyProcessed());

            return;
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
        $evening = $now->setTime(18, 0) >= $now->modify('+2 minutes');
        $this->channel->sendText($chatId, Messages::askSchedule(), Keyboards::scheduleChoices((int) $post['id'], $evening));
    }

    /**
     * @param array<string, mixed> $user
     */
    public function chooseSchedule(array $user, int $chatId, string $callbackId, string $choice, int $postId): void
    {
        $this->channel->answerCallback($callbackId);
        $post = $this->posts->find($postId);
        if ($post === null || (int) $post['user_id'] !== (int) $user['id']) {
            return;
        }
        if (!$this->inMenu($post)) {
            $this->channel->sendText($chatId, Messages::alreadyProcessed());

            return;
        }
        if ($choice === 'in') {
            $this->users->update((int) $user['id'], ['pending_action' => 'sched:' . $postId]);
            $this->channel->sendText($chatId, Messages::askScheduleText());

            return;
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
        $at = match ($choice) {
            't18' => $now->setTime(18, 0),
            'n9' => $now->modify('+1 day')->setTime(9, 0),
            'n18' => $now->modify('+1 day')->setTime(18, 0),
            default => null,
        };
        if ($at === null) {
            return;
        }
        $this->applyScheduleResult($user, $chatId, $post, ScheduleTime::accept($at, $now));
    }

    /**
     * @param array<string, mixed> $user
     */
    public function handleScheduleText(array $user, int $chatId, string $text): bool
    {
        $pending = (string) ($user['pending_action'] ?? '');
        if (!str_starts_with($pending, 'sched:')) {
            return false;
        }
        $postId = (int) substr($pending, 6);
        $post = $this->posts->find($postId);
        if ($post === null || (int) $post['user_id'] !== (int) $user['id']) {
            $this->users->update((int) $user['id'], ['pending_action' => null]);

            return true;
        }
        if (!$this->inMenu($post)) {
            $this->users->update((int) $user['id'], ['pending_action' => null]);
            $this->channel->sendText($chatId, Messages::alreadyProcessed());

            return true;
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
        $this->applyScheduleResult($user, $chatId, $post, ScheduleTime::parse($text, $now));

        return true;
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $post
     * @param array{status:string, at:?DateTimeImmutable} $result
     */
    private function applyScheduleResult(array $user, int $chatId, array $post, array $result): void
    {
        $postId = (int) $post['id'];
        if ($result['status'] !== ScheduleTime::OK || $result['at'] === null) {
            $text = match ($result['status']) {
                ScheduleTime::PAST => Messages::schedulePast(),
                ScheduleTime::FAR => Messages::scheduleFar(),
                default => Messages::scheduleInvalid(),
            };
            $now = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
            $evening = $now->setTime(18, 0) >= $now->modify('+2 minutes');
            $this->channel->sendText($chatId, $text, Keyboards::scheduleChoices($postId, $evening));

            return;
        }
        $status = (string) $post['status'];
        if ($status !== PostStatus::Scheduled->value
            && !$this->posts->transition($postId, PostStatus::AwaitingApproval, PostStatus::Scheduled)) {
            $this->channel->sendText($chatId, Messages::alreadyProcessed());

            return;
        }
        $this->posts->update($postId, [
            'scheduled_at' => $result['at']->format('Y-m-d H:i:s'),
        ]);
        $this->users->update((int) $user['id'], ['pending_action' => null]);
        $this->channel->sendText($chatId, Messages::scheduled(ScheduleTime::label($result['at'])), Keyboards::scheduled($postId));
        $this->sendPreview($user, $chatId, $postId);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $post
     */
    private function unschedule(array $user, int $chatId, array $post): void
    {
        $postId = (int) $post['id'];
        if ((string) $post['status'] !== PostStatus::Scheduled->value) {
            $this->channel->sendText($chatId, Messages::alreadyProcessed());

            return;
        }
        if (!$this->posts->transition($postId, PostStatus::Scheduled, PostStatus::AwaitingApproval)) {
            return;
        }
        $this->posts->update($postId, ['scheduled_at' => null]);
        if (str_starts_with((string) ($user['pending_action'] ?? ''), 'sched:')) {
            $this->users->update((int) $user['id'], ['pending_action' => null]);
        }
        $this->channel->sendText($chatId, Messages::scheduleCancelled());
        $this->sendPreview($user, $chatId, $postId);
    }

    public function publishDue(): void
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s');
        foreach ($this->posts->dueScheduled($now) as $post) {
            $user = $this->users->find((int) $post['user_id']);
            if ($user === null || empty($user['telegram_chat_id'])) {
                continue;
            }
            $chatId = (int) $user['telegram_chat_id'];
            $postId = (int) $post['id'];
            $this->publish($user, $chatId, $post);
            $fresh = $this->posts->find($postId);
            if ($fresh !== null && (string) $fresh['status'] === PostStatus::Scheduled->value) {
                $this->posts->transition($postId, PostStatus::Scheduled, PostStatus::AwaitingApproval);
                $this->posts->update($postId, ['scheduled_at' => null]);
                $this->sendPreview($user, $chatId, $postId);
            }
        }
    }

    private function askPhotoPhrase(array $user, int $chatId, array $post): void
    {
        if (!$this->inMenu($post)) {
            $this->channel->sendText($chatId, Messages::alreadyProcessed());

            return;
        }
        if (!$this->photoEditAllowed((int) $user['id'])) {
            $this->channel->sendText($chatId, Messages::photoEditPlan());

            return;
        }
        $this->users->update((int) $user['id'], ['pending_action' => 'phrase:' . (int) $post['id']]);
        $this->channel->sendText($chatId, Messages::askPhotoPhrase());
    }

    /**
     * @param array<string, mixed> $user
     */
    public function handlePhraseText(array $user, int $chatId, string $text): bool
    {
        $pending = (string) ($user['pending_action'] ?? '');
        if (!str_starts_with($pending, 'phrase:')) {
            return false;
        }
        $postId = (int) substr($pending, 7);
        $post = $this->posts->find($postId);
        if ($post === null || (int) $post['user_id'] !== (int) $user['id']) {
            $this->users->update((int) $user['id'], ['pending_action' => null]);

            return true;
        }
        $phrase = trim(strip_tags($text));
        if ($phrase === '') {
            $this->channel->sendText($chatId, Messages::askPhotoPhrase());

            return true;
        }
        $this->applyPhrase($user, $chatId, $post, mb_substr($phrase, 0, 80));

        return true;
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $post
     */
    private function applyPhrase(array $user, int $chatId, array $post, string $phrase): void
    {
        $postId = (int) $post['id'];
        $media = $this->posts->media($postId);
        $first = $media[0] ?? null;
        $publicDir = Config::root() . '/public/m';
        $current = $first !== null && !empty($first['public_name'])
            ? $publicDir . '/' . $first['public_name'] . '.jpg'
            : '';
        if ($first === null || ($first['kind'] ?? 'image') === 'video' || !is_file($current)) {
            $this->users->update((int) $user['id'], ['pending_action' => null]);
            $this->channel->sendText($chatId, Messages::photoEditFailed());

            return;
        }
        $name = bin2hex(random_bytes(20));
        $dest = $publicDir . '/' . $name . '.jpg';
        if (!copy($current, $dest)) {
            $this->channel->sendText($chatId, Messages::photoEditFailed());

            return;
        }
        PhotoPhrase::draw($dest, $phrase);
        $this->posts->updateMedia((int) $first['id'], ['public_name' => $name]);
        $this->freezeEditedPhoto($first, $dest);
        if (is_file($current)) {
            unlink($current);
        }
        $this->users->update((int) $user['id'], ['pending_action' => null]);
        $this->sendPreview($user, $chatId, $postId);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $post
     */
    private function askMark(array $user, int $chatId, array $post): void
    {
        if (!$this->inMenu($post)) {
            $this->channel->sendText($chatId, Messages::alreadyProcessed());

            return;
        }
        if (!$this->photoEditAllowed((int) $user['id'])) {
            $this->channel->sendText($chatId, Messages::markPlan());

            return;
        }
        $media = $this->posts->media((int) $post['id']);
        $first = $media[0] ?? null;
        if ($first === null || ($first['kind'] ?? 'image') === 'video') {
            $this->channel->sendText($chatId, Messages::markNeedsPhoto());

            return;
        }
        $this->channel->sendText($chatId, Messages::askMarkPlace(), Keyboards::markPlace((int) $post['id']));
    }

    /**
     * @param array<string, mixed> $user
     */
    public function placeMark(array $user, int $chatId, string $callbackId, string $place, int $postId): void
    {
        $this->channel->answerCallback($callbackId);
        $post = $this->posts->find($postId);
        if ($post === null || (int) $post['user_id'] !== (int) $user['id']) {
            return;
        }
        if (!$this->inMenu($post)) {
            $this->channel->sendText($chatId, Messages::alreadyProcessed());

            return;
        }
        $ig = $this->users->instagramAccount((int) $user['id']);
        if (!is_array($ig) || ($ig['status'] ?? '') !== 'active' || empty($ig['access_token'])) {
            $this->channel->sendText($chatId, Messages::markNeedsInstagram());

            return;
        }
        $bytes = (new InstagramClient())->profilePicture((string) $ig['access_token']);
        if ($bytes === null) {
            $this->channel->sendText($chatId, Messages::markFailed());

            return;
        }
        $logo = tempnam(sys_get_temp_dir(), 'mk');
        if ($logo === false) {
            $this->channel->sendText($chatId, Messages::markFailed());

            return;
        }
        file_put_contents($logo, $bytes);
        try {
            $this->applyMark($user, $chatId, $post, $logo, $place);
        } finally {
            if (is_file($logo)) {
                unlink($logo);
            }
        }
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $post
     */
    private function applyMark(array $user, int $chatId, array $post, string $logo, string $place): void
    {
        $postId = (int) $post['id'];
        $publicDir = Config::root() . '/public/m';
        $stamped = false;
        foreach ($this->posts->media($postId) as $row) {
            if (($row['kind'] ?? 'image') === 'video' || empty($row['public_name'])) {
                continue;
            }
            $current = $publicDir . '/' . $row['public_name'] . '.jpg';
            if (!is_file($current)) {
                continue;
            }
            $name = bin2hex(random_bytes(20));
            $dest = $publicDir . '/' . $name . '.jpg';
            if (!copy($current, $dest) || !PhotoMark::stamp($dest, $logo, $place)) {
                if (is_file($dest)) {
                    unlink($dest);
                }
                continue;
            }
            $this->posts->updateMedia((int) $row['id'], ['public_name' => $name]);
            $this->freezeEditedPhoto($row, $dest);
            if (is_file($current)) {
                unlink($current);
            }
            $stamped = true;
        }
        if (!$stamped) {
            $this->channel->sendText($chatId, Messages::markFailed());

            return;
        }
        $this->sendPreview($user, $chatId, $postId);
    }

    private function askPhotoEdit(array $user, int $chatId, array $post): void
    {
        if (!$this->inMenu($post)) {
            $this->channel->sendText($chatId, Messages::alreadyProcessed());

            return;
        }
        if (!$this->photoEditAllowed((int) $user['id'])) {
            $this->channel->sendText($chatId, Messages::photoEditPlan());

            return;
        }
        if ((int) ($post['image_edit_count'] ?? 0) >= $this->photoEditLimit()) {
            $this->channel->sendText($chatId, Messages::photoEditLimit());

            return;
        }
        $postId = (int) $post['id'];
        if (!$this->leaveMenu($postId, PostStatus::AwaitingImageEdit)) {
            return;
        }
        $album = count($this->posts->media($postId)) > 1;
        $this->channel->sendText($chatId, Messages::askPhotoEdit($album));
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $post
     */
    private function editPhoto(array $user, int $chatId, array $post, string $text): void
    {
        $postId = (int) $post['id'];
        $request = ImageEditRequest::parse($text);
        if ($request->treatment === '' && $request->phrase === null) {
            $this->channel->sendText($chatId, Messages::askPhotoEdit(count($this->posts->media($postId)) > 1));

            return;
        }
        if ($request->treatment === '' && $request->phrase !== null) {
            if (!$this->returnToMenu($postId, PostStatus::AwaitingImageEdit)) {
                return;
            }
            $fresh = $this->posts->find($postId);
            if (is_array($fresh)) {
                $this->applyPhrase($user, $chatId, $fresh, $request->phrase);
            }

            return;
        }
        if (!$this->posts->transition($postId, PostStatus::AwaitingImageEdit, PostStatus::ImageEditing)) {
            return;
        }
        $this->channel->sendText($chatId, Messages::photoEditing());
        $temp = null;
        try {
            $media = $this->posts->media($postId);
            $first = $media[0] ?? null;
            if ($first === null || empty($first['public_name'])) {
                throw new ImageEditException('Post has no public photo');
            }
            $current = Config::root() . '/public/m/' . $first['public_name'] . '.jpg';
            $source = $current;
            if ($request->treatment !== '') {
                $binary = $this->editor()->edit($current, $request->treatment);
                $temp = tempnam(sys_get_temp_dir(), 'pd');
                if ($temp === false) {
                    throw new ImageEditException('Cannot store edited photo');
                }
                file_put_contents($temp, $binary);
                $source = $temp;
            }
            $publicDir = Config::root() . '/public/m';
            $normalized = $this->normalizer->normalize([$source], $publicDir);
            $image = $normalized[0] ?? null;
            if ($image === null) {
                throw new ImageEditException('Normalizer returned no photo');
            }
            if ($request->phrase !== null) {
                PhotoPhrase::draw($image->absolutePath, $request->phrase);
            }
            $oldName = (string) $first['public_name'];
            if ($oldName !== $image->publicName) {
                $oldPath = $publicDir . '/' . $oldName . '.jpg';
                if (is_file($oldPath)) {
                    unlink($oldPath);
                }
            }
            $this->posts->updateMedia((int) $first['id'], [
                'public_name' => $image->publicName,
                'width' => $image->width,
                'height' => $image->height,
            ]);
            $this->freezeEditedPhoto($first, $image->absolutePath);
            $this->posts->update($postId, [
                'image_edit_count' => ((int) ($post['image_edit_count'] ?? 0)) + 1,
            ]);
            if (!$this->returnToMenu($postId, PostStatus::ImageEditing)) {
                return;
            }
            $this->posts->recordEvent((int) $user['id'], $postId, 'photo_edit', [
                'phrase' => $request->phrase !== null,
                'treatment' => $request->treatment !== '',
            ]);
            $this->sendPreview($user, $chatId, $postId);
        } catch (\Throwable $e) {
            Logger::get()->error('Tratamento de foto falhou', ['post_id' => $postId, 'error' => $e->getMessage()]);
            $this->posts->transition($postId, PostStatus::ImageEditing, PostStatus::AwaitingImageEdit);
            $this->channel->sendText($chatId, Messages::photoEditFailed());
        } finally {
            if (is_string($temp) && is_file($temp)) {
                unlink($temp);
            }
        }
    }

    /**
     * @param array<string, mixed> $user
     */
    public function abandonImageEdit(int $postId): void
    {
        $post = $this->posts->find($postId);
        if ($post === null || (string) $post['status'] !== PostStatus::ImageEditing->value) {
            return;
        }
        if (!$this->returnToMenu($postId, PostStatus::ImageEditing)) {
            return;
        }
        $user = $this->users->find((int) $post['user_id']);
        if ($user === null || empty($user['telegram_chat_id'])) {
            return;
        }
        $chatId = (int) $user['telegram_chat_id'];
        $this->channel->sendText($chatId, Messages::photoEditFailed());
        $this->sendPreview($user, $chatId, $postId);
    }

    /**
     * @param array<string, mixed> $post
     */
    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>|null
     */
    private function ensureMenu(array $post): ?array
    {
        if ($this->inMenu($post)) {
            return $post;
        }
        $status = (string) ($post['status'] ?? '');
        $waiting = [
            PostStatus::AwaitingFeedback->value => PostStatus::AwaitingFeedback,
            PostStatus::AwaitingManualEdit->value => PostStatus::AwaitingManualEdit,
            PostStatus::AwaitingImageEdit->value => PostStatus::AwaitingImageEdit,
        ];
        if (!isset($waiting[$status])) {
            return null;
        }
        $postId = (int) $post['id'];
        if (!$this->returnToMenu($postId, $waiting[$status])) {
            $fresh = $this->posts->find($postId);

            return is_array($fresh) && $this->inMenu($fresh) ? $fresh : null;
        }

        return $this->posts->find($postId);
    }

    /**
     * @param array<string, mixed> $post
     */
    private function inMenu(array $post): bool
    {
        $status = (string) ($post['status'] ?? '');

        return $status === PostStatus::AwaitingApproval->value
            || $status === PostStatus::Scheduled->value;
    }

    private function leaveMenu(int $postId, PostStatus $to): bool
    {
        if ($this->posts->transition($postId, PostStatus::AwaitingApproval, $to)) {
            return true;
        }

        return $this->posts->transition($postId, PostStatus::Scheduled, $to);
    }

    private function returnToMenu(int $postId, PostStatus $from): bool
    {
        $post = $this->posts->find($postId);
        $to = is_array($post) && !empty($post['scheduled_at'])
            ? PostStatus::Scheduled
            : PostStatus::AwaitingApproval;

        return $this->posts->transition($postId, $from, $to);
    }

    private function photoEditAllowed(int $userId): bool
    {
        return $this->access !== null && $this->access->canEditPhoto($userId);
    }

    private function photoEditLimit(): int
    {
        return max(2, (int) Config::get('IMAGE_EDITS_PER_POST', '2'));
    }

    private function ideaImageLimit(): int
    {
        return max(1, (int) Config::get('IDEA_IMAGE_REGENS', '2'));
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $post
     */
    private function renewIdeaImage(array $user, int $chatId, array $post): bool
    {
        if ((int) ($post['idea_regen_count'] ?? 0) >= $this->ideaImageLimit()) {
            return false;
        }
        $postId = (int) $post['id'];
        $media = $this->posts->media($postId);
        $first = $media[0] ?? null;
        $path = is_array($first) ? (string) ($first['original_path'] ?? '') : '';
        if ($path === '') {
            $path = Config::root() . '/storage/media/' . $postId . '_0.jpg';
        }
        try {
            $jpeg = ($this->ideas ?? new IdeaImage())->create(
                (string) ($post['theme_text'] ?? ''),
                is_file($path) ? $path : null,
                BenefitOrchestrator::brandBrief($user),
            );
            $dir = dirname($path);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                return false;
            }
            if (file_put_contents($path, $jpeg) === false) {
                return false;
            }
            if (is_array($first) && (string) ($first['original_path'] ?? '') === '') {
                $this->posts->updateMedia((int) $first['id'], ['original_path' => $path]);
            }

            return true;
        } catch (\Throwable $e) {
            Logger::get()->error('Nova foto da ideia falhou', ['post_id' => $postId, 'error' => $e->getMessage()]);
            $this->channel->sendText($chatId, Messages::ideaImageRetryFailed());

            return false;
        }
    }

    private function editor(): ImageEditorInterface
    {
        return $this->images ?? new OpenRouterImageEditor();
    }

    private function captionText(string $caption): string
    {
        $caption = str_replace(["\\r\\n", "\\n", "\\r"], "\n", $caption);

        return \PerfilEmDia\Ai\CaptionGenerator::dedupeHashtagBlocks($caption);
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
        $isVideo = ($first['kind'] ?? 'image') === 'video';
        $path = Config::root() . '/public/m/' . $first['public_name'] . ($isVideo ? '.mp4' : '.jpg');
        $caption = $this->captionText((string) ($post['caption'] ?? ''));
        $limit = (int) Config::get('LIMIT_REGENERATIONS_PER_POST', '5');
        $allowRegen = ((int) $post['regen_count']) < $limit;
        if (!$allowRegen) {
            $this->channel->sendText($chatId, Messages::regenLimit());
        }
        $usedEdits = (int) ($post['image_edit_count'] ?? 0);
        $canPhoto = !$isVideo && $this->photoEditAllowed((int) $user['id']);
        $allowTreat = $canPhoto && $usedEdits < $this->photoEditLimit();
        $allowStory = BenefitOrchestrator::storyFits(count($media));
        $allowIdea = (int) ($post['creative'] ?? 0) === 1
            && (int) ($post['idea_regen_count'] ?? 0) < $this->ideaImageLimit();
        $buttons = Keyboards::approval($postId, $allowRegen, $allowTreat, $canPhoto, $allowStory, $allowIdea, $canPhoto);
        if ((string) $post['status'] === PostStatus::Scheduled->value && !empty($post['scheduled_at'])) {
            $at = DateTimeImmutable::createFromFormat(
                'Y-m-d H:i:s',
                (string) $post['scheduled_at'],
                new DateTimeZone('America/Sao_Paulo'),
            );
            if ($at instanceof DateTimeImmutable) {
                $this->channel->sendText($chatId, Messages::stillScheduled(ScheduleTime::label($at)));
            }
        }
        $previewId = $isVideo
            ? $this->channel->sendVideo($chatId, $path, $caption, $buttons)
            : $this->channel->sendPhoto($chatId, $path, $caption, $buttons);
        $this->posts->update($postId, ['preview_message_id' => $previewId]);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $post
     */
    public function resumePublishing(int $postId): void
    {
        $post = $this->posts->find($postId);
        if ($post === null || (string) $post['status'] !== PostStatus::Publishing->value) {
            return;
        }
        $user = $this->users->find((int) $post['user_id']);
        if ($user === null) {
            return;
        }
        $chatId = (int) $user['telegram_chat_id'];
        $previewId = $post['preview_message_id'] !== null ? (int) $post['preview_message_id'] : null;
        $containerId = trim((string) ($post['ig_container_id'] ?? ''));
        $mediaId = trim((string) ($post['ig_media_id'] ?? ''));
        if ($containerId === '' && $mediaId === '') {
            if ($this->posts->transition($postId, PostStatus::Publishing, PostStatus::AwaitingApproval)) {
                $this->channel->sendText($chatId, Messages::publishRetry());
            }

            return;
        }

        $this->finishPublish($user, $chatId, $postId, $previewId);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $post
     */
    /**
     * @param array<string, mixed> $user
     */
    public function showIdea(array $user, int $chatId): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
        $this->users->update((int) $user['id'], ['idea_text' => BenefitOrchestrator::suggestion($user, $now)]);
        $this->channel->sendText($chatId, BenefitOrchestrator::ideaMessage($user, $now), Keyboards::ideaActions());
    }

    /**
     * @param array<string, mixed> $user
     */
    public function setDailyIdeas(array $user, int $chatId, bool $on): void
    {
        $this->users->update((int) $user['id'], ['idea_daily' => $on ? 1 : 0]);
        $this->channel->sendText($chatId, $on ? Messages::ideaDailyOn() : Messages::ideaDailyOff());
    }

    /**
     * @param array<string, mixed> $user
     */
    public function createFromStoredIdea(array $user, int $chatId): void
    {
        $fresh = $this->users->find((int) $user['id']) ?? $user;
        $idea = trim((string) ($fresh['idea_text'] ?? ''));
        if ($idea === '') {
            $this->showIdea($fresh, $chatId);

            return;
        }
        $this->startIdea($fresh, $chatId, $idea, null);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function report(array $user, int $chatId): void
    {
        $ig = $this->users->instagramAccount((int) $user['id']);
        if ($ig === null) {
            $this->channel->sendText($chatId, Messages::needInstagram());

            return;
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
        try {
            $payload = (new InstagramClient())->accountInsights(
                (string) $ig['ig_user_id'],
                (string) $ig['access_token'],
                $now->modify('-7 days')->getTimestamp(),
                $now->getTimestamp(),
            );
            $this->channel->sendText($chatId, BenefitOrchestrator::formatInsights($payload));
        } catch (InstagramApiException $e) {
            $denied = in_array($e->errorCode, [10, 200], true);
            $this->channel->sendText($chatId, $denied ? Messages::reportDenied() : Messages::reportFailed());
        }
    }

    public function sendDailyIdeas(): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
        foreach ($this->users->dueDailyIdeas($now->format('Y-m-d')) as $user) {
            $chatId = (int) ($user['telegram_chat_id'] ?? 0);
            if ($chatId === 0) {
                continue;
            }
            $this->users->update((int) $user['id'], [
                'idea_text' => BenefitOrchestrator::suggestion($user, $now),
                'idea_sent_on' => $now->format('Y-m-d'),
            ]);
            $this->channel->sendText($chatId, BenefitOrchestrator::ideaMessage($user, $now), Keyboards::ideaActions());
        }
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $post
     */
    private function publish(array $user, int $chatId, array $post, string $destination = 'feed'): void
    {
        $destination = $destination === 'story' ? 'story' : 'feed';
        if ($destination === 'story' && !BenefitOrchestrator::storyFits(count($this->posts->media((int) $post['id'])))) {
            $this->channel->sendText($chatId, Messages::storyNeedsOne());

            return;
        }
        $this->posts->update((int) $post['id'], ['destination' => $destination]);
        $pending = (string) ($user['pending_action'] ?? '');
        if (str_starts_with($pending, 'phrase:') || str_starts_with($pending, 'sched:')) {
            $this->users->update((int) $user['id'], ['pending_action' => null]);
        }
        $postId = (int) $post['id'];
        if (!$this->subscriptionAllows($user, $chatId)) {
            return;
        }
        $status = (string) $post['status'];
        $from = match ($status) {
            PostStatus::AwaitingApproval->value => PostStatus::AwaitingApproval,
            PostStatus::Scheduled->value => PostStatus::Scheduled,
            default => null,
        };
        if ($from === null) {
            $this->channel->sendText($chatId, Messages::alreadyProcessed());

            return;
        }
        if ($from === PostStatus::Scheduled) {
            $this->posts->update($postId, ['scheduled_at' => null]);
        }
        if (!$this->posts->transition($postId, $from, PostStatus::Publishing)) {
            $this->channel->sendText($chatId, Messages::alreadyProcessed());

            return;
        }

        $previewId = $post['preview_message_id'] !== null ? (int) $post['preview_message_id'] : null;
        if ($previewId !== null) {
            $this->channel->editButtons($chatId, $previewId, null);
        }
        $this->channel->sendText($chatId, Messages::publishing());
        $this->finishPublish($user, $chatId, $postId, $previewId);
    }

    /**
     * @param array<string, mixed> $user
     */
    private function finishPublish(array $user, int $chatId, int $postId, ?int $previewId): void
    {
        $post = $this->posts->find($postId);
        if ($post === null) {
            return;
        }

        $ig = $this->users->instagramAccount((int) $user['id']);
        if ($ig === null) {
            $this->posts->transition($postId, PostStatus::Publishing, PostStatus::AwaitingApproval);
            $this->channel->sendText($chatId, Messages::needInstagram());

            return;
        }

        $media = $this->posts->media($postId);
        $appUrl = rtrim(Config::get('APP_URL'), '/');
        $urls = [];
        $isVideo = false;
        foreach ($media as $row) {
            if (empty($row['public_name'])) {
                continue;
            }
            if (($row['kind'] ?? 'image') === 'video') {
                $isVideo = true;
            }
            $ext = (($row['kind'] ?? 'image') === 'video') ? 'mp4' : 'jpg';
            $urls[] = $appUrl . '/m/' . $row['public_name'] . '.' . $ext;
        }

        $attempt = 0;
        while ($attempt < 3) {
            $attempt++;
            $current = $this->posts->find($postId) ?? $post;
            $containerId = trim((string) ($current['ig_container_id'] ?? ''));
            $mediaId = trim((string) ($current['ig_media_id'] ?? ''));
            try {
                $caption = $this->captionText((string) ($current['caption'] ?? ''));
                $asStory = (string) ($current['destination'] ?? 'feed') === 'story' && count($urls) === 1;
                $published = $asStory
                    ? $this->publisher->publishStory(
                        (string) $ig['ig_user_id'],
                        (string) $ig['access_token'],
                        $urls[0] ?? '',
                        $isVideo,
                        $containerId !== '' ? $containerId : null,
                        $mediaId !== '' ? $mediaId : null,
                        function (string $field, string $value) use ($postId): void {
                            if ($field === 'container') {
                                $this->posts->update($postId, ['ig_container_id' => $value]);
                            }
                            if ($field === 'media') {
                                $this->posts->update($postId, ['ig_media_id' => $value]);
                            }
                        },
                    )
                    : ($isVideo
                    ? $this->publisher->publishReel(
                        (string) $ig['ig_user_id'],
                        (string) $ig['access_token'],
                        $urls[0] ?? '',
                        $caption,
                        $containerId !== '' ? $containerId : null,
                        $mediaId !== '' ? $mediaId : null,
                        function (string $field, string $value) use ($postId): void {
                            if ($field === 'container') {
                                $this->posts->update($postId, ['ig_container_id' => $value]);
                            }
                            if ($field === 'media') {
                                $this->posts->update($postId, ['ig_media_id' => $value]);
                            }
                        },
                    )
                    : $this->publisher->publish(
                    (string) $ig['ig_user_id'],
                    (string) $ig['access_token'],
                    $urls,
                    $caption,
                    $current['alt_text'] !== null ? $this->captionText((string) $current['alt_text']) : null,
                    $containerId !== '' ? $containerId : null,
                    $mediaId !== '' ? $mediaId : null,
                    function (string $field, string $value) use ($postId): void {
                        if ($field === 'container') {
                            $this->posts->update($postId, ['ig_container_id' => $value]);
                        }
                        if ($field === 'media') {
                            $this->posts->update($postId, ['ig_media_id' => $value]);
                        }
                    },
                    )
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
                $this->channel->sendText(
                    $chatId,
                    Messages::published(),
                    Keyboards::openUrl('Ver no Instagram', $published->permalink),
                );

                return;
            } catch (InstagramApiException $e) {
                $this->handlePublishError($user, $chatId, $postId, $previewId, $e, $attempt);
                $retry = $e->kind === 'network' || ($e->kind === 'not_ready' && !$this->postIsVideo($postId));
                if (!$retry || $attempt >= 3) {
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
        if ($e->kind === 'not_ready' && $this->postIsVideo($postId)) {
            $current = $this->posts->find($postId);
            $tries = (int) ($current['attempts'] ?? 0) + 1;
            $this->posts->update($postId, [
                'error_code' => 'not_ready',
                'error_message' => $e->getMessage(),
                'attempts' => $tries,
            ]);
            if ($tries === 1) {
                $this->channel->sendText($chatId, Messages::videoProcessing());
            }
            if ($tries >= 5) {
                $this->posts->transition($postId, PostStatus::Publishing, PostStatus::AwaitingApproval);
                $this->channel->sendText($chatId, Messages::publishRetry());
                $this->sendPreview($user, $chatId, $postId);
            }

            return;
        }

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

        if ($e->kind === 'not_ready' && !$this->postIsVideo($postId)) {
            $this->posts->update($postId, ['ig_container_id' => null, 'ig_media_id' => null]);
            if ($attempt < 3) {
                return;
            }
            $this->offerPublishRetry($chatId, $postId, $e);

            return;
        }

        if ($e->kind === 'rate_limit') {
            $this->posts->transition($postId, PostStatus::Publishing, PostStatus::AwaitingApproval);
            $this->channel->sendText($chatId, Messages::rateLimited(), Keyboards::publishRetry($postId));

            return;
        }

        if ($e->kind === 'media_invalid') {
            Logger::get()->error('Media invalida no Instagram', [
                'post_id' => $postId,
                'code' => $e->errorCode,
                'subcode' => $e->subcode,
            ]);
            $this->offerPublishRetry($chatId, $postId, $e);

            return;
        }

        if ($e->kind === 'network') {
            if ($attempt < 3) {
                return;
            }
            $this->offerPublishRetry($chatId, $postId, $e);

            return;
        }

        $this->offerPublishRetry($chatId, $postId, $e);
    }

    private function offerPublishRetry(int $chatId, int $postId, InstagramApiException $e): void
    {
        $this->posts->update($postId, ['ig_container_id' => null, 'ig_media_id' => null]);
        $this->posts->transition($postId, PostStatus::Publishing, PostStatus::AwaitingApproval);
        $this->channel->sendText($chatId, Messages::publishFailed($this->publishReason($e)), Keyboards::publishRetry($postId));
    }

    private function publishReason(InstagramApiException $e): string
    {
        if ($e->errorCode === 9007 || $e->kind === 'not_ready') {
            return Messages::reasonMediaNotReady();
        }
        if ($e->kind === 'rate_limit') {
            return Messages::reasonRateLimit();
        }
        if ($e->kind === 'network') {
            return Messages::reasonNetwork();
        }
        if ($e->kind === 'media_invalid') {
            return Messages::reasonMediaRejected();
        }
        $detail = trim($e->getMessage());

        return $detail !== '' ? Messages::reasonInstagram($detail) : Messages::reasonUnknown();
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
        if (!$this->leaveMenu((int) $post['id'], PostStatus::AwaitingFeedback)) {
            $this->channel->sendText($chatId, Messages::alreadyProcessed());

            return;
        }
        $this->channel->sendText($chatId, Messages::askFeedback());
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $post
     */
    private function regenIdea(array $user, int $chatId, array $post): void
    {
        if ((int) ($post['creative'] ?? 0) !== 1) {
            return;
        }
        if (!$this->aiAllowed((int) $user['id'])) {
            $this->channel->sendText($chatId, Messages::aiPlan());

            return;
        }
        if ((int) ($post['idea_regen_count'] ?? 0) >= $this->ideaImageLimit()) {
            $this->channel->sendText($chatId, Messages::ideaImageLimit());

            return;
        }
        $postId = (int) $post['id'];
        if (!$this->leaveMenu($postId, PostStatus::Generating)) {
            $this->channel->sendText($chatId, Messages::alreadyProcessed());

            return;
        }
        if (!$this->renewIdeaImage($user, $chatId, $post)) {
            $this->returnToMenu($postId, PostStatus::Generating);
            $this->sendPreview($user, $chatId, $postId);

            return;
        }
        $this->posts->update($postId, [
            'idea_regen_count' => ((int) ($post['idea_regen_count'] ?? 0)) + 1,
            'last_feedback' => null,
        ]);
        $this->generateAndPreview((int) $user['id'], $chatId, $postId);
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
        if (!$this->leaveMenu((int) $post['id'], PostStatus::Generating)) {
            $this->channel->sendText($chatId, Messages::alreadyProcessed());

            return;
        }
        $this->posts->update((int) $post['id'], [
            'last_feedback' => null,
            'regen_count' => ((int) $post['regen_count']) + 1,
        ]);
        $this->generateAndPreview((int) $user['id'], $chatId, (int) $post['id']);
    }

    /**
     * @param array<string, mixed> $post
     */
    private function askManual(int $chatId, array $post): void
    {
        if (!$this->leaveMenu((int) $post['id'], PostStatus::AwaitingManualEdit)) {
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
        $this->discardPublicMedia((int) $post['id']);
        $this->channel->sendText($chatId, Messages::cancelled());
    }

    private function failPost(int $postId, PostStatus $from, string $code, string $message): void
    {
        $this->posts->transition($postId, $from, PostStatus::Failed);
        $this->posts->update($postId, [
            'error_code' => $code,
            'error_message' => $message,
        ]);
        $this->discardPublicMedia($postId);
    }

    private function discardPublicMedia(int $postId): void
    {
        foreach ($this->posts->media($postId) as $row) {
            if (!empty($row['public_name'])) {
                foreach (['.jpg', '.mp4'] as $ext) {
                    $public = Config::root() . '/public/m/' . $row['public_name'] . $ext;
                    if (is_file($public)) {
                        unlink($public);
                    }
                }
            }
            $original = (string) ($row['original_path'] ?? '');
            if ($original !== '' && is_file($original)) {
                unlink($original);
            }
        }
    }

    /**
     * @param array<string, mixed>|null $user
     */
    public function askPostKind(int $chatId, ?array $user = null): void
    {
        $pending = is_array($user) ? (string) ($user['pending_action'] ?? '') : '';
        if (str_starts_with($pending, 'kind:') || str_starts_with($pending, 'phrase:') || str_starts_with($pending, 'sched:')) {
            $this->users->update((int) $user['id'], ['pending_action' => null]);
        }
        $this->channel->sendText($chatId, Messages::askPostKind(), Keyboards::postKind());
    }

    /**
     * @param array<string, mixed> $user
     */
    public function choosePostKind(array $user, int $chatId, string $kind): void
    {
        if (!in_array($kind, ['foto', 'album', 'video', 'ia'], true)) {
            $this->askPostKind($chatId);

            return;
        }
        if ($kind === 'video' && !$this->videoAllowed((int) $user['id'])) {
            $this->channel->sendText($chatId, Messages::videoPlan());

            return;
        }
        if ($kind === 'ia' && !$this->aiAllowed((int) $user['id'])) {
            $this->channel->sendText($chatId, Messages::aiPlan());

            return;
        }
        $this->users->update((int) $user['id'], ['pending_action' => 'kind:' . $kind]);
        $text = match ($kind) {
            'album' => Messages::kindAlbum(),
            'video' => Messages::kindVideo(),
            'ia' => Messages::kindIa(),
            default => Messages::kindFoto(),
        };
        $this->channel->sendText($chatId, $text);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function handleIdeaText(array $user, int $chatId, string $text): bool
    {
        if ($this->selectedKind($user) !== 'ia') {
            return false;
        }
        $idea = trim(strip_tags($text));
        if ($idea === '') {
            $this->channel->sendText($chatId, Messages::kindIaNeedText());

            return true;
        }
        $this->startIdea($user, $chatId, mb_substr($idea, 0, 1000), null);

        return true;
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed>|null $message
     */
    private function startIdea(array $user, int $chatId, string $idea, ?array $message): void
    {
        if (!$this->aiAllowed((int) $user['id'])) {
            $this->channel->sendText($chatId, Messages::aiPlan());

            return;
        }
        $this->users->update((int) $user['id'], ['pending_action' => null]);
        $this->channel->sendText($chatId, Messages::received());
        $postId = $this->posts->create((int) $user['id'], PostStatus::Generating, $idea);
        $this->posts->update($postId, ['creative' => 1]);

        $reference = null;
        if ($message !== null) {
            $file = $this->extractFile($message);
            if ($file !== null && $file['kind'] === 'image') {
                $reference = Config::root() . '/storage/media/' . $postId . '_ref.jpg';
                $dir = dirname($reference);
                if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                    $reference = null;
                } else {
                    try {
                        $this->channel->download($file['file_id'], $reference);
                    } catch (\Throwable) {
                        $reference = null;
                    }
                }
            }
        }

        try {
            $jpeg = ($this->ideas ?? new IdeaImage())->create($idea, $reference, BenefitOrchestrator::brandBrief($user));
            $dest = Config::root() . '/storage/media/' . $postId . '_0.jpg';
            $dir = dirname($dest);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException('Nao foi possivel criar storage/media');
            }
            if (file_put_contents($dest, $jpeg) === false) {
                throw new RuntimeException('Nao foi possivel gravar a imagem');
            }
            $mediaId = $this->posts->addMedia($postId, 0, 'idea', (int) ($message['message_id'] ?? 0));
            $this->posts->updateMedia($mediaId, ['original_path' => $dest]);
            $this->generateAndPreview((int) $user['id'], $chatId, $postId);
        } catch (\Throwable $e) {
            Logger::get()->error('Imagem da ideia falhou', ['post_id' => $postId, 'error' => $e->getMessage()]);
            $this->failPost($postId, PostStatus::Generating, 'idea_image', $e->getMessage());
            $this->channel->sendText($chatId, Messages::ideaFailed());
        } finally {
            if (is_string($reference) && is_file($reference)) {
                unlink($reference);
            }
        }
    }

    /**
     * @param array<string, mixed> $user
     */
    private function selectedKind(array $user): ?string
    {
        $pending = (string) ($user['pending_action'] ?? '');
        if (!str_starts_with($pending, 'kind:')) {
            return null;
        }
        $kind = substr($pending, 5);

        return in_array($kind, ['foto', 'album', 'video', 'ia'], true) ? $kind : null;
    }

    private function aiAllowed(int $userId): bool
    {
        return $this->access !== null && $this->access->canCreateWithAi($userId);
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
     * @return array{file_id:string,kind:string,duration:int,bytes:int,width:int,height:int}|null
     */
    private function extractFile(array $message): ?array
    {
        if (!empty($message['photo']) && is_array($message['photo'])) {
            $photos = $message['photo'];
            $last = $photos[array_key_last($photos)];
            if (is_array($last) && !empty($last['file_id'])) {
                return $this->mediaFile((string) $last['file_id'], 'image', $last);
            }
        }

        if (!empty($message['video']) && is_array($message['video']) && !empty($message['video']['file_id'])) {
            return $this->mediaFile((string) $message['video']['file_id'], 'video', $message['video']);
        }

        if (!empty($message['video_note']) && is_array($message['video_note']) && !empty($message['video_note']['file_id'])) {
            return $this->mediaFile((string) $message['video_note']['file_id'], 'video', $message['video_note']);
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
                return $this->mediaFile((string) $message['document']['file_id'], 'image', $message['document']);
            }
            if (str_starts_with($mime, 'video/') && !empty($message['document']['file_id'])) {
                return $this->mediaFile((string) $message['document']['file_id'], 'video', $message['document']);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $node
     * @return array{file_id:string,kind:string,duration:int,bytes:int,width:int,height:int}
     */
    private function mediaFile(string $fileId, string $kind, array $node): array
    {
        return [
            'file_id' => $fileId,
            'kind' => $kind === 'video' ? 'video' : 'image',
            'duration' => (int) ($node['duration'] ?? 0),
            'bytes' => (int) ($node['file_size'] ?? 0),
            'width' => (int) ($node['width'] ?? 0),
            'height' => (int) ($node['height'] ?? 0),
        ];
    }

    /**
     * @param array{file_id:string,kind:string,duration:int,bytes:int,width:int,height:int} $file
     */
    private function rememberMedia(int $postId, int $position, array $file, int $messageId): void
    {
        $mediaId = $this->posts->addMedia($postId, $position, $file['file_id'], $messageId, $file['kind']);
        $fields = [];
        if ($file['width'] > 0) {
            $fields['width'] = $file['width'];
        }
        if ($file['height'] > 0) {
            $fields['height'] = $file['height'];
        }
        if ($fields !== []) {
            $this->posts->updateMedia($mediaId, $fields);
        }
    }

    /**
     * @param array<string, mixed> $user
     * @param array{file_id:string,kind:string,duration:int,bytes:int,width:int,height:int} $file
     */
    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $message
     */
    private function incomingBlock(array $user, array $message): ?string
    {
        $kind = $this->selectedKind($user);
        $incoming = $this->extractFile($message);
        if ($incoming !== null && ($incoming['kind'] ?? 'image') === 'video' && $kind !== 'video') {
            return Messages::kindNotVideo();
        }
        if ($kind === 'video') {
            if (isset($message['media_group_id']) || $incoming === null || ($incoming['kind'] ?? '') !== 'video') {
                return Messages::kindVideo();
            }

            return $this->videoBlockReason($user, $incoming);
        }
        if ($kind === 'foto' && isset($message['media_group_id'])) {
            return Messages::kindUseAlbum();
        }
        if ($kind === 'album' && !isset($message['media_group_id'])) {
            return Messages::kindAlbum();
        }

        return null;
    }

    /**
     * @param array<string, mixed> $media
     */
    private function freezeEditedPhoto(array $media, string $publicJpeg): void
    {
        if (!is_file($publicJpeg)) {
            return;
        }
        $original = (string) ($media['original_path'] ?? '');
        if ($original === '') {
            $original = Config::root() . '/storage/media/edited_' . (int) $media['id'] . '.jpg';
        }
        $dir = dirname($original);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        if (!copy($publicJpeg, $original)) {
            return;
        }
        if ((string) ($media['original_path'] ?? '') !== $original) {
            $this->posts->updateMedia((int) $media['id'], ['original_path' => $original]);
        }
    }

    /**
     * @param array<string, mixed> $user
     * @param array{file_id:string,kind:string,duration:int,bytes:int,width:int,height:int} $file
     */
    private function videoBlockReason(array $user, array $file): ?string
    {
        if (!$this->videoAllowed((int) $user['id'])) {
            return Messages::videoPlan();
        }
        $duration = (int) $file['duration'];
        if ($duration <= 0) {
            return Messages::videoDurationUnknown();
        }
        if ($duration < self::VIDEO_MIN_SECONDS) {
            return Messages::videoTooShort();
        }
        if ($duration > self::VIDEO_MAX_SECONDS) {
            return Messages::videoTooLong();
        }
        if ((int) $file['bytes'] > self::VIDEO_MAX_BYTES) {
            return Messages::videoTooBig();
        }

        return null;
    }

    private function videoAllowed(int $userId): bool
    {
        return $this->access !== null && $this->access->canPublishVideo($userId);
    }

    private function postIsVideo(int $postId): bool
    {
        foreach ($this->posts->media($postId) as $row) {
            if (($row['kind'] ?? 'image') === 'video') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $row
     * @return list<string>
     */
    private function stageVideo(int $postId, array $row): array
    {
        $dest = Config::root() . '/storage/media/' . $postId . '_0.mp4';
        if (empty($row['original_path']) || !is_file((string) $row['original_path'])) {
            $dir = dirname($dest);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException('Nao foi possivel criar storage/media');
            }
            $this->channel->download((string) $row['telegram_file_id'], $dest);
            $this->posts->updateMedia((int) $row['id'], ['original_path' => $dest]);
        } else {
            $dest = (string) $row['original_path'];
        }

        $publicDir = Config::root() . '/public/m';
        if (!is_dir($publicDir) && !mkdir($publicDir, 0775, true) && !is_dir($publicDir)) {
            throw new RuntimeException('Nao foi possivel criar public/m');
        }
        $oldName = (string) ($row['public_name'] ?? '');
        if ($oldName !== '') {
            $old = $publicDir . '/' . $oldName . '.mp4';
            if (is_file($old)) {
                unlink($old);
            }
        }
        $name = bin2hex(random_bytes(20));
        $public = $publicDir . '/' . $name . '.mp4';
        if (!copy($dest, $public)) {
            throw new RuntimeException('Nao foi possivel publicar o video');
        }
        @chmod($public, 0644);
        $fields = ['public_name' => $name];
        if ((int) ($row['width'] ?? 0) > 0) {
            $fields['width'] = (int) $row['width'];
        }
        if ((int) ($row['height'] ?? 0) > 0) {
            $fields['height'] = (int) $row['height'];
        }
        $this->posts->updateMedia((int) $row['id'], $fields);

        $frame = Config::root() . '/storage/media/' . $postId . '_0.jpg';
        if ((new VideoFrame())->capture($dest, $frame)) {
            return [$frame];
        }

        return [];
    }
}
