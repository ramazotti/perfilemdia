<?php

declare(strict_types=1);

namespace PerfilEmDia\Site;

use PDO;
use PerfilEmDia\Billing\AccountOrchestrator;
use PerfilEmDia\Billing\PlanAccess;
use PerfilEmDia\Channel\WebStudioChannel;
use PerfilEmDia\Config;
use PerfilEmDia\Domain\IncomingMediaStash;
use PerfilEmDia\Domain\PostRepository;
use PerfilEmDia\Domain\PostService;
use PerfilEmDia\Domain\PostStatus;
use PerfilEmDia\Domain\UserRepository;
use PerfilEmDia\Image\PhraseColor;
use PerfilEmDia\Image\PhrasePlace;
use PerfilEmDia\Image\PhraseSize;
use PerfilEmDia\Image\PhraseStyle;
use RuntimeException;

/**
 * Orquestra assinatura (via AccountOrchestrator), perfil por @, preferências e studio web.
 */
final class PortalOrchestrator
{
    /** @var list<string> */
    private const TONES = ['profissional', 'descontraido', 'tecnico', 'acolhedor'];

    /** @var array<string, int> */
    private const LIMITS = [
        'display_name' => 120,
        'profession' => 120,
        'city' => 120,
        'contact_cta' => 255,
        'about' => 500,
        'brand_style' => 160,
        'fixed_hashtags' => 255,
    ];

    private readonly UserRepository $users;
    private readonly PostRepository $posts;
    private readonly PlanAccess $plans;

    public function __construct(
        private readonly PDO $pdo,
        private readonly AccountOrchestrator $accounts,
    ) {
        $this->users = PortalFactory::users($pdo);
        $this->posts = new PostRepository($pdo);
        $this->plans = new PlanAccess($pdo);
    }

    public function billing(): AccountOrchestrator
    {
        return $this->accounts;
    }

