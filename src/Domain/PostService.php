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
use PerfilEmDia\Telegram\SlowNotice;
use PerfilEmDia\Image\IdeaImage;
use PerfilEmDia\Image\IdeaPieces;
use PerfilEmDia\Image\IdeaLook;
use PerfilEmDia\Image\IdeaImageGenerator;
use PerfilEmDia\Image\IdeaReference;
use PerfilEmDia\Image\IdeaVideo;
use PerfilEmDia\Image\IdeaVideoGenerator;
use PerfilEmDia\Image\ImageEditException;
use PerfilEmDia\Image\ImageEditRequest;
use PerfilEmDia\Image\ImageEditorInterface;
use PerfilEmDia\Image\ImageNormalizerInterface;
use PerfilEmDia\Image\OpenRouterImageEditor;
use PerfilEmDia\Image\PhotoMark;
use PerfilEmDia\Image\PhraseColor;
use PerfilEmDia\Image\PhrasePlace;
use PerfilEmDia\Image\PhotoPhrase;
use PerfilEmDia\Image\PhraseSize;
use PerfilEmDia\Image\PhraseStyle;
use PerfilEmDia\Image\StoryCard;
use PerfilEmDia\Image\StoryScript;
use PerfilEmDia\Image\VideoFrame;
use PerfilEmDia\Instagram\InstagramApiException;
use PerfilEmDia\Instagram\InstagramClient;
use PerfilEmDia\Instagram\InstagramPublisherInterface;
use PerfilEmDia\Instagram\PublishedMedia;
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
        private readonly ?IdeaVideoGenerator $videos = null,
    ) {
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $message
     */
    public function handleIncomingMedia(array $user, int $chatId, array $message): void
    {
        $logoPending = (string) ($user['pending_action'] ?? '');
        if (str_starts_with($logoPending, 'logo:')) {
            $this->receiveLogo($user, $chatId, $message, (int) substr($logoPending, 5));

            return;
        }

        if (!$this->guardsPass($user, $chatId)) {
            return;
        }

        $userPending = (string) ($user['pending_action'] ?? '');
        if (preg_match('/^aiv:(4|5|6|8|15)$/', $userPending, $videoSeconds) === 1) {
            $idea = trim((string) ($this->extractTheme($message) ?? ''));
            $this->startAiVideo($user, $chatId, $idea, IdeaVideo::duration((int) $videoSeconds[1]), $message);

            return;
        }
        if (str_starts_with($userPending, 'edit:')) {
            $this->users->update((int) $user['id'], ['pending_action' => null]);
            $user['pending_action'] = null;
            $userPending = '';
        }

        $pending = $this->posts->findPendingForUser((int) $user['id']);
        if ($pending !== null && !str_starts_with($userPending, 'newpost:')) {
            if ($this->shouldBlockMediaWhilePending($userPending, $pending)) {
                $text = (string) ($pending['destination'] ?? 'feed') === 'story'
                    ? Messages::storyKeepPreview()
                    : Messages::useOpenButtons();
                $this->channel->sendText($chatId, $text);

                return;
            }
            $block = $this->incomingBlock($user, $message);
            if ($block !== null) {
                $this->channel->sendText($chatId, $block);

                return;
            }
            $draftId = $this->createDraftFromMessage($user, $message, $pending);
            $this->users->update((int) $user['id'], [
                'pending_action' => 'newpost:' . $draftId,
            ]);
            $this->channel->sendText($chatId, Messages::replacePending(), Keyboards::yesNoPending());

            return;
        }

        $kind = $this->selectedKind($user);
        if ($kind === null) {
            if ($this->posts->findOpenWorkflowForUser((int) $user['id']) !== null) {
                $this->channel->sendText($chatId, Messages::postInProgress());

                return;
            }
            if (IncomingMediaStash::save((int) $user['id'], $message)) {
                $this->channel->sendText($chatId, Messages::mediaStashed());
            }
            $pending = (string) ($user['pending_action'] ?? '');
            if (str_starts_with($pending, 'where:')) {
                $where = substr($pending, 6) === 'story' ? 'story' : 'feed';
                $this->sendKindMenu($user, $chatId, $where);

                return;
            }
            $this->askWhere($chatId, $user);

            return;
        }

        if ($kind === 'ia') {
            $idea = $this->extractTheme($message);
            if ($idea === null || $idea === '') {
                $stashed = $this->stashIdeaReference($user, $message);
                $this->channel->sendText(
                    $chatId,
                    $stashed ? Messages::kindIaNeedTextWithPhoto() : Messages::kindIaNeedText(),
                );

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
                $this->channel->sendText($chatId, Messages::useOpenButtons());
                $this->sendPreview($user, $chatId, (int) $old['id']);
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
        if ($old !== null && (string) ($old['destination'] ?? '') === 'story') {
            $this->posts->update($draftId, ['destination' => 'story']);
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
            $this->generateAndPreview((int) $user['id'], $chatId, $postId, true, true);

            return true;
        }

        if ($status === PostStatus::AwaitingManualEdit) {
            $newBody = trim(strip_tags($text));
            if ($newBody === '') {
                $this->askManual($user, $chatId, $pending);

                return true;
            }
            $story = (string) ($pending['destination'] ?? 'feed') === 'story';
            $caption = \PerfilEmDia\Ai\CaptionGenerator::applyManualLegenda(
                (string) ($pending['caption'] ?? ''),
                $newBody,
                (string) ($user['contact_cta'] ?? ''),
            );
            $this->posts->update($postId, [
                'caption' => $caption,
                'caption_version' => ((int) $pending['caption_version']) + 1,
            ]);
            if (!$this->returnToMenu($postId, PostStatus::AwaitingManualEdit)) {
                return true;
            }
            if ((string) ($pending['destination'] ?? 'feed') === 'story') {
                $media = $this->posts->media($postId);
                $first = $media[0] ?? null;
                $photo = is_array($first) && !empty($first['public_name'])
                    ? Config::root() . '/public/m/' . $first['public_name'] . '.jpg'
                    : '';
                if ($photo !== '' && is_file($photo) && ($first['kind'] ?? 'image') !== 'video') {
                    $this->paintStoryCaption($user, $postId, $photo, $caption);
                }
            }
            $this->sendPreview($user, $chatId, $postId, $this->adjustButtons($user, $postId));

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
            'pub' => $this->publish(
                $user,
                $chatId,
                $post,
                (string) ($post['destination'] ?? 'feed') === 'story' ? 'story' : 'feed',
            ),
            'sty' => $this->publish($user, $chatId, $post, 'story'),
            'sch' => $this->askSchedule($user, $chatId, $post),
            'uns' => $this->unschedule($user, $chatId, $post),
            'txt' => $this->askPhotoPhrase($user, $chatId, $post),
            'wm' => $this->askMark($user, $chatId, $post),
            'img' => $this->askPhotoEdit($user, $chatId, $post),
            'mor' => $this->showAdjustMenu($user, $chatId, $post),
            'back' => $this->showApprovalMenu($chatId, $post),
            'look' => $this->showStoryLookMenu($chatId, $post),
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
            'man' => $this->askManual($user, $chatId, $post),
            'can' => $this->cancelPost($chatId, $post),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $user
     */
    public function cancelPending(array $user, int $chatId): void
    {
        $clearedWizard = $this->clearPostWizard((int) $user['id']);

        $pending = $this->posts->findPendingForUser((int) $user['id']);
        if ($pending !== null) {
            $from = PostStatus::from((string) $pending['status']);
            if ($from->isPending() && $this->posts->transition((int) $pending['id'], $from, PostStatus::Cancelled)) {
                $this->discardPublicMedia((int) $pending['id']);
                $this->channel->sendText($chatId, Messages::cancelled());

                return;
            }
        }

        $open = $this->posts->findOpenWorkflowForUser((int) $user['id']);
        if ($open !== null) {
            $from = PostStatus::from((string) $open['status']);
            if ($this->posts->transition((int) $open['id'], $from, PostStatus::Cancelled)) {
                $this->discardPublicMedia((int) $open['id']);
                $this->channel->sendText($chatId, Messages::cancelled());

                return;
            }
        }

        if ($clearedWizard) {
            $this->channel->sendText($chatId, Messages::wizardCancelled());

            return;
        }

        $this->channel->sendText($chatId, Messages::nothingToCancel());
    }

    /**
     * @param array<string, mixed> $user
     */
    public function beginNewPost(array $user, int $chatId): bool
    {
        if (!$this->guardsPass($user, $chatId)) {
            return false;
        }
        $this->channel->sendText($chatId, Messages::novo());
        $this->maybeSendPublishingAs($user, $chatId);
        $this->askWhere($chatId, $user);

        return true;
    }

    /**
     * @param array<string, mixed> $user
     */
    public function showInstagramAccounts(array $user, int $chatId): void
    {
        $userId = (int) $user['id'];
        $max = $this->access !== null ? $this->access->maxInstagramAccounts($userId) : 1;
        $accounts = $this->users->listInstagramAccounts($userId);
        if ($accounts === []) {
            $this->channel->sendText($chatId, Messages::needInstagram());

            return;
        }
        $active = $this->users->instagramAccount($userId);
        $activeId = $active !== null ? (int) $active['id'] : 0;
        $activeName = $active !== null ? (string) ($active['username'] ?? '') : '';
        $state = $this->users->createOauthState($userId, 'add');
        $url = rtrim(Config::get('APP_URL'), '/') . '/conectar.php?t=' . $state;
        $this->channel->sendText(
            $chatId,
            Messages::instagramAccountsMenu(count($accounts), $max, $activeName),
            Keyboards::instagramAccounts($accounts, $activeId, $max, $url),
        );
    }

    /**
     * @param array<string, mixed> $user
     */
    public function handleInstagramAccountCallback(array $user, int $chatId, string $data): bool
    {
        if (preg_match('/^ig:pick:(\d+)$/', $data, $m) === 1) {
            $accountId = (int) $m[1];
            if (!$this->users->setActiveInstagramAccount((int) $user['id'], $accountId)) {
                $this->channel->sendText($chatId, Messages::needInstagram());

                return true;
            }
            $ig = $this->users->instagramAccountById($accountId, (int) $user['id']);
            $name = is_array($ig) ? (string) ($ig['username'] ?? '') : '';
            $this->channel->sendText($chatId, Messages::instagramPicked($name));

            return true;
        }
        if (preg_match('/^ig:off:(\d+)$/', $data, $m) === 1) {
            $accountId = (int) $m[1];
            $ig = $this->users->instagramAccountById($accountId, (int) $user['id']);
            $name = is_array($ig) ? (string) ($ig['username'] ?? '') : '';
            if (!$this->users->disconnectInstagramAccount((int) $user['id'], $accountId)) {
                return true;
            }
            $this->channel->sendText($chatId, Messages::instagramDisconnected($name));

            return true;
        }

        return false;
    }

    private function clearPostWizard(int $userId): bool
    {
        $user = $this->users->find($userId);
        if ($user === null) {
            return false;
        }
        $pendingAction = (string) ($user['pending_action'] ?? '');
        if ($pendingAction === '' || str_starts_with($pendingAction, 'chamado:') || str_starts_with($pendingAction, 'edit:')) {
            return false;
        }
        $wizard = str_starts_with($pendingAction, 'where:')
            || str_starts_with($pendingAction, 'kind:')
            || str_starts_with($pendingAction, 'aiv:')
            || str_starts_with($pendingAction, 'newpost:')
            || str_starts_with($pendingAction, 'surprise:');
        if (!$wizard) {
            return false;
        }
        $this->users->update($userId, ['pending_action' => null]);
        IncomingMediaStash::clear($userId);
        IdeaReference::clearStash($userId);
        SurpriseDraft::clear($userId);

        return true;
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
    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $message
     * @param array<string, mixed>|null $inheritFrom
     */
    private function createDraftFromMessage(array $user, array $message, ?array $inheritFrom = null): int
    {
        $theme = $this->extractTheme($message);
        $mediaGroupId = isset($message['media_group_id']) ? (string) $message['media_group_id'] : null;
        $postId = $this->posts->create((int) $user['id'], PostStatus::Collecting, $theme, $mediaGroupId, $this->activeInstagramAccountId($user));
        $this->rememberDestination($user, $postId);
        if ($inheritFrom !== null && (string) ($inheritFrom['destination'] ?? '') === 'story') {
            $this->posts->update($postId, ['destination' => 'story']);
        }
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
        $postId = $this->posts->create((int) $user['id'], $status, $theme, null, $this->activeInstagramAccountId($user));
        $this->rememberDestination($user, $postId);
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
            $postId = $this->posts->create((int) $user['id'], PostStatus::Collecting, $theme, $mediaGroupId, $this->activeInstagramAccountId($user));
            $this->rememberDestination($user, $postId);
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

    private function generateAndPreview(int $userId, int $chatId, int $postId, bool $sendPreview = true, bool $keepAdjust = false): void
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

                $canvas = (string) ($post['destination'] ?? 'feed') === 'story' ? 'story' : 'feed';
                $normalized = $this->normalizer->normalize($sourcePaths, $publicDir, $canvas);
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
            $captionUser = $this->captionContext($user, $post);
            $profile = [
                'display_name' => $captionUser['display_name'] ?? null,
                'profession' => $captionUser['profession'] ?? null,
                'city' => $captionUser['city'] ?? null,
                'tone' => $captionUser['tone'] ?? null,
                'contact_cta' => $captionUser['contact_cta'] ?? null,
                'about' => $captionUser['about'] ?? null,
                'fixed_hashtags' => $captionUser['fixed_hashtags'] ?? null,
            ];
            $previous = $post['caption'] !== null ? (string) $post['caption'] : null;
            $feedback = $post['last_feedback'] !== null ? (string) $post['last_feedback'] : null;
            $theme = (string) ($post['theme_text'] ?? '');
            if ((int) ($post['creative'] ?? 0) === 1) {
                $theme = "[[criacao]]\n" . $theme;
            }
            if ((string) ($post['destination'] ?? 'feed') === 'story') {
                $theme = "[[story]]\n" . $theme;
            }
            if ($isVideo && $jpegPaths === []) {
                $theme .= "\nIsto é um vídeo curto. Escreva a legenda só com o que a pessoa contou, sem inventar o que aparece.";
            }
            $matchedExtras = $this->matchedPromptExtras(
                $user,
                $post,
                (string) ($post['theme_text'] ?? ''),
                $feedback ?? '',
            );
            $result = $this->captions->generate(
                $profile,
                $theme,
                $jpegPaths,
                $previous,
                $feedback,
                PromptExtras::formatForCaption($matchedExtras),
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
            if (!$isVideo && (string) ($post['destination'] ?? 'feed') === 'story' && isset($jpegPaths[0])) {
                $cleanId = (int) ($mediaRows[0]['id'] ?? 0);
                $this->replaceStoryClean($cleanId, (string) $jpegPaths[0]);
                $this->paintStoryCaption($user, $postId, (string) $jpegPaths[0], $result->caption);
            }

            if (!$this->returnToMenu($postId, PostStatus::Generating)) {
                return;
            }
            if ($sendPreview) {
                $this->sendPreview($user, $chatId, $postId, $keepAdjust ? $this->adjustButtons($user, $postId) : null);
            }
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
            $destination = (string) ($post['destination'] ?? 'feed') === 'story' ? 'story' : 'feed';
            $this->publish($user, $chatId, $post, $destination);
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
        $this->sendPhrasePrompt($user, $chatId, (int) $post['id'], false);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function choosePhraseStyle(array $user, int $chatId, string $callbackId, string $style, int $postId): void
    {
        $this->channel->answerCallback($callbackId);
        $style = PhraseStyle::normalize($style);
        $fields = ['phrase_style' => $style];
        $paired = PhraseStyle::pairedColor($style);
        if ($paired !== null) {
            $fields['phrase_color'] = $paired;
        }
        $this->users->update((int) $user['id'], $fields);
        $post = $this->posts->find($postId);
        if ($post === null || (int) $post['user_id'] !== (int) $user['id'] || !$this->inMenu($post)) {
            $this->channel->sendText($chatId, Messages::alreadyProcessed());

            return;
        }
        $this->users->update((int) $user['id'], ['pending_action' => 'phrase:' . $postId]);
        $user = $this->users->find((int) $user['id']) ?? $user;
        if ($this->refreshPhrasePreview($user, $chatId, $post)) {
            return;
        }
        $this->sendPhrasePrompt($user, $chatId, $postId, true);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function choosePhraseColor(array $user, int $chatId, string $callbackId, string $color, int $postId): void
    {
        $this->channel->answerCallback($callbackId);
        $color = PhraseColor::normalize($color);
        $this->users->update((int) $user['id'], ['phrase_color' => $color]);
        $post = $this->posts->find($postId);
        if ($post === null || (int) $post['user_id'] !== (int) $user['id'] || !$this->inMenu($post)) {
            $this->channel->sendText($chatId, Messages::alreadyProcessed());

            return;
        }
        $this->users->update((int) $user['id'], ['pending_action' => 'phrase:' . $postId]);
        $user = $this->users->find((int) $user['id']) ?? $user;
        if ($this->refreshPhrasePreview($user, $chatId, $post)) {
            return;
        }
        $this->sendPhrasePrompt($user, $chatId, $postId, true);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function choosePhrasePlace(array $user, int $chatId, string $callbackId, string $place, int $postId): void
    {
        $this->channel->answerCallback($callbackId);
        $place = PhrasePlace::normalize($place);
        $this->users->update((int) $user['id'], ['phrase_place' => $place]);
        $post = $this->posts->find($postId);
        if ($post === null || (int) $post['user_id'] !== (int) $user['id'] || !$this->inMenu($post)) {
            $this->channel->sendText($chatId, Messages::alreadyProcessed());

            return;
        }
        $this->users->update((int) $user['id'], ['pending_action' => 'phrase:' . $postId]);
        $user = $this->users->find((int) $user['id']) ?? $user;
        if ($this->refreshPhrasePreview($user, $chatId, $post)) {
            return;
        }
        $this->sendPhrasePrompt($user, $chatId, $postId, true);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function choosePhraseSize(array $user, int $chatId, string $callbackId, string $size, int $postId): void
    {
        $this->channel->answerCallback($callbackId);
        $this->users->update((int) $user['id'], ['phrase_size' => PhraseSize::normalize($size)]);
        $post = $this->posts->find($postId);
        if ($post === null || (int) $post['user_id'] !== (int) $user['id'] || !$this->inMenu($post)) {
            $this->channel->sendText($chatId, Messages::alreadyProcessed());

            return;
        }
        $this->users->update((int) $user['id'], ['pending_action' => 'phrase:' . $postId]);
        $user = $this->users->find((int) $user['id']) ?? $user;
        if ($this->refreshPhrasePreview($user, $chatId, $post)) {
            return;
        }
        $this->sendPhrasePrompt($user, $chatId, $postId, true);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function clearPhrase(array $user, int $chatId, string $callbackId, int $postId): void
    {
        $this->channel->answerCallback($callbackId);
        $post = $this->posts->find($postId);
        if ($post === null || (int) $post['user_id'] !== (int) $user['id'] || !$this->inMenu($post)) {
            $this->channel->sendText($chatId, Messages::alreadyProcessed());

            return;
        }
        $media = $this->posts->media($postId);
        $first = $media[0] ?? null;
        $base = is_array($first) ? $this->phraseBasePath((int) $first['id']) : '';
        $publicDir = Config::root() . '/public/m';
        if (!is_array($first) || !is_file($base)) {
            $this->channel->sendText($chatId, Messages::photoEditFailed());

            return;
        }
        $name = bin2hex(random_bytes(20));
        $dest = $publicDir . '/' . $name . '.jpg';
        $current = !empty($first['public_name']) ? $publicDir . '/' . $first['public_name'] . '.jpg' : '';
        if (!copy($base, $dest)) {
            $this->channel->sendText($chatId, Messages::photoEditFailed());

            return;
        }
        $this->posts->updateMedia((int) $first['id'], ['public_name' => $name]);
        $this->posts->update($postId, ['photo_phrase' => null]);
        $this->freezeEditedPhoto($first, $dest);
        if ($current !== '' && is_file($current)) {
            unlink($current);
        }
        $this->users->update((int) $user['id'], ['pending_action' => 'phrase:' . $postId]);
        $this->sendPreview($user, $chatId, $postId);
        $this->sendPhrasePrompt($user, $chatId, $postId, false);
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
            $this->sendPhrasePrompt($user, $chatId, $postId, false);

            return true;
        }
        $limit = (string) ($post['destination'] ?? '') === 'story' ? StoryScript::MAX_PART_CHARS : 80;
        $this->applyPhrase($user, $chatId, $post, mb_substr($phrase, 0, $limit));

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
        $base = $this->phraseBasePath((int) $first['id']);
        if (!is_file($base)) {
            $this->rememberPhraseBase((int) $first['id'], $current);
        }
        $name = bin2hex(random_bytes(20));
        $dest = $publicDir . '/' . $name . '.jpg';
        if (!is_file($base) || !copy($base, $dest)) {
            $this->channel->sendText($chatId, Messages::photoEditFailed());

            return;
        }
        $this->drawPhrase($user, $post, $dest, $phrase);
        $this->posts->updateMedia((int) $first['id'], ['public_name' => $name]);
        $this->posts->update($postId, ['photo_phrase' => $phrase]);
        $this->freezeEditedPhoto($first, $dest);
        if (is_file($current)) {
            unlink($current);
        }
        $story = (string) ($post['destination'] ?? 'feed') === 'story';
        $this->users->update((int) $user['id'], [
            'pending_action' => $story ? null : 'phrase:' . $postId,
        ]);
        if ($story) {
            $this->sendPreview($user, $chatId, $postId, $this->storyLookButtons($postId));

            return;
        }
        $this->sendPreview($user, $chatId, $postId);
        $this->sendPhrasePrompt($user, $chatId, $postId, true);
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
        if ((string) ($post['destination'] ?? 'feed') === 'story') {
            $this->channel->sendText($chatId, Messages::storyNoMark());

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
        $hasLogo = $this->logoFile($user, $post) !== null;
        $this->channel->sendText(
            $chatId,
            Messages::askMarkSource($hasLogo),
            Keyboards::markSource((int) $post['id'], $hasLogo)
        );
    }

    /**
     * @param array<string, mixed> $user
     */
    public function chooseMarkSource(array $user, int $chatId, string $callbackId, string $source, int $postId): void
    {
        $this->channel->answerCallback($callbackId);
        $post = $this->posts->find($postId);
        if ($post === null || (int) $post['user_id'] !== (int) $user['id'] || !$this->inMenu($post)) {
            $this->channel->sendText($chatId, Messages::alreadyProcessed());

            return;
        }
        if ($source === 'up') {
            $this->users->update((int) $user['id'], ['pending_action' => 'logo:' . $postId]);
            $this->channel->sendText($chatId, Messages::askLogoUpload());

            return;
        }
        if ($source === 'ok') {
            if ($this->logoFile($user, $post) === null) {
                $this->users->update((int) $user['id'], ['pending_action' => 'logo:' . $postId]);
                $this->channel->sendText($chatId, Messages::askLogoUpload());

                return;
            }
            $this->channel->sendText($chatId, Messages::askMarkPlate(), Keyboards::markPlace($postId, 'lg'));

            return;
        }
        $ig = $this->resolveInstagram($user, $post);
        if (!is_array($ig) || ($ig['status'] ?? '') !== 'active' || empty($ig['access_token'])) {
            $this->channel->sendText($chatId, Messages::markNeedsInstagram());

            return;
        }
        $this->channel->sendText($chatId, Messages::askMarkPlace(), Keyboards::markPlace($postId, 'ig'));
    }

    /**
     * @param array<string, mixed> $user
     */
    public function handleLogoWait(array $user, int $chatId): bool
    {
        if (!str_starts_with((string) ($user['pending_action'] ?? ''), 'logo:')) {
            return false;
        }
        $this->channel->sendText($chatId, Messages::logoNeedImage());

        return true;
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $message
     */
    private function receiveLogo(array $user, int $chatId, array $message, int $postId): void
    {
        $file = $this->extractFile($message);
        if ($file === null || $file['kind'] !== 'image') {
            $this->channel->sendText($chatId, Messages::logoNeedImage());

            return;
        }
        $temp = tempnam(sys_get_temp_dir(), 'lg');
        if ($temp === false) {
            $this->channel->sendText($chatId, Messages::logoFailed());

            return;
        }
        try {
            $this->channel->download($file['file_id'], $temp);
            $postRow = $this->posts->find($postId);
            $accountId = is_array($postRow)
                ? (int) ($postRow['instagram_account_id'] ?? 0)
                : 0;
            if ($accountId <= 0) {
                $accountId = $this->users->resolveProfileAccountId((int) $user['id']) ?? 0;
            }
            if ($accountId <= 0) {
                $this->channel->sendText($chatId, Messages::logoFailed());

                return;
            }
            $saved = $this->storeLogo($accountId, $temp);
            if ($saved === null) {
                $this->channel->sendText($chatId, Messages::logoFailed());

                return;
            }
            $this->users->update((int) $user['id'], [
                'logo_path' => $saved,
                'pending_action' => null,
            ]);
            $post = $this->posts->find($postId);
            if ($post === null || (int) $post['user_id'] !== (int) $user['id'] || !$this->inMenu($post)) {
                $this->channel->sendText($chatId, Messages::logoSavedIdle());

                return;
            }
            $this->channel->sendText($chatId, Messages::logoSaved(), Keyboards::markPlace($postId, 'lg'));
        } catch (\Throwable) {
            $this->channel->sendText($chatId, Messages::logoFailed());
        } finally {
            if (is_file($temp)) {
                unlink($temp);
            }
        }
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function profileView(array $user): array
    {
        return $this->users->userForPerfil($this->users->find((int) $user['id']) ?? $user);
    }

    private function profileContext(array $user, ?array $post = null): array
    {
        return $post !== null
            ? $this->captionContext($user, $post)
            : $this->profileView($user);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed>|null $post
     */
    private function phraseStyleOf(array $user, ?array $post = null): string
    {
        $fresh = $this->profileContext($user, $post);

        return PhraseStyle::normalize((string) ($fresh['phrase_style'] ?? ''));
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed>|null $post
     */
    private function phraseColorOf(array $user, ?array $post = null): string
    {
        $fresh = $this->profileContext($user, $post);

        return PhraseColor::normalize((string) ($fresh['phrase_color'] ?? ''));
    }

    /**
     * @param array<string, mixed> $user
     */
    private function phrasePlaceOf(array $user, ?array $post = null): string
    {
        $fresh = $this->profileContext($user, $post);

        return PhrasePlace::normalize((string) ($fresh['phrase_place'] ?? ''));
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed>|null $post
     */
    private function phraseSizeOf(array $user, ?array $post = null): string
    {
        $fresh = $this->profileContext($user, $post);

        return PhraseSize::normalize((string) ($fresh['phrase_size'] ?? ''));
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $post
     */
    private function refreshPhrasePreview(array $user, int $chatId, array $post): bool
    {
        if ((string) ($post['destination'] ?? 'feed') === 'story') {
            $caption = trim((string) ($post['caption'] ?? ''));
            if ($caption === '') {
                return false;
            }
            $media = $this->posts->media((int) $post['id']);
            $first = $media[0] ?? null;
            $jpeg = is_array($first) && !empty($first['public_name'])
                ? Config::root() . '/public/m/' . $first['public_name'] . '.jpg'
                : '';
            $this->paintStoryCaption($user, (int) $post['id'], $jpeg, $caption);
            $this->users->update((int) $user['id'], ['pending_action' => null]);
            $this->sendPreview($user, $chatId, (int) $post['id'], $this->storyLookButtons((int) $post['id']));

            return true;
        }
        $phrase = trim((string) ($post['photo_phrase'] ?? ''));
        if ($phrase === '') {
            return false;
        }
        $this->applyPhrase($user, $chatId, $post, $phrase);

        return true;
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $post
     */
    private function drawPhrase(array $user, array $post, string $dest, string $phrase): void
    {
        $style = $this->phraseStyleOf($user, $post);
        $color = $this->phraseColorOf($user, $post);
        $place = $this->phrasePlaceOf($user, $post);
        $size = $this->phraseSizeOf($user, $post);
        if ((string) ($post['destination'] ?? 'feed') === 'story') {
            StoryCard::draw($dest, $phrase, $color, $place, $size, PhraseStyle::storyChoice($style));

            return;
        }
        PhotoPhrase::draw($dest, $phrase, $style, $color, $place, $size);
    }

    /**
     * @param array<string, mixed> $user
     */
    private function paintStoryCaption(array $user, int $postId, string $jpeg, string $caption): void
    {
        $post = $this->posts->find($postId) ?? ['destination' => 'story'];
        if ((int) ($post['creative'] ?? 0) === 1 && IdeaImage::isDesigned((string) ($post['theme_text'] ?? ''))) {
            return;
        }
        $ctx = $this->profileContext($user, is_array($post) ? $post : null);
        $parts = StoryScript::parts($caption, (string) ($ctx['contact_cta'] ?? ''));
        if ($parts === []) {
            return;
        }
        $media = $this->posts->media($postId);
        $first = $media[0] ?? null;
        if (!is_array($first) || ($first['kind'] ?? 'image') === 'video') {
            return;
        }
        $clean = $this->ensureStoryClean((int) $first['id'], $first, $jpeg);
        if (!is_file($clean)) {
            return;
        }
        $this->dropStoryPages($postId);
        $publicDir = Config::root() . '/public/m';
        if (!is_dir($publicDir) && !mkdir($publicDir, 0775, true) && !is_dir($publicDir)) {
            return;
        }
        $oldName = (string) ($first['public_name'] ?? '');
        $oldPath = $oldName !== '' ? $publicDir . '/' . $oldName . '.jpg' : '';
        $firstName = '';
        foreach ($parts as $index => $text) {
            $name = bin2hex(random_bytes(20));
            $dest = $publicDir . '/' . $name . '.jpg';
            if (!copy($clean, $dest)) {
                continue;
            }
            $this->drawPhrase($user, $post, $dest, $text);
            $info = @getimagesize($dest);
            $fields = ['public_name' => $name];
            if (is_array($info)) {
                $fields['width'] = (int) $info[0];
                $fields['height'] = (int) $info[1];
            }
            if ($index === 0) {
                $this->posts->updateMedia((int) $first['id'], $fields);
                $firstName = $name;
                continue;
            }
            $pageId = $this->posts->addMedia($postId, $index, 'storypage', 0);
            $this->posts->updateMedia($pageId, $fields);
        }
        if ($oldPath !== '' && $firstName !== '' && $oldName !== $firstName && is_file($oldPath) && $oldPath !== $clean) {
            unlink($oldPath);
        }
        $this->posts->update($postId, [
            'photo_phrase' => $parts[0],
            'story_sent' => 0,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function ensureStoryClean(int $mediaId, array $row, string $fallback): string
    {
        $clean = $this->storyCleanPath($mediaId);
        if (is_file($clean)) {
            return $clean;
        }
        $original = (string) ($row['original_path'] ?? '');
        $source = $original !== '' && is_file($original) ? $original : $fallback;
        $this->replaceStoryClean($mediaId, $source);

        return $this->storyCleanPath($mediaId);
    }

    private function storyCleanPath(int $mediaId): string
    {
        return Config::root() . '/storage/media/storyclean_' . $mediaId . '.jpg';
    }

    private function replaceStoryClean(int $mediaId, string $source): void
    {
        if ($mediaId <= 0 || !is_file($source)) {
            return;
        }
        $dest = $this->storyCleanPath($mediaId);
        $dir = dirname($dest);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        if ($source !== $dest) {
            copy($source, $dest);
        }
    }

    private function dropStoryPages(int $postId): void
    {
        $publicDir = Config::root() . '/public/m';
        foreach ($this->posts->media($postId) as $row) {
            if ((string) ($row['telegram_file_id'] ?? '') !== 'storypage') {
                continue;
            }
            $name = (string) ($row['public_name'] ?? '');
            if ($name !== '') {
                $path = $publicDir . '/' . $name . '.jpg';
                if (is_file($path)) {
                    unlink($path);
                }
            }
            $this->posts->deleteMedia((int) $row['id']);
        }
    }

    /**
     * @param array<string, mixed> $user
     */
    private function sendPhrasePrompt(array $user, int $chatId, int $postId, bool $saved): void
    {
        $post = $this->posts->find($postId);
        $style = $this->phraseStyleOf($user, $post);
        $color = $this->phraseColorOf($user, $post);
        $place = $this->phrasePlaceOf($user, $post);
        $size = $this->phraseSizeOf($user, $post);
        $isStory = is_array($post) && (string) ($post['destination'] ?? 'feed') === 'story';
        if ($isStory) {
            $this->channel->sendText(
                $chatId,
                $saved
                    ? Messages::phraseLookSaved(PhraseStyle::label($style), PhraseColor::label($color), PhrasePlace::where($place))
                    : Messages::askStoryManual(),
            );
            if (!$saved) {
                $editable = \PerfilEmDia\Ai\CaptionGenerator::legendaForEdit(
                    (string) ($post['caption'] ?? ''),
                    (string) ($user['contact_cta'] ?? ''),
                    true,
                );
                if ($editable === '') {
                    $editable = trim((string) ($post['photo_phrase'] ?? ''));
                }
                $this->sendEditableSnippet($chatId, $editable);
            }

            return;
        }
        $text = $saved
            ? Messages::phraseLookSaved(PhraseStyle::label($style), PhraseColor::label($color), PhrasePlace::where($place))
            : Messages::askPhotoPhrase(PhraseStyle::label($style), PhraseColor::label($color), PhrasePlace::where($place));
        $canRemove = is_array($post) && trim((string) ($post['photo_phrase'] ?? '')) !== '';
        $this->channel->sendText($chatId, $text, Keyboards::phraseStyles($postId, $style, $color, $place, $size, $canRemove));
        if (!$saved && is_array($post)) {
            $this->sendEditableSnippet($chatId, trim((string) ($post['photo_phrase'] ?? '')));
        }
    }

    private function phraseBasePath(int $mediaId): string
    {
        return Config::root() . '/storage/media/phrasebase_' . $mediaId . '.jpg';
    }

    private function rememberPhraseBase(int $mediaId, string $source): void
    {
        if (!is_file($source)) {
            return;
        }
        $dest = $this->phraseBasePath($mediaId);
        $dir = dirname($dest);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        if ($source !== $dest) {
            copy($source, $dest);
        }
    }

    private function stampPhraseBase(int $mediaId, string $logo, string $place, bool $plate): void
    {
        $base = $this->phraseBasePath($mediaId);
        if (!is_file($base)) {
            return;
        }
        if ($plate) {
            PhotoMark::stampPlate($base, $logo, $place);

            return;
        }
        PhotoMark::stamp($base, $logo, $place);
    }

    /**
     * @param array<string, mixed> $user
     */
    private function logoFile(array $user, ?array $post = null): ?string
    {
        $ctx = $this->profileContext($user, $post);
        $relative = trim((string) ($ctx['logo_path'] ?? ''));
        if ($relative === '' || str_contains($relative, '..')) {
            return null;
        }
        $path = Config::root() . '/' . ltrim($relative, '/');

        return is_file($path) ? $path : null;
    }

    private function storeLogo(int $accountId, string $source): ?string
    {
        $dir = Config::root() . '/storage/logos';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return null;
        }
        $dest = $dir . '/a' . $accountId . '.png';
        if (extension_loaded('imagick') && class_exists(\Imagick::class)) {
            try {
                $image = new \Imagick($source);
                $image->setImageFormat('png');
                $image->writeImage($dest);
                $image->clear();

                return 'storage/logos/a' . $accountId . '.png';
            } catch (\Throwable) {
            }
        }
        $raw = file_get_contents($source);
        $gd = is_string($raw) ? @imagecreatefromstring($raw) : false;
        if ($gd === false) {
            return null;
        }
        imagesavealpha($gd, true);
        $ok = imagepng($gd, $dest);
        imagedestroy($gd);

        return $ok ? 'storage/logos/a' . $accountId . '.png' : null;
    }

    /**
     * @param array<string, mixed> $user
     */
    public function placeMark(array $user, int $chatId, string $callbackId, string $place, string $source, int $postId): void
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
        if ($source === 'lg') {
            $path = $this->logoFile($this->users->find((int) $user['id']) ?? $user);
            if ($path === null) {
                $this->users->update((int) $user['id'], ['pending_action' => 'logo:' . $postId]);
                $this->channel->sendText($chatId, Messages::askLogoUpload());

                return;
            }
            $this->applyMark($user, $chatId, $post, $path, $place, true);

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
    private function applyMark(array $user, int $chatId, array $post, string $logo, string $place, bool $plate = false): void
    {
        if ((string) ($post['destination'] ?? 'feed') === 'story') {
            $this->channel->sendText($chatId, Messages::storyNoMark());

            return;
        }
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
            if (!copy($current, $dest)) {
                continue;
            }
            $stampedFile = $plate
                ? PhotoMark::stampPlate($dest, $logo, $place)
                : PhotoMark::stamp($dest, $logo, $place);
            if (!$stampedFile) {
                if (is_file($dest)) {
                    unlink($dest);
                }
                continue;
            }
            $this->posts->updateMedia((int) $row['id'], ['public_name' => $name]);
            $this->stampPhraseBase((int) $row['id'], $logo, $place, $plate);
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
        $this->channel->sendText(
            $chatId,
            $plate ? Messages::askMarkPlate() : Messages::askMarkPlace(),
            Keyboards::markPlace($postId, $plate ? 'lg' : 'ig'),
        );
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
            if (!is_array($fresh)) {
                return;
            }
            if ((string) ($fresh['destination'] ?? 'feed') === 'story') {
                $this->posts->update($postId, [
                    'caption' => $request->phrase,
                    'caption_version' => ((int) ($fresh['caption_version'] ?? 0)) + 1,
                ]);
                $rows = $this->posts->media($postId);
                $row = $rows[0] ?? null;
                $jpeg = is_array($row) && !empty($row['public_name'])
                    ? Config::root() . '/public/m/' . $row['public_name'] . '.jpg'
                    : '';
                $this->paintStoryCaption($user, $postId, $jpeg, $request->phrase);
                $this->sendPreview($user, $chatId, $postId);

                return;
            }
            $this->applyPhrase($user, $chatId, $fresh, $request->phrase);

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
            $base = $this->phraseBasePath((int) $first['id']);
            $source = is_file($base) ? $base : $current;
            $isStory = (string) ($post['destination'] ?? 'feed') === 'story';
            $cleanNow = $this->storyCleanPath((int) $first['id']);
            if ($isStory && is_file($cleanNow)) {
                $source = $cleanNow;
            }
            if ($request->treatment !== '') {
                $binary = $this->editor()->edit(
                    $isStory && is_file($cleanNow) ? $cleanNow : $current,
                    $request->treatment,
                    $isStory ? '9:16' : '4:5',
                );
                $temp = tempnam(sys_get_temp_dir(), 'pd');
                if ($temp === false) {
                    throw new ImageEditException('Cannot store edited photo');
                }
                file_put_contents($temp, $binary);
                $source = $temp;
            }
            $publicDir = Config::root() . '/public/m';
            $normalized = $this->normalizer->normalize([$source], $publicDir, $isStory ? 'story' : 'feed');
            $image = $normalized[0] ?? null;
            if ($image === null) {
                throw new ImageEditException('Normalizer returned no photo');
            }
            if ($isStory) {
                $this->replaceStoryClean((int) $first['id'], $image->absolutePath);
                $this->freezeEditedPhoto($first, $this->storyCleanPath((int) $first['id']));
                $this->posts->update($postId, [
                    'image_edit_count' => ((int) ($post['image_edit_count'] ?? 0)) + 1,
                ]);
                if (!$this->returnToMenu($postId, PostStatus::ImageEditing)) {
                    return;
                }
                $this->posts->recordEvent((int) $user['id'], $postId, 'photo_edit', [
                    'phrase' => false,
                    'treatment' => $request->treatment !== '',
                ]);
                $fresh = $this->posts->find($postId) ?? $post;
                $this->paintStoryCaption($user, $postId, $image->absolutePath, (string) ($fresh['caption'] ?? ''));
                $normalizedPath = $image->absolutePath;
                $cleanPath = $this->storyCleanPath((int) $first['id']);
                if ($normalizedPath !== $cleanPath && is_file($normalizedPath)) {
                    unlink($normalizedPath);
                }
                $this->sendPreview($user, $chatId, $postId, $this->adjustButtons($user, $postId));

                return;
            }
            $this->rememberPhraseBase((int) $first['id'], $image->absolutePath);
            if ($request->phrase !== null) {
                $this->drawPhrase($user, $post, $image->absolutePath, $request->phrase);
                $this->posts->update($postId, ['photo_phrase' => $request->phrase]);
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
            $this->sendPreview($user, $chatId, $postId, $this->adjustButtons($user, $postId));
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
        $savedRef = IdeaReference::postPath($postId);
        $aspect = (string) ($post['destination'] ?? 'feed') === 'story' ? '9:16' : '4:5';
        $idea = (string) ($post['theme_text'] ?? '');
        $series = count(IdeaImage::pieces($idea, $aspect)) > 1;
        $reference = is_file($savedRef) ? $savedRef : (!$series && is_file($path) ? $path : null);
        $matchedExtras = $this->matchedPromptExtras($user, $post, $idea);
        $reference = $this->ideaApiReference($reference, $idea, $matchedExtras);
        try {
            $generator = $this->ideas ?? new IdeaImage();
            $look = $this->ideaLookBrief($this->captionContext($user, $post), $idea, $matchedExtras);
            $frames = $generator instanceof IdeaImage
                ? $generator->createSet($idea, $reference, $look, $aspect)
                : [$generator->create($idea, $reference, $look, $aspect)];
            $frames = $this->applyBrandLogoOverlays($frames, $idea, $matchedExtras);
            $this->storeIdeaFrames($postId, $frames, 0);

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
    /**
     * @param list<list<array{text:string, callback_data?:string, url?:string}>>|null $buttons
     */
    private function sendPreview(array $user, int $chatId, int $postId, ?array $buttons = null): void
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
        $isStory = (string) ($post['destination'] ?? 'feed') === 'story';
        $previewCaption = $isStory && !$isVideo
            ? \PerfilEmDia\Ai\CaptionGenerator::storyTextForReview($caption, (string) ($user['contact_cta'] ?? ''))
            : $caption;
        $buttons ??= Keyboards::approval($postId, false);
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
        $designed = (int) ($post['creative'] ?? 0) === 1
            && IdeaImage::isDesigned((string) ($post['theme_text'] ?? ''));
        $many = !$isVideo && count($media) > 1;
        $previewId = $isVideo
            ? $this->channel->sendVideo($chatId, $path, $previewCaption, $buttons)
            : ($many && ($isStory || $designed)
                ? $this->sendStoryPages(
                    $chatId,
                    $media,
                    $previewCaption,
                    $buttons,
                    $designed ? Messages::ideaSlides(count($media), $isStory) : null,
                )
                : $this->channel->sendPhoto($chatId, $path, $previewCaption, $buttons));
        $this->posts->update($postId, ['preview_message_id' => $previewId]);
    }

    /**
     * @param list<array<string, mixed>> $media
     * @param list<list<array{text:string, callback_data?:string, url?:string}>> $buttons
     */
    private function sendStoryPages(int $chatId, array $media, string $caption, array $buttons, ?string $note = null): int
    {
        $paths = [];
        foreach ($media as $row) {
            if (($row['kind'] ?? 'image') === 'video' || empty($row['public_name'])) {
                continue;
            }
            $file = Config::root() . '/public/m/' . $row['public_name'] . '.jpg';
            if (is_file($file)) {
                $paths[] = $file;
            }
        }
        if (count($paths) > 1) {
            $this->channel->sendAlbum($chatId, $paths);
        }
        $note ??= Messages::storyPages(max(1, count($paths)));

        return $this->channel->sendText($chatId, $caption === '' ? $note : $note . "\n\n" . $caption, $buttons);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $post
     * @return array{
     *     allowRegen: bool,
     *     allowPhoto: bool,
     *     allowMark: bool,
     *     allowIdea: bool,
     *     allowPhrase: bool,
     *     storyLook: bool,
     * }
     */
    private function previewAdjustFlags(array $user, array $post): array
    {
        $media = $this->posts->media((int) $post['id']);
        $first = $media[0] ?? null;
        $isVideo = ($first['kind'] ?? 'image') === 'video';
        $limit = (int) Config::get('LIMIT_REGENERATIONS_PER_POST', '5');
        $allowRegen = ((int) $post['regen_count']) < $limit;
        $canPhoto = !$isVideo && $this->photoEditAllowed((int) $user['id']);
        $usedEdits = (int) ($post['image_edit_count'] ?? 0);
        $allowTreat = $canPhoto && $usedEdits < $this->photoEditLimit();
        $allowIdea = (int) ($post['creative'] ?? 0) === 1
            && (int) ($post['idea_regen_count'] ?? 0) < $this->ideaImageLimit();

        return [
            'allowRegen' => $allowRegen,
            'allowPhoto' => $allowTreat,
            'allowMark' => $canPhoto && (string) ($post['destination'] ?? 'feed') !== 'story',
            'allowIdea' => $allowIdea,
            'allowPhrase' => $canPhoto && (string) ($post['destination'] ?? 'feed') !== 'story',
            'storyLook' => (string) ($post['destination'] ?? 'feed') === 'story',
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $post
     */
    private function showAdjustMenu(array $user, int $chatId, array $post): void
    {
        $previewId = $post['preview_message_id'] !== null ? (int) $post['preview_message_id'] : null;
        if ($previewId === null) {
            return;
        }
        $this->channel->editButtons($chatId, $previewId, $this->adjustButtons($user, (int) $post['id']));
    }

    /**
     * @param array<string, mixed> $user
     * @return list<list<array{text:string, callback_data?:string, url?:string}>>
     */
    private function adjustButtons(array $user, int $postId): array
    {
        $post = $this->posts->find($postId);
        if ($post === null) {
            return Keyboards::approval($postId);
        }
        $flags = $this->previewAdjustFlags($user, $post);

        return Keyboards::adjustMenu(
            $postId,
            $flags['storyLook'],
            $flags['allowRegen'],
            $flags['allowPhoto'],
            $flags['allowMark'],
            $flags['allowIdea'],
            $flags['allowPhrase'],
        );
    }

    /**
     * @param array<string, mixed> $post
     */
    private function showApprovalMenu(int $chatId, array $post): void
    {
        $previewId = $post['preview_message_id'] !== null ? (int) $post['preview_message_id'] : null;
        if ($previewId === null) {
            return;
        }
        $this->channel->editButtons($chatId, $previewId, Keyboards::approval((int) $post['id']));
    }

    /**
     * @param array<string, mixed> $post
     */
    private function showStoryLookMenu(int $chatId, array $post): void
    {
        $previewId = $post['preview_message_id'] !== null ? (int) $post['preview_message_id'] : null;
        if ($previewId === null) {
            return;
        }
        $this->channel->editButtons($chatId, $previewId, $this->storyLookButtons((int) $post['id']));
    }

    /**
     * @return list<list<array{text:string, callback_data?:string, url?:string}>>
     */
    private function storyLookButtons(int $postId): array
    {
        $post = $this->posts->find($postId) ?? [];
        $owner = $this->users->find((int) ($post['user_id'] ?? 0)) ?? [];

        return Keyboards::storyLook(
            $postId,
            PhraseStyle::storyChoice($this->phraseStyleOf($owner, $post)),
            $this->phrasePlaceOf($owner, $post),
            $this->phraseColorOf($owner, $post),
        );
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
        $can = $this->aiAllowed((int) $user['id']);
        $view = $this->profileView($user);
        $this->users->update((int) $user['id'], ['idea_text' => BenefitOrchestrator::suggestion($view, $now)]);
        $this->channel->sendText($chatId, BenefitOrchestrator::ideaMessage($view, $now, $can), Keyboards::ideaActions($can));
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
        $view = $this->profileView($user);
        $row = $this->users->find((int) $user['id']);
        $idea = trim((string) (is_array($row) ? ($row['idea_text'] ?? '') : ''));
        if ($idea === '' || !$this->storedIdeaMatchesProfile($idea, $view)) {
            $this->showIdea($user, $chatId);

            return;
        }
        $this->startIdea($view, $chatId, $idea, null);
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
        if ((int) $now->format('G') < 8) {
            return;
        }
        $limit = $now->modify('-1 day');
        foreach ($this->users->dueDailyIdeas($now->format('Y-m-d')) as $user) {
            $chatId = (int) ($user['telegram_chat_id'] ?? 0);
            if ($chatId === 0) {
                continue;
            }
            $view = $this->users->userForPerfil($user);
            $idea = BenefitOrchestrator::suggestion($view, $now);
            $last = $this->posts->lastPublishedAt((int) $user['id']);
            $quiet = true;
            if ($last !== null) {
                $published = new DateTimeImmutable($last, new DateTimeZone('America/Sao_Paulo'));
                $quiet = $published < $limit;
            }
            $can = $this->aiAllowed((int) $user['id']);
            $this->users->update((int) $user['id'], [
                'idea_text' => $idea,
                'idea_sent_on' => $now->format('Y-m-d'),
            ]);
            $text = $quiet
                ? BenefitOrchestrator::nudge($view, $now, $can)
                : BenefitOrchestrator::ideaMessage($view, $now, $can);
            $this->channel->sendText($chatId, $text, Keyboards::ideaActions($can));
        }
    }

    /**
     * @param array<string, mixed> $user
     */
    public function surprise(array $user, int $chatId): void
    {
        if (!$this->aiAllowed((int) $user['id'])) {
            $this->channel->sendText($chatId, Messages::aiPlan());

            return;
        }
        $view = $this->profileView($user);
        $now = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
        $row = $this->users->find((int) $user['id']);
        $stored = trim((string) (is_array($row) ? ($row['idea_text'] ?? '') : ''));
        if ($stored !== '' && $this->storedIdeaMatchesProfile($stored, $view)) {
            $this->offerSurprisePreview(
                $view,
                $chatId,
                mb_substr($stored, 0, 1000),
                BenefitOrchestrator::photoPhrase($view),
            );

            return;
        }
        $brief = BenefitOrchestrator::surpriseBrief($view, $now);
        $this->offerSurprisePreview($view, $chatId, mb_substr($brief['idea'], 0, 1000), $brief['phrase']);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function handleSurprisePreviewCallback(array $user, int $chatId, ?string $callbackId, string $data): void
    {
        if ($callbackId !== null && $callbackId !== '') {
            $this->channel->answerCallback($callbackId);
        }
        $userId = (int) $user['id'];
        if ($data === 'sur:cancel') {
            SurpriseDraft::clear($userId);
            $this->users->update($userId, ['pending_action' => null]);
            $this->channel->sendText($chatId, Messages::surprisePreviewCancelled());

            return;
        }
        if ($data === 'sur:add') {
            $this->users->update($userId, ['pending_action' => 'surprise:add']);
            $this->channel->sendText($chatId, Messages::surpriseAskComplement());

            return;
        }
        if ($data !== 'sur:go') {
            return;
        }
        $draft = SurpriseDraft::load($userId);
        if ($draft === null) {
            $this->users->update($userId, ['pending_action' => null]);
            $this->channel->sendText($chatId, Messages::surpriseDraftExpired());

            return;
        }
        SurpriseDraft::clear($userId);
        $this->users->update($userId, ['pending_action' => null]);
        $view = $this->profileView($user);
        $phrase = (string) ($draft['phrase'] ?? '');
        $idea = (string) ($draft['idea'] ?? '');
        $this->startIdea(
            $view,
            $chatId,
            mb_substr($idea, 0, 1000),
            null,
            $phrase !== '' ? $phrase : null,
        );
    }

    /**
     * @param array<string, mixed> $user
     */
    public function handleSurpriseText(array $user, int $chatId, string $text): bool
    {
        $pending = (string) ($user['pending_action'] ?? '');
        if ($pending !== 'surprise:add') {
            return false;
        }
        $complement = trim(strip_tags($text));
        if ($complement === '') {
            $this->channel->sendText($chatId, Messages::surpriseAskComplement());

            return true;
        }
        if (!SurpriseDraft::appendComplement((int) $user['id'], mb_substr($complement, 0, 400))) {
            $this->users->update((int) $user['id'], ['pending_action' => null]);
            $this->channel->sendText($chatId, Messages::surpriseDraftExpired());

            return true;
        }
        $draft = SurpriseDraft::load((int) $user['id']);
        if ($draft === null) {
            $this->users->update((int) $user['id'], ['pending_action' => null]);
            $this->channel->sendText($chatId, Messages::surpriseDraftExpired());

            return true;
        }
        $this->users->update((int) $user['id'], ['pending_action' => 'surprise:wait']);
        $this->channel->sendText(
            $chatId,
            Messages::surpriseComplementSaved() . "\n\n" . $this->surprisePreviewText($user, (string) $draft['idea'], (string) $draft['phrase']),
            Keyboards::surprisePreview(),
        );

        return true;
    }

    /**
     * @param array<string, mixed> $user
     */
    private function offerSurprisePreview(array $user, int $chatId, string $idea, string $phrase): void
    {
        $userId = (int) $user['id'];
        SurpriseDraft::clear($userId);
        if (!SurpriseDraft::save($userId, $idea, $phrase)) {
            $this->channel->sendText($chatId, Messages::ideaFailed());

            return;
        }
        $this->users->update($userId, ['pending_action' => 'surprise:wait']);
        $this->channel->sendText($chatId, $this->surprisePreviewText($user, $idea, $phrase), Keyboards::surprisePreview());
    }

    /**
     * @param array<string, mixed> $user
     */
    private function surprisePreviewText(array $user, string $idea, string $phrase): string
    {
        $accountId = $this->users->resolveProfileAccountId((int) $user['id']) ?? 0;
        $matched = $accountId > 0
            ? PromptExtras::matched($this->promptExtraRepo()->listForAccount($accountId), $idea)
            : [];
        $captionExtra = PromptExtras::formatForCaption($matched);
        $parts = [
            Messages::surprisePreviewHeader(),
            '',
            'Ideia (imagem e legenda):',
            $idea,
        ];
        if ($phrase !== '' && !IdeaImage::isDesigned($idea)) {
            $parts[] = '';
            $parts[] = 'Frase sugerida na foto:';
            $parts[] = $phrase;
        }
        if ($captionExtra !== '') {
            $parts[] = '';
            $parts[] = 'Contexto extra (gatilhos):';
            $parts[] = $captionExtra;
        }
        $parts[] = '';
        $parts[] = Messages::surprisePreviewFooter();

        return implode("\n", $parts);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $post
     */
    private function publish(array $user, int $chatId, array $post, string $destination = 'feed'): void
    {
        $destination = $destination === 'story' ? 'story' : 'feed';
        if ($destination === 'story' && !$this->storyMediaReady((int) $post['id'])) {
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

    private function storyMediaReady(int $postId): bool
    {
        $images = 0;
        $videos = 0;
        foreach ($this->posts->media($postId) as $row) {
            if (empty($row['public_name']) && ($row['kind'] ?? 'image') !== 'video') {
                continue;
            }
            if (($row['kind'] ?? 'image') === 'video') {
                $videos++;
                continue;
            }
            $images++;
        }

        return ($videos === 1 && $images === 0) || ($videos === 0 && $images >= 1 && $images <= 3);
    }

    /**
     * @param array<string, mixed> $ig
     * @param list<string> $urls
     */
    private function publishStorySet(array $ig, int $postId, array $urls, bool $video, int $alreadySent): PublishedMedia
    {
        if ($alreadySent >= count($urls) && $urls !== []) {
            $current = $this->posts->find($postId) ?? [];

            return new PublishedMedia(
                (string) ($current['ig_container_id'] ?? ''),
                (string) ($current['ig_media_id'] ?? ''),
                (string) ($current['ig_permalink'] ?? ''),
            );
        }
        $last = null;
        foreach ($urls as $index => $url) {
            if ($index < $alreadySent || $url === '') {
                continue;
            }
            $last = $this->publisher->publishStory(
                (string) $ig['ig_user_id'],
                (string) $ig['access_token'],
                $url,
                $video && count($urls) === 1,
                null,
                null,
                function (string $field, string $value) use ($postId): void {
                    if ($field === 'container') {
                        $this->posts->update($postId, ['ig_container_id' => $value]);
                    }
                    if ($field === 'media') {
                        $this->posts->update($postId, ['ig_media_id' => $value]);
                    }
                },
            );
            $this->posts->update($postId, [
                'story_sent' => $index + 1,
                'ig_container_id' => $last->containerId,
                'ig_media_id' => $last->mediaId,
                'ig_permalink' => $last->permalink,
            ]);
        }
        if ($last === null) {
            $current = $this->posts->find($postId) ?? [];

            return new PublishedMedia(
                (string) ($current['ig_container_id'] ?? ''),
                (string) ($current['ig_media_id'] ?? ''),
                (string) ($current['ig_permalink'] ?? ''),
            );
        }

        return $last;
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

        $ig = $this->resolveInstagram($user, $post);
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
                $asStory = (string) ($current['destination'] ?? 'feed') === 'story';
                $published = $asStory
                    ? $this->publishStorySet($ig, $postId, $urls, $isVideo, (int) ($current['story_sent'] ?? 0))
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
            $post = $this->posts->find($postId);
            $accountId = is_array($post) ? (int) ($post['instagram_account_id'] ?? 0) : 0;
            if ($accountId > 0) {
                $this->users->markInstagramStatusById($accountId, 'expired');
            } else {
                $this->users->markInstagramStatus((int) $user['id'], 'expired');
            }
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
        if (!$this->posts->transition($postId, PostStatus::Publishing, PostStatus::AwaitingApproval)) {
            $this->posts->update($postId, ['status' => PostStatus::AwaitingApproval->value]);
        }
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
        $story = (string) ($post['destination'] ?? 'feed') === 'story';
        $this->channel->sendText($chatId, $story ? Messages::askStoryText() : Messages::askFeedback());
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
            $this->sendPreview($user, $chatId, $postId, $this->adjustButtons($user, $postId));

            return;
        }
        $this->posts->update($postId, [
            'idea_regen_count' => ((int) ($post['idea_regen_count'] ?? 0)) + 1,
            'last_feedback' => null,
        ]);
        $this->generateAndPreview((int) $user['id'], $chatId, $postId, true, true);
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
        $this->generateAndPreview((int) $user['id'], $chatId, (int) $post['id'], true, true);
    }

    /**
     * @param array<string, mixed> $post
     */
    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $post
     */
    private function askManual(array $user, int $chatId, array $post): void
    {
        if (!$this->leaveMenu((int) $post['id'], PostStatus::AwaitingManualEdit)) {
            $this->channel->sendText($chatId, Messages::alreadyProcessed());

            return;
        }
        $story = (string) ($post['destination'] ?? 'feed') === 'story';
        $this->channel->sendText($chatId, $story ? Messages::askStoryManual() : Messages::askManual());
        $editable = \PerfilEmDia\Ai\CaptionGenerator::legendaForEdit(
            (string) ($post['caption'] ?? ''),
            (string) ($user['contact_cta'] ?? ''),
            $story,
        );
        $this->sendEditableSnippet($chatId, $editable);
    }

    private function sendEditableSnippet(int $chatId, string $text): void
    {
        $text = trim($text);
        if ($text === '') {
            return;
        }
        $this->channel->sendText($chatId, Messages::editableTextIntro());
        $this->channel->sendText($chatId, $text);
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
    public function askWhere(int $chatId, ?array $user = null): void
    {
        $pending = is_array($user) ? (string) ($user['pending_action'] ?? '') : '';
        if (
            is_array($user)
            && (
                str_starts_with($pending, 'kind:')
                || str_starts_with($pending, 'where:')
                || str_starts_with($pending, 'phrase:')
                || str_starts_with($pending, 'sched:')
                || str_starts_with($pending, 'aiv:')
                || str_starts_with($pending, 'surprise:')
            )
        ) {
            $this->users->update((int) $user['id'], ['pending_action' => null]);
            if (str_starts_with($pending, 'surprise:')) {
                SurpriseDraft::clear((int) $user['id']);
            }
        }
        $this->channel->sendText($chatId, Messages::askWhere(), Keyboards::where());
    }

    /**
     * @param array<string, mixed> $user
     */
    public function chooseWhere(array $user, int $chatId, string $where): void
    {
        $where = $where === 'story' ? 'story' : 'feed';
        $this->users->update((int) $user['id'], ['pending_action' => 'where:' . $where]);
        $user['pending_action'] = 'where:' . $where;
        $this->sendKindMenu($user, $chatId, $where);
    }

    public function askPostKind(int $chatId, ?array $user = null): void
    {
        $where = is_array($user) ? $this->chosenDestination($user) : 'feed';
        if (is_array($user)) {
            $this->sendKindMenu($user, $chatId, $where);

            return;
        }
        $this->channel->sendText($chatId, Messages::askPostKind(), Keyboards::postKind(false));
    }

    /**
     * @param array<string, mixed> $user
     */
    private function sendKindMenu(array $user, int $chatId, string $where): void
    {
        if ($where === 'story') {
            $this->channel->sendText($chatId, Messages::askStoryKind(), Keyboards::storyKind());

            return;
        }
        $this->channel->sendText($chatId, Messages::askPostKind(), Keyboards::postKind($this->aiVideoOn($user)));
    }

    /**
     * @param array<string, mixed> $user
     */
    public function choosePostKind(array $user, int $chatId, string $kind): void
    {
        if (!in_array($kind, ['foto', 'album', 'video', 'ia', 'aivideo', 'surpresa'], true)) {
            $this->askPostKind($chatId, $user);

            return;
        }
        if ($kind === 'aivideo') {
            if (!$this->aiVideoOn($user)) {
                $this->channel->sendText($chatId, Messages::aiVideoOff());

                return;
            }
            $this->channel->sendText($chatId, Messages::askVideoSeconds(), Keyboards::videoSeconds());

            return;
        }
        if ($kind === 'video' && !$this->videoAllowed((int) $user['id'])) {
            $this->channel->sendText($chatId, Messages::videoPlan());

            return;
        }
        if ($kind === 'surpresa') {
            $this->surprise($user, $chatId);

            return;
        }
        if ($kind === 'ia' && !$this->aiAllowed((int) $user['id'])) {
            $this->channel->sendText($chatId, Messages::aiPlan());

            return;
        }
        if ($kind !== 'ia') {
            IdeaReference::clearStash((int) $user['id']);
        }
        $where = $this->chosenDestination($user);
        if ($where === 'story' && in_array($kind, ['album', 'aivideo', 'surpresa'], true)) {
            $this->channel->sendText($chatId, Messages::storyKindInvalid());
            $this->sendKindMenu($user, $chatId, 'story');

            return;
        }
        $mark = $where === 'story' ? '@story' : '';
        $this->users->update((int) $user['id'], ['pending_action' => 'kind:' . $kind . $mark]);
        $user['pending_action'] = 'kind:' . $kind . $mark;
        $text = match ($kind) {
            'album' => Messages::kindAlbum(),
            'video' => $where === 'story' ? Messages::kindStoryVideo() : Messages::kindVideo(),
            'ia' => $where === 'story' ? Messages::kindStoryIa() : Messages::kindIa(),
            default => $where === 'story' ? Messages::kindStoryFoto() : Messages::kindFoto(),
        };
        $this->channel->sendText($chatId, $text, $kind === 'ia' ? Keyboards::surpriseMe() : null);
        $this->tryConsumeIncomingStash($user, $chatId);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function chooseVideoSeconds(array $user, int $chatId, string $callbackId, int $seconds): void
    {
        $this->channel->answerCallback($callbackId);
        if (!in_array($seconds, [4, 5, 6, 8, 15], true) || !$this->aiVideoOn($user)) {
            $this->channel->sendText($chatId, Messages::aiVideoOff());

            return;
        }
        $seconds = IdeaVideo::duration($seconds);
        $this->users->update((int) $user['id'], ['pending_action' => 'aiv:' . $seconds]);
        $this->channel->sendText($chatId, Messages::askAiVideo($seconds));
    }

    /**
     * @param array<string, mixed> $user
     */
    public function handleAiVideoText(array $user, int $chatId, string $text): bool
    {
        $pending = (string) ($user['pending_action'] ?? '');
        if (preg_match('/^aiv:(4|5|6|8|15)$/', $pending, $seconds) !== 1) {
            return false;
        }
        $idea = trim(strip_tags($text));
        if ($idea === '') {
            $this->channel->sendText($chatId, Messages::askAiVideo((int) $seconds[1]));

            return true;
        }
        $this->startAiVideo($user, $chatId, mb_substr($idea, 0, 1000), IdeaVideo::duration((int) $seconds[1]), null);

        return true;
    }

    /**
     * @param array<string, mixed> $user
     */
    public function replyWhenIdle(array $user, int $chatId): void
    {
        $pending = (string) ($user['pending_action'] ?? '');
        if ($pending === 'surprise:wait') {
            $draft = SurpriseDraft::load((int) $user['id']);
            if ($draft !== null) {
                $this->channel->sendText(
                    $chatId,
                    Messages::surprisePreviewReminder() . "\n\n" . $this->surprisePreviewText($user, (string) $draft['idea'], (string) $draft['phrase']),
                    Keyboards::surprisePreview(),
                );

                return;
            }
            $this->users->update((int) $user['id'], ['pending_action' => null]);
        }
        if ($pending === 'surprise:add') {
            $this->channel->sendText($chatId, Messages::surpriseAskComplement());

            return;
        }
        if (str_starts_with($pending, 'where:')) {
            $this->sendKindMenu($user, $chatId, substr($pending, 6) === 'story' ? 'story' : 'feed');

            return;
        }
        if (str_starts_with($pending, 'kind:')) {
            $kind = explode('@', substr($pending, 5), 2)[0];
            $where = $this->chosenDestination($user);
            $text = match ($kind) {
                'album' => Messages::kindAlbum(),
                'video' => $where === 'story' ? Messages::kindStoryVideo() : Messages::kindVideo(),
                'ia' => $where === 'story' ? Messages::kindStoryIa() : Messages::kindIaNeedText(),
                default => $where === 'story' ? Messages::kindStoryFoto() : Messages::kindFoto(),
            };
            $this->channel->sendText($chatId, $text);

            return;
        }
        if ($this->posts->findPendingForUser((int) $user['id']) !== null) {
            $this->channel->sendText($chatId, Messages::useOpenButtons());

            return;
        }
        if ($this->posts->generatingVideoForUser((int) $user['id'])) {
            $this->channel->sendText($chatId, Messages::aiVideoBusy());

            return;
        }
        $this->channel->sendText($chatId, Messages::textWithoutStep($this->aiVideoOn($user)));
    }

    public function finishAiVideos(): void
    {
        foreach ($this->posts->pendingVideoJobs() as $post) {
            $postId = (int) $post['id'];
            $user = $this->users->find((int) $post['user_id']);
            if ($user === null || empty($user['telegram_chat_id'])) {
                continue;
            }
            $chatId = (int) $user['telegram_chat_id'];
            try {
                $job = $this->videoGenerator()->status((string) $post['video_job_id']);
            } catch (\Throwable $e) {
                Logger::get()->error('Video IA status falhou', ['post_id' => $postId, 'error' => $e->getMessage()]);
                $this->pingVideoWait($post, $chatId);
                continue;
            }
            $status = $job['status'];
            if ($status === 'pending' || $status === 'in_progress' || $status === '') {
                $created = strtotime((string) ($post['created_at'] ?? ''));
                if ($created !== false && $created < time() - 1200) {
                    $this->failPost($postId, PostStatus::Generating, 'video_timeout', 'timeout');
                    $this->channel->sendText($chatId, Messages::aiVideoFailed());
                } else {
                    $this->pingVideoWait($post, $chatId);
                }
                continue;
            }
            if ($status !== 'completed' || $job['url'] === null) {
                $error = (string) ($job['error'] ?? '');
                Logger::get()->error('Video IA recusado', [
                    'post_id' => $postId,
                    'status' => $status,
                    'error' => $error,
                ]);
                $this->failPost($postId, PostStatus::Generating, 'video_failed', $status);
                $audio = stripos($error, 'copyright') !== false;
                $this->channel->sendText($chatId, $audio ? Messages::aiVideoAudioRefused() : Messages::aiVideoFailed());
                continue;
            }
            try {
                $binary = $this->videoGenerator()->download($job['url']);
                $dest = Config::root() . '/storage/media/' . $postId . '_0.mp4';
                $dir = dirname($dest);
                if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                    throw new \RuntimeException('Nao foi possivel criar storage/media');
                }
                if (file_put_contents($dest, $binary) === false) {
                    throw new \RuntimeException('Nao foi possivel gravar o video');
                }
                $mediaId = $this->posts->addMedia($postId, 0, 'aivideo', 0, 'video');
                $this->posts->updateMedia($mediaId, [
                    'original_path' => $dest,
                    'width' => 720,
                    'height' => 1280,
                ]);
                $this->generateAndPreview((int) $user['id'], $chatId, $postId);
            } catch (\Throwable $e) {
                $tries = (int) ($post['attempts'] ?? 0) + 1;
                $this->posts->update($postId, ['attempts' => $tries]);
                Logger::get()->error('Video IA download falhou', ['post_id' => $postId, 'error' => $e->getMessage()]);
                if ($tries >= 4) {
                    $this->failPost($postId, PostStatus::Generating, 'video_download', 'download');
                    $this->channel->sendText($chatId, Messages::aiVideoFailed());
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed>|null $message
     */
    private function startAiVideo(array $user, int $chatId, string $idea, int $seconds, ?array $message): void
    {
        if (!$this->aiVideoOn($user)) {
            $this->users->update((int) $user['id'], ['pending_action' => null]);
            $this->channel->sendText($chatId, Messages::aiVideoOff());

            return;
        }
        if ($idea === '') {
            $this->channel->sendText($chatId, Messages::askAiVideo($seconds));

            return;
        }
        if (!$this->subscriptionAllows($user, $chatId)) {
            return;
        }
        if ($this->posts->generatingVideoForUser((int) $user['id'])) {
            $this->channel->sendText($chatId, Messages::aiVideoBusy());

            return;
        }
        $this->users->update((int) $user['id'], ['pending_action' => null]);
        $this->channel->sendText($chatId, Messages::aiVideoStarted($seconds));
        $postId = $this->posts->create((int) $user['id'], PostStatus::Generating, $idea, null, $this->activeInstagramAccountId($user));
        SlowNotice::arm($postId, $chatId);
        $this->rememberDestination($user, $postId);
        $this->posts->update($postId, ['creative' => 1, 'video_seconds' => $seconds]);
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
            $prompt = $this->videoPrompt(
                $idea,
                trim(BenefitOrchestrator::brandBrief($this->profileView($user))),
                $reference !== null,
            );
            $jobId = $this->videoGenerator()->submit($prompt, $seconds, $reference);
            $this->posts->update($postId, ['video_job_id' => $jobId]);
        } catch (\Throwable $e) {
            Logger::get()->error('Video IA pedido falhou', ['post_id' => $postId, 'error' => $e->getMessage()]);
            $this->failPost($postId, PostStatus::Generating, 'video_submit', 'submit');
            $this->channel->sendText($chatId, Messages::aiVideoFailed());
        } finally {
            if (is_string($reference) && is_file($reference)) {
                unlink($reference);
            }
        }
    }

    /**
     * @param array<string, mixed> $post
     */
    private function pingVideoWait(array $post, int $chatId): void
    {
        $created = strtotime((string) ($post['created_at'] ?? ''));
        if ($created === false || $created > time() - 45) {
            return;
        }
        $last = strtotime((string) ($post['video_ping_at'] ?? ''));
        if ($last !== false && $last > time() - 50) {
            return;
        }
        $seconds = (int) ($post['video_seconds'] ?? 0);
        $this->channel->sendText($chatId, Messages::aiVideoWaiting($seconds > 0 ? $seconds : 8));
        $this->posts->update((int) $post['id'], [
            'video_ping_at' => (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s'),
        ]);
    }

    private function videoPrompt(string $idea, string $brand, bool $hasReference): string
    {
        $prompt = 'Create one realistic vertical Instagram video. Natural motion, steady and clear. ';
        $prompt .= 'Silent video. No audio, no music, no soundtrack, no voice, no singing, and no sound effects. If the scene mentions music or sound, ignore it. ';
        $phrase = $this->onScreenPhrase($idea);
        if ($phrase !== null) {
            $prompt .= 'Show only this exact sentence on screen, spelled as written: "' . $phrase . '". Do not speak it. No other text, letters, numbers, logos, or watermarks. ';
        } else {
            $prompt .= 'No text, letters, numbers, logos, or watermarks. ';
        }
        if ($brand !== '') {
            $prompt .= $brand . ' ';
        }
        if ($hasReference) {
            $prompt .= 'The attached image is the first frame. Keep the same subject and place. ';
        }
        $prompt .= 'The scene: ' . $idea;

        return $prompt;
    }

    private function onScreenPhrase(string $idea): ?string
    {
        if (preg_match('/\bfrase\b\s*[:\-]?\s*(.+)$/us', $idea, $matches) !== 1) {
            return null;
        }
        $phrase = trim($matches[1], " \t\n\r\"'");
        $phrase = trim((string) preg_replace('/\s+/u', ' ', $phrase));
        if (mb_strlen($phrase) < 8 || mb_strlen($phrase) > 160) {
            return null;
        }

        return $phrase;
    }

    /**
     * @param list<string> $frames
     */
    private function storeIdeaFrames(int $postId, array $frames, int $messageId): void
    {
        $dir = Config::root() . '/storage/media';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Nao foi possivel criar storage/media');
        }
        $existing = $this->posts->media($postId);
        $publicDir = Config::root() . '/public/m';
        foreach ($frames as $index => $jpeg) {
            $dest = $dir . '/' . $postId . '_' . $index . '.jpg';
            if (file_put_contents($dest, $jpeg) === false) {
                throw new RuntimeException('Nao foi possivel gravar a imagem');
            }
            $row = $existing[$index] ?? null;
            if (is_array($row)) {
                $this->posts->updateMedia((int) $row['id'], ['original_path' => $dest]);
                continue;
            }
            $mediaId = $this->posts->addMedia($postId, $index, 'idea', $messageId);
            $this->posts->updateMedia($mediaId, ['original_path' => $dest]);
        }
        for ($index = count($frames); $index < count($existing); $index++) {
            $row = $existing[$index];
            $name = (string) ($row['public_name'] ?? '');
            if ($name !== '') {
                $public = $publicDir . '/' . $name . '.jpg';
                if (is_file($public)) {
                    unlink($public);
                }
            }
            $this->posts->deleteMedia((int) $row['id']);
        }
    }

    private function wakeDatabase(): void
    {
        $pdo = Db::reconnect();
        $this->posts->bind($pdo);
        $this->users->bind($pdo);
        $this->access?->bind($pdo);
    }

    private function videoGenerator(): IdeaVideoGenerator
    {
        return $this->videos ?? new IdeaVideo();
    }

    /**
     * @param array<string, mixed>|null $user
     */
    private function aiVideoOn(?array $user): bool
    {
        if (!is_array($user) || empty($user['id'])) {
            return false;
        }

        return $this->users->aiVideoEnabled((int) $user['id']);
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
    private function startIdea(array $user, int $chatId, string $idea, ?array $message, ?string $surprisePhrase = null): void
    {
        $userId = (int) $user['id'];
        if (!$this->aiAllowed($userId)) {
            $this->channel->sendText($chatId, Messages::aiPlan());

            return;
        }
        $profile = $this->profileView($user);
        $this->users->update($userId, ['pending_action' => null]);
        $aspect = $this->chosenDestination($user) === 'story' ? '9:16' : '4:5';
        [$idea, $rolled] = IdeaPieces::dress($idea, $aspect, $surprisePhrase !== null);
        $designed = IdeaImage::isDesigned($idea);
        if ($surprisePhrase !== null) {
            $this->channel->sendText($chatId, $designed ? Messages::surpriseStartedDesign() : Messages::surpriseStarted());
        } else {
            $this->channel->sendText($chatId, Messages::ideaWorking());
        }
        if ($rolled !== '') {
            $this->channel->sendText($chatId, Messages::ideaFormatPicked($rolled));
        }
        $postId = $this->posts->create((int) $user['id'], PostStatus::Generating, $idea, null, $this->activeInstagramAccountId($user));
        $this->rememberDestination($user, $postId);
        $this->posts->update($postId, ['creative' => 1]);

        $reference = $this->resolveIdeaReference($user, $postId, $message);
        $matchedExtras = $this->matchedPromptExtras($user, ['instagram_account_id' => $this->activeInstagramAccountId($user)], $idea);
        $reference = $this->ideaApiReference($reference, $idea, $matchedExtras);
        $look = $this->ideaLookBrief($profile, $idea, $matchedExtras);

        try {
            $aspect = $this->chosenDestination($user) === 'story' ? '9:16' : '4:5';
            $count = count(IdeaImage::pieces($idea, $aspect));
            if ($count > 1) {
                $this->channel->sendText($chatId, Messages::ideaSlidesStarted($count, $aspect === '9:16'));
            }
            $generator = $this->ideas ?? new IdeaImage();
            $frames = $generator instanceof IdeaImage
                ? $generator->createSet($idea, $reference, $look, $aspect)
                : [$generator->create($idea, $reference, $look, $aspect)];
            $frames = $this->applyBrandLogoOverlays($frames, $idea, $matchedExtras);
            $this->wakeDatabase();
            $this->storeIdeaFrames($postId, $frames, (int) ($message['message_id'] ?? 0));
            $this->generateAndPreview($userId, $chatId, $postId, $surprisePhrase === null);
            if ($surprisePhrase !== null) {
                $this->dressSurprise($user, $chatId, $postId, $surprisePhrase);
            }
        } catch (\Throwable $e) {
            Logger::get()->error('Imagem da ideia falhou', ['post_id' => $postId, 'error' => $e->getMessage()]);
            try {
                $this->wakeDatabase();
                $this->failPost($postId, PostStatus::Generating, 'idea_image', $e->getMessage());
            } catch (\Throwable) {
            }
            $this->channel->sendText($chatId, Messages::ideaFailed());
        } finally {
            IdeaReference::clearStash((int) $user['id']);
        }
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed>|null $message
     */
    private function resolveIdeaReference(array $user, int $postId, ?array $message): ?string
    {
        if ($message !== null) {
            $file = $this->extractFile($message);
            if ($file !== null && $file['kind'] === 'image') {
                $reference = IdeaReference::postPath($postId);
                $dir = dirname($reference);
                if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                    return IdeaReference::adoptStash((int) $user['id'], $postId);
                }
                try {
                    $this->channel->download($file['file_id'], $reference);
                    IdeaReference::clearStash((int) $user['id']);

                    return $reference;
                } catch (\Throwable) {
                    return IdeaReference::adoptStash((int) $user['id'], $postId);
                }
            }
        }

        return IdeaReference::adoptStash((int) $user['id'], $postId);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $message
     */
    /**
     * @param array<string, mixed> $user
     */
    private function tryConsumeIncomingStash(array $user, int $chatId): void
    {
        $payload = IncomingMediaStash::load((int) $user['id']);
        if ($payload === null) {
            return;
        }
        $message = IncomingMediaStash::toMessage($payload);
        $kind = $this->selectedKind($user);
        if ($kind === null) {
            return;
        }

        if ($kind === 'ia') {
            $idea = $this->extractTheme($message);
            IncomingMediaStash::clear((int) $user['id']);
            if ($idea !== null && $idea !== '') {
                $this->startIdea($user, $chatId, $idea, $message);

                return;
            }
            if ($this->stashIdeaReference($user, $message)) {
                $this->channel->sendText($chatId, Messages::kindIaNeedTextWithPhoto());
            }

            return;
        }

        if ($kind === 'album') {
            if (!isset($message['media_group_id'])) {
                return;
            }
        }

        $block = $this->incomingBlock($user, $message);
        if ($block !== null) {
            $this->channel->sendText($chatId, $block);

            return;
        }

        IncomingMediaStash::clear((int) $user['id']);

        if ($kind === 'album' && isset($message['media_group_id'])) {
            $this->handleAlbumItem($user, $chatId, $message, (string) $message['media_group_id']);

            return;
        }

        if ($kind === 'foto' || $kind === 'video') {
            $this->startSinglePost($user, $chatId, $message);
        }
    }

    private function stashIdeaReference(array $user, array $message): bool
    {
        $file = $this->extractFile($message);
        if ($file === null || $file['kind'] !== 'image') {
            return false;
        }
        $path = IdeaReference::stashPath((int) $user['id']);
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        try {
            $this->channel->download($file['file_id'], $path);
        } catch (\Throwable) {
            return false;
        }

        return is_file($path) && filesize($path) > 0;
    }

    /**
     * @param array<string, mixed> $user
     */
    private function dressSurprise(array $user, int $chatId, int $postId, string $phrase): void
    {
        $post = $this->posts->find($postId);
        if (!is_array($post) || !$this->inMenu($post)) {
            return;
        }
        $marked = false;
        $designed = IdeaImage::isDesigned((string) ($post['theme_text'] ?? ''));
        if ((string) ($post['destination'] ?? 'feed') === 'story') {
            $this->sendPreview($user, $chatId, $postId);
            $this->channel->sendText($chatId, $designed ? Messages::surpriseReadyDesign() : Messages::surpriseReadyStory());

            return;
        }
        if ($designed) {
            $this->sendPreview($user, $chatId, $postId);
            $this->channel->sendText($chatId, Messages::surpriseReadyDesign());

            return;
        }
        if ($this->photoEditAllowed((int) $user['id'])) {
            $marked = $this->applySurpriseDress($user, $postId, $phrase);
        }
        $this->sendPreview($user, $chatId, $postId);
        $this->channel->sendText($chatId, $marked ? Messages::surpriseReady() : Messages::surpriseReadyPlain());
    }

    /**
     * @param array<string, mixed> $user
     */
    private function applySurpriseDress(array $user, int $postId, string $phrase): bool
    {
        $media = $this->posts->media($postId);
        $first = $media[0] ?? null;
        if ($first === null || ($first['kind'] ?? 'image') === 'video' || empty($first['public_name'])) {
            return false;
        }
        $publicDir = Config::root() . '/public/m';
        $current = $publicDir . '/' . $first['public_name'] . '.jpg';
        if (!is_file($current)) {
            return false;
        }
        $this->rememberPhraseBase((int) $first['id'], $current);
        $base = $this->phraseBasePath((int) $first['id']);
        $name = bin2hex(random_bytes(20));
        $dest = $publicDir . '/' . $name . '.jpg';
        if (!is_file($base) || !copy($base, $dest)) {
            return false;
        }
        $post = $this->posts->find($postId) ?? ['destination' => 'feed'];
        $this->drawPhrase($user, $post, $dest, $phrase);
        $this->posts->update($postId, ['photo_phrase' => $phrase]);
        $marked = $this->stampSurpriseMark($user, (int) $first['id'], $dest);
        $this->posts->updateMedia((int) $first['id'], ['public_name' => $name]);
        $this->freezeEditedPhoto($first, $dest);
        if (is_file($current)) {
            unlink($current);
        }

        return $marked;
    }

    /**
     * @param array<string, mixed> $user
     */
    private function stampSurpriseMark(array $user, int $mediaId, string $dest): bool
    {
        $logo = $this->logoFile($user);
        if ($logo !== null) {
            $ok = PhotoMark::stampPlate($dest, $logo, 'br');
            if ($ok) {
                $this->stampPhraseBase($mediaId, $logo, 'br', true);
            }

            return $ok;
        }
        $ig = $this->users->instagramAccount((int) $user['id']);
        if (!is_array($ig) || ($ig['status'] ?? '') !== 'active' || empty($ig['access_token'])) {
            return false;
        }
        try {
            $bytes = (new InstagramClient())->profilePicture((string) $ig['access_token']);
        } catch (\Throwable) {
            return false;
        }
        if ($bytes === null) {
            return false;
        }
        $temp = tempnam(sys_get_temp_dir(), 'mk');
        if ($temp === false) {
            return false;
        }
        file_put_contents($temp, $bytes);
        try {
            $ok = PhotoMark::stamp($dest, $temp, 'br');
            if ($ok) {
                $this->stampPhraseBase($mediaId, $temp, 'br', false);
            }

            return $ok;
        } finally {
            if (is_file($temp)) {
                unlink($temp);
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
        $kind = explode('@', substr($pending, 5), 2)[0];

        return in_array($kind, ['foto', 'album', 'video', 'ia'], true) ? $kind : null;
    }

    /**
     * @param array<string, mixed> $user
     */
    private function chosenDestination(array $user): string
    {
        $pending = (string) ($user['pending_action'] ?? '');
        $fromPost = $this->destinationFromPendingAction($pending);
        if ($fromPost !== null) {
            return $fromPost;
        }
        if (str_starts_with($pending, 'where:')) {
            return substr($pending, 6) === 'story' ? 'story' : 'feed';
        }
        if (str_contains($pending, '@story')) {
            return 'story';
        }

        return 'feed';
    }

    private function destinationFromPendingAction(string $pendingAction): ?string
    {
        if (preg_match('/^(phrase|logo|sched):(\d+)$/', $pendingAction, $match) !== 1) {
            return null;
        }
        $post = $this->posts->find((int) $match[2]);
        if ($post === null) {
            return null;
        }

        return (string) ($post['destination'] ?? 'feed') === 'story' ? 'story' : 'feed';
    }

    /**
     * @param array<string, mixed> $pendingPost
     */
    private function shouldBlockMediaWhilePending(string $userPending, array $pendingPost): bool
    {
        if (preg_match('/^(phrase|logo|sched):(\d+)$/', $userPending) === 1) {
            return true;
        }
        if ((string) ($pendingPost['destination'] ?? 'feed') === 'story') {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $user
     */
    private function rememberDestination(array $user, int $postId): void
    {
        if ($this->chosenDestination($user) !== 'story') {
            return;
        }
        $this->posts->update($postId, ['destination' => 'story']);
    }

    /**
     * @param array<string, mixed> $user
     */
    private function maybeSendPublishingAs(array $user, int $chatId): void
    {
        $accounts = $this->users->listInstagramAccounts((int) $user['id']);
        if (count($accounts) <= 1) {
            return;
        }
        $ig = $this->users->instagramAccount((int) $user['id']);
        if ($ig === null) {
            return;
        }
        $this->channel->sendText($chatId, Messages::publishingAs((string) ($ig['username'] ?? '')));
    }

    /**
     * @param array<string, mixed> $user
     */
    private function activeInstagramAccountId(array $user): ?int
    {
        $ig = $this->users->instagramAccount((int) $user['id']);

        return $ig !== null ? (int) $ig['id'] : null;
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed>|null $post
     * @return array<string, mixed>|null
     */
    private function resolveInstagram(array $user, ?array $post = null): ?array
    {
        if ($post !== null) {
            $accountId = (int) ($post['instagram_account_id'] ?? 0);
            if ($accountId > 0) {
                $ig = $this->users->instagramAccountById($accountId, (int) $user['id']);
                if ($ig !== null) {
                    return $ig;
                }
            }
        }

        return $this->users->instagramAccount((int) $user['id']);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed>|null $post
     * @return array<string, mixed>
     */
    private function captionContext(array $user, ?array $post = null): array
    {
        $accountId = $post !== null ? (int) ($post['instagram_account_id'] ?? 0) : 0;

        return $this->users->userWithInstagramProfile(
            $user,
            $accountId > 0 ? $accountId : null,
        );
    }

    /**
     * @param array<string, mixed> $view Perfil da @ ativa (userForPerfil).
     */
    private function storedIdeaMatchesProfile(string $stored, array $view): bool
    {
        $who = trim((string) ($view['profession'] ?? ''));
        if ($who === '') {
            return true;
        }
        $needle = mb_substr($who, 0, min(48, mb_strlen($who)));

        return $needle !== '' && mb_stripos($stored, $needle) !== false;
    }

    private function promptExtraRepo(): PromptExtraRepository
    {
        return new PromptExtraRepository(Db::pdo());
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed>|null $post
     * @return list<array<string, mixed>>
     */
    private function matchedPromptExtras(array $user, ?array $post, string ...$textParts): array
    {
        $accountId = $post !== null ? (int) ($post['instagram_account_id'] ?? 0) : 0;
        if ($accountId <= 0) {
            $accountId = $this->users->resolveProfileAccountId((int) $user['id']) ?? 0;
        }
        if ($accountId <= 0) {
            return [];
        }
        $haystack = trim(implode("\n", array_filter($textParts, static fn (string $p): bool => trim($p) !== '')));
        if ($haystack === '') {
            return [];
        }

        return PromptExtras::matched($this->promptExtraRepo()->listForAccount($accountId), $haystack);
    }

    /**
     * @param list<array<string, mixed>> $matchedExtras
     */
    private function ideaApiReference(?string $reference, string $idea, array $matchedExtras): ?string
    {
        if (PromptExtras::isPromptExtraImagePath($reference)) {
            $reference = null;
        }
        if (IdeaImage::isDesigned($idea)) {
            return $reference;
        }

        return $reference ?? PromptExtras::firstImageAbsolute($matchedExtras);
    }

    /**
     * @param array<string, mixed> $profile
     * @param list<array<string, mixed>> $matchedExtras
     */
    private function ideaLookBrief(array $profile, string $idea, array $matchedExtras): string
    {
        $look = IdeaLook::brief($profile, PromptExtras::formatForImageBrief($matchedExtras));
        if (IdeaImage::isDesigned($idea)) {
            $look .= PromptExtras::designedLogoBriefSuffix($matchedExtras);
        }

        return $look;
    }

    /**
     * @param list<string> $frames
     * @param list<array<string, mixed>> $matchedExtras
     * @return list<string>
     */
    private function applyBrandLogoOverlays(array $frames, string $idea, array $matchedExtras): array
    {
        if (!IdeaImage::isDesigned($idea) || PromptExtras::allImageAbsolutes($matchedExtras) === []) {
            return $frames;
        }

        return array_map(
            static fn (string $jpeg): string => PromptExtras::applyLogosToJpegBinary($jpeg, $matchedExtras),
            $frames,
        );
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