    public function userIdForCustomer(int $customerId): int
    {
        $stmt = $this->pdo->prepare('SELECT user_id FROM customers WHERE id = ? AND status <> ? LIMIT 1');
        $stmt->execute([$customerId, 'excluido']);
        $userId = $stmt->fetchColumn();

        return is_numeric($userId) ? (int) $userId : 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function profileBundle(int $customerId): ?array
    {
        $view = $this->accounts->view($customerId);
        if ($view === null) {
            return null;
        }
        $userId = (int) ($view['customer']['user_id'] ?? 0);
        if ($userId < 1) {
            return $view + [
                'profile' => null,
                'instagram_accounts' => [],
                'max_instagram' => 1,
                'active_account_id' => 0,
            ];
        }
        $user = $this->users->find($userId);
        if ($user === null) {
            return null;
        }
        $profile = $this->users->userForPerfil($user);
        $accounts = $this->users->listInstagramAccounts($userId);
        $activeId = (int) ($user['active_instagram_account_id'] ?? 0);

        return $view + [
            'profile' => $profile,
            'instagram_accounts' => $accounts,
            'max_instagram' => $this->plans->maxInstagramAccounts($userId),
            'active_account_id' => $activeId,
            'idea_daily' => (int) ($user['idea_daily'] ?? 0) === 1,
            'logo_url' => $this->logoUrl($profile),
        ];
    }

    public function setActiveAccount(int $customerId, int $accountId): void
    {
        $userId = $this->requireUser($customerId);
        if (!$this->users->setActiveInstagramAccount($userId, $accountId)) {
            throw new RuntimeException('Conta Instagram inválida.');
        }
    }

    /**
     * @param array<string, string> $fields
     */
    public function saveProfile(int $customerId, array $fields): void
    {
        $userId = $this->requireUser($customerId);
        $patch = [];
        foreach (['display_name', 'profession', 'city', 'tone', 'contact_cta', 'about', 'brand_style', 'fixed_hashtags'] as $key) {
            if (!array_key_exists($key, $fields)) {
                continue;
            }
            $clean = trim(strip_tags($fields[$key]));
            if ($key === 'tone') {
                if (!in_array($clean, self::TONES, true)) {
                    throw new RuntimeException('Escolha um tom válido.');
                }
                $patch['tone'] = $clean;
                continue;
            }
            $limit = self::LIMITS[$key] ?? 120;
            if (in_array($key, ['display_name', 'profession', 'city'], true) && $clean === '') {
                throw new RuntimeException('Preencha os campos obrigatórios.');
            }
            if (mb_strlen($clean) > $limit) {
                throw new RuntimeException('Texto longo demais em ' . $key . '.');
            }
            $patch[$key] = $clean;
        }
        if ($patch === []) {
            return;
        }
        $this->users->update($userId, $patch);
    }

    /**
     * @param array<string, string> $fields
     */
    public function savePreferences(int $customerId, array $fields): void
    {
        $userId = $this->requireUser($customerId);
        $patch = [];
        if (array_key_exists('phrase_style', $fields)) {
            $patch['phrase_style'] = PhraseStyle::normalize($fields['phrase_style']);
        }
        if (array_key_exists('phrase_color', $fields)) {
            $patch['phrase_color'] = PhraseColor::normalize($fields['phrase_color']);
        }
        if (array_key_exists('phrase_place', $fields)) {
            $patch['phrase_place'] = PhrasePlace::normalize($fields['phrase_place']);
        }
        if (array_key_exists('phrase_size', $fields)) {
            $patch['phrase_size'] = PhraseSize::normalize($fields['phrase_size']);
        }
        if (array_key_exists('idea_daily', $fields)) {
            $patch['idea_daily'] = $fields['idea_daily'] === '1' ? 1 : 0;
        }
        if ($patch === []) {
            return;
        }
        $this->users->update($userId, $patch);
    }

    public function saveLogo(int $customerId, string $sourcePath): void
    {
        $userId = $this->requireUser($customerId);
        $accountId = $this->users->resolveProfileAccountId($userId);
        if ($accountId === null) {
            throw new RuntimeException('Conecte uma conta Instagram antes de enviar a logo.');
        }
        $saved = $this->storeLogo($accountId, $sourcePath);
        if ($saved === null) {
            throw new RuntimeException('Use uma imagem PNG ou JPEG.');
        }
        $this->users->update($userId, ['logo_path' => $saved]);
    }

    public function logoPathForCustomer(int $customerId): ?string
    {
        $userId = $this->userIdForCustomer($customerId);
        if ($userId < 1) {
            return null;
        }
        $user = $this->users->find($userId);
        if ($user === null) {
            return null;
        }
        $profile = $this->users->userForPerfil($user);
        $relative = trim((string) ($profile['logo_path'] ?? ''));
        if ($relative === '' || str_contains($relative, '..')) {
            return null;
        }
        $path = Config::root() . '/' . ltrim($relative, '/');

        return is_file($path) ? $path : null;
    }

    /**
     * @return array{messages:list<array<string,mixed>>,expecting:?string,pending_action:string,post:?array<string,mixed>}
     */
    public function studioState(int $customerId): array
    {
        $userId = $this->userIdForCustomer($customerId);
        $messages = $this->sessionMessages($userId);
        if ($userId < 1) {
            return [
                'messages' => $messages,
                'expecting' => null,
                'pending_action' => '',
                'post' => null,
            ];
        }
        $user = $this->users->find($userId);
        if ($user === null) {
            return [
                'messages' => $messages,
                'expecting' => null,
                'pending_action' => '',
                'post' => null,
            ];
        }
        $pending = (string) ($user['pending_action'] ?? '');
        $post = $this->posts->findPendingForUser($userId);
        if ($post === null) {
            $post = $this->posts->findOpenWorkflowForUser($userId);
        }

        return [
            'messages' => $messages,
            'expecting' => $this->expectingInput($user, $post),
            'pending_action' => $pending,
            'post' => $post,
        ];
    }

    public function studioChat(int $customerId, string $text): void
    {
        $user = $this->requireUserRow($customerId);
        $userId = (int) $user['id'];
        $trim = trim($text);
        if ($trim === '') {
            return;
        }
        $this->appendUserMessage($userId, $trim);
        $channel = new WebStudioChannel();
        $posts = PortalFactory::postService($this->pdo, $channel);
        $chatId = (int) ($user['telegram_chat_id'] ?? $userId);
        if ($posts->handleThemeText($user, $chatId, $trim)) {
            $this->flushBot($userId, $channel);

            return;
        }
        if ($trim === '/novo' || strcasecmp($trim, 'novo post') === 0) {
            $posts->beginNewPost($user, $chatId);
            $this->flushBot($userId, $channel);

            return;
        }
        if ($trim === '/cancelar' || strcasecmp($trim, 'cancelar') === 0) {
            $posts->cancelPending($user, $chatId);
            $this->flushBot($userId, $channel);

            return;
        }
        $posts->replyWhenIdle($user, $chatId);
        $this->flushBot($userId, $channel);
    }

    public function studioCallback(int $customerId, string $data): void
    {
        $user = $this->requireUserRow($customerId);
        $userId = (int) $user['id'];
        $channel = new WebStudioChannel();
        $posts = PortalFactory::postService($this->pdo, $channel);
        $chatId = (int) ($user['telegram_chat_id'] ?? $userId);
        $this->routeCallback($posts, $user, $chatId, $data);
        $this->flushBot($userId, $channel);
    }

    /**
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file
     */
    public function studioUpload(int $customerId, array $file, ?string $caption): void
    {
        $user = $this->requireUserRow($customerId);
        $userId = (int) $user['id'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Não foi possível receber o arquivo.');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('Upload inválido.');
        }
        $mime = $this->detectMime($tmp, (string) ($file['type'] ?? ''));
        if (!$this->allowedMime($mime)) {
            throw new RuntimeException('Use foto (JPEG, PNG, WebP) ou vídeo (MP4).');
        }
        $stored = $this->storeUpload($userId, $tmp, $mime);
        $this->appendUserMessage($userId, $caption !== null && $caption !== '' ? $caption : '[mídia enviada]');
        $message = WebMediaMessage::fromUpload($stored, $mime, $caption);
        $channel = new WebStudioChannel();
        $posts = PortalFactory::postService($this->pdo, $channel);
        $chatId = (int) ($user['telegram_chat_id'] ?? $userId);
        $posts->handleIncomingMedia($user, $chatId, $message);
        $this->flushBot($userId, $channel);
    }

    public function studioReset(int $customerId): void
    {
        $userId = $this->userIdForCustomer($customerId);
        if ($userId < 1) {
            return;
        }
        $_SESSION['portal_studio'][$userId] = [];
        IncomingMediaStash::clear($userId);
    }

    public function studioSeed(int $customerId): void
    {
        $userId = $this->userIdForCustomer($customerId);
        if ($userId < 1) {
            return;
        }
        $messages = $this->sessionMessages($userId);
        if ($messages !== []) {
            return;
        }
        $user = $this->users->find($userId);
        $name = is_array($user) ? (string) ($user['display_name'] ?? '') : '';
        $who = $name !== '' ? $name : 'por aqui';
        $this->appendBotMessage($userId, [
            'type' => 'text',
            'text' => "Olá, {$who}. Este é o mesmo fluxo do Telegram: escolha feed ou story, envie a foto ou peça Surpreenda-me, revise e publique.\n\nToque em Novo post ou escreva /novo.",
            'buttons' => [[
                ['text' => 'Novo post', 'data' => 'cmd:novo'],
            ]],
        ]);
    }

    private function routeCallback(PostService $posts, array $user, int $chatId, string $data): void
    {
        if ($data === 'cmd:novo') {
            $posts->beginNewPost($user, $chatId);

            return;
        }
        if ($data === 'novo:sim' || $data === 'novo:nao') {
            $posts->handleReplaceDecision($user, $chatId, $data === 'novo:sim');

            return;
        }
        if ($data === 'wh:feed' || $data === 'wh:story') {
            $posts->chooseWhere($user, $chatId, substr($data, 3));

            return;
        }
        if (str_starts_with($data, 'pk:')) {
            $posts->choosePostKind($user, $chatId, substr($data, 3));

            return;
        }
        if ($data === 'id:sur') {
            $posts->surprise($user, $chatId);

            return;
        }
        if (preg_match('/^a:(pub|adj|reg|man|can|img|txt|wm|sch|uns|sty|pic|mor|look|back):(\d+)$/', $data, $m) === 1) {
            $posts->handleApprovalCallback($user, $chatId, 'web', $m[1], (int) $m[2]);

            return;
        }
        if (preg_match('/^vd:(4|5|6|8|15)$/', $data, $m) === 1) {
            $posts->chooseVideoSeconds($user, $chatId, 'web', (int) $m[1]);

            return;
        }
        if (preg_match('/^s:(t18|n9|n18|in):(\d+)$/', $data, $m) === 1) {
            $posts->chooseSchedule($user, $chatId, 'web', $m[1], (int) $m[2]);

            return;
        }
        if (preg_match('/^f:(cursiva|classica|limpa|forte|balao|caixa):(\d+)$/', $data, $m) === 1) {
            $posts->choosePhraseStyle($user, $chatId, 'web', $m[1], (int) $m[2]);

            return;
        }
        if (preg_match('/^pc:(dourado|branco|preto):(\d+)$/', $data, $m) === 1) {
            $posts->choosePhraseColor($user, $chatId, 'web', $m[1], (int) $m[2]);

            return;
        }
        if (preg_match('/^pp:(topo|meio|rodape):(\d+)$/', $data, $m) === 1) {
            $posts->choosePhrasePlace($user, $chatId, 'web', $m[1], (int) $m[2]);

            return;
        }
        if (preg_match('/^ps:(menor|normal|maior):(\d+)$/', $data, $m) === 1) {
            $posts->choosePhraseSize($user, $chatId, 'web', $m[1], (int) $m[2]);

            return;
        }
        if (preg_match('/^px:(\d+)$/', $data, $m) === 1) {
            $posts->clearPhrase($user, $chatId, 'web', (int) $m[1]);

            return;
        }
        if (preg_match('/^m:(ig|up|ok):(\d+)$/', $data, $m) === 1) {
            $posts->chooseMarkSource($user, $chatId, 'web', $m[1], (int) $m[2]);

            return;
        }
        if (preg_match('/^w:(tl|tr|bl|br|c):(ig|lg):(\d+)$/', $data, $m) === 1) {
            $posts->placeMark($user, $chatId, 'web', $m[1], $m[2], (int) $m[3]);

            return;
        }
        if (str_starts_with($data, 'ig:')) {
            $posts->handleInstagramAccountCallback($user, $chatId, $data);

            return;
        }
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed>|null $post
     */
    private function expectingInput(array $user, ?array $post): ?string
    {
        if ($post !== null) {
            $status = (string) ($post['status'] ?? '');
            if ($status === PostStatus::AwaitingTheme->value) {
                return 'theme';
            }
            if ($status === PostStatus::AwaitingFeedback->value) {
                return 'feedback';
            }
            if ($status === PostStatus::AwaitingManualEdit->value) {
                return 'caption';
            }
            if ($status === PostStatus::AwaitingImageEdit->value) {
                return 'image_edit';
            }
        }
        $pending = (string) ($user['pending_action'] ?? '');
        if (str_starts_with($pending, 'kind:')) {
            return 'media';
        }
        if ($pending === 'kind:ia' || str_starts_with($pending, 'aiv:')) {
            return 'idea';
        }

        return 'text';
    }

    private function requireUser(int $customerId): int
    {
        $userId = $this->userIdForCustomer($customerId);
        if ($userId < 1) {
            throw new RuntimeException('Ative a conta no Telegram antes de usar perfil e publicação.');
        }

        return $userId;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireUserRow(int $customerId): array
    {
        $userId = $this->requireUser($customerId);
        $user = $this->users->find($userId);
        if ($user === null) {
            throw new RuntimeException('Usuário não encontrado.');
        }

        return $user;
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
     * @param array<string, mixed> $profile
     */
    private function logoUrl(array $profile): string
    {
        $relative = trim((string) ($profile['logo_path'] ?? ''));
        if ($relative === '') {
            return '';
        }

        return Layout::url('minha-conta/logo');
    }

    private function storeUpload(int $userId, string $tmp, string $mime): string
    {
        $ext = str_starts_with($mime, 'video/') ? 'mp4' : 'jpg';
        $dir = Config::root() . '/storage/media';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Pasta de mídia indisponível.');
        }
        $dest = $dir . '/u' . $userId . '_web_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!move_uploaded_file($tmp, $dest)) {
            if (!rename($tmp, $dest)) {
                throw new RuntimeException('Não foi possível guardar o arquivo.');
            }
        }

        return $dest;
    }

    private function detectMime(string $path, string $hint): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detected = is_resource($finfo) ? finfo_file($finfo, $path) : false;
        if (is_resource($finfo)) {
            finfo_close($finfo);
        }
        if (is_string($detected) && $detected !== '') {
            return strtolower($detected);
        }

        return strtolower($hint);
    }

    private function allowedMime(string $mime): bool
    {
        return in_array($mime, [
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/heic',
            'image/heif',
            'video/mp4',
            'video/quicktime',
        ], true) || str_starts_with($mime, 'video/');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sessionMessages(int $userId): array
    {
        if ($userId < 1) {
            return [];
        }
        $all = $_SESSION['portal_studio'] ?? [];
        if (!is_array($all)) {
            return [];
        }
        $messages = $all[$userId] ?? [];

        return is_array($messages) ? $messages : [];
    }

    private function appendUserMessage(int $userId, string $text): void
    {
        $this->appendMessage($userId, [
            'role' => 'user',
            'type' => 'text',
            'text' => $text,
        ]);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function appendBotMessage(int $userId, array $item): void
    {
        $item['role'] = 'bot';
        $this->appendMessage($userId, $item);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function appendMessage(int $userId, array $item): void
    {
        if (!isset($_SESSION['portal_studio']) || !is_array($_SESSION['portal_studio'])) {
            $_SESSION['portal_studio'] = [];
        }
        if (!isset($_SESSION['portal_studio'][$userId]) || !is_array($_SESSION['portal_studio'][$userId])) {
            $_SESSION['portal_studio'][$userId] = [];
        }
        $_SESSION['portal_studio'][$userId][] = $item;
        $max = 80;
        if (count($_SESSION['portal_studio'][$userId]) > $max) {
            $_SESSION['portal_studio'][$userId] = array_slice($_SESSION['portal_studio'][$userId], -$max);
        }
    }

    private function flushBot(int $userId, WebStudioChannel $channel): void
    {
        foreach ($channel->drainOutbox() as $item) {
            $mapped = $this->mapOutboxItem($item);
            if ($mapped !== null) {
                $this->appendBotMessage($userId, $mapped);
            }
        }
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>|null
     */
    private function mapOutboxItem(array $item): ?array
    {
        $type = (string) ($item['type'] ?? 'text');
        if ($type === 'text' || $type === 'edit_text') {
            return [
                'type' => 'text',
                'text' => (string) ($item['text'] ?? ''),
                'buttons' => $item['buttons'] ?? [],
            ];
        }
        if ($type === 'photo' || $type === 'video') {
            $path = (string) ($item['path'] ?? '');
            $url = $this->publicUrlForPath($path);

            return [
                'type' => $type,
                'url' => $url,
                'caption' => (string) ($item['caption'] ?? ''),
                'buttons' => $item['buttons'] ?? [],
            ];
        }
        if ($type === 'album') {
            $urls = [];
            foreach ($item['paths'] ?? [] as $path) {
                if (!is_string($path)) {
                    continue;
                }
                $url = $this->publicUrlForPath($path);
                if ($url !== '') {
                    $urls[] = $url;
                }
            }

            return [
                'type' => 'album',
                'urls' => $urls,
                'buttons' => [],
            ];
        }

        return null;
    }

    private function publicUrlForPath(string $path): string
    {
        if ($path === '' || !is_file($path)) {
            return '';
        }
        $root = Config::root() . '/public/m/';
        if (str_starts_with($path, $root)) {
            $name = basename($path);
            $base = Layout::url('m/' . $name);

            return $base;
        }

        return '';
    }
}
