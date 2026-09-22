<?php

declare(strict_types=1);

namespace PerfilEmDia\Domain;

use PerfilEmDia\Channel\ChannelInterface;
use PerfilEmDia\Config;
use PerfilEmDia\Messages;
use PerfilEmDia\Telegram\Keyboards;

final class OnboardingService
{
    private const TONES = ['profissional', 'descontraido', 'tecnico', 'acolhedor'];

    private const FIELD_MAP = [
        'nome' => 'display_name',
        'profissao' => 'profession',
        'cidade' => 'city',
        'tom' => 'tone',
        'contato' => 'contact_cta',
        'sobre' => 'about',
    ];

    private const LIMITS = [
        'display_name' => 120,
        'profession' => 120,
        'city' => 120,
        'contact_cta' => 255,
        'about' => 500,
    ];

    public function __construct(
        private readonly UserRepository $users,
        private readonly ChannelInterface $channel,
    ) {
    }

    /**
     * @param array<string, mixed> $user
     */
    public function start(array $user, int $chatId): void
    {
        $step = (string) ($user['onboarding_step'] ?? 'start');
        if ($step === 'done') {
            $this->channel->sendText($chatId, Messages::welcomeBack((string) ($user['display_name'] ?? '')));

            return;
        }

        if ($step === 'start') {
            $nameHint = (string) ($user['telegram_username'] ?? '');
            $this->channel->sendText($chatId, Messages::welcome($nameHint));

            return;
        }

        $this->askCurrentStep($user, $chatId);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function continueOnboarding(array $user, int $chatId): void
    {
        $this->askCurrentStep($user, $chatId);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function handleText(array $user, int $chatId, string $text): bool
    {
        $pending = (string) ($user['pending_action'] ?? '');
        if (str_starts_with($pending, 'edit:')) {
            $fieldKey = substr($pending, 5);
            if ($fieldKey === 'tom') {
                $this->channel->sendText($chatId, Messages::askTone(), Keyboards::tone());

                return true;
            }

            return $this->saveEditField($user, $chatId, $fieldKey, $text);
        }

        $step = (string) ($user['onboarding_step'] ?? 'start');
        if ($step === 'done') {
            return false;
        }

        $clean = $this->sanitize($text);

        return match ($step) {
            'start' => $this->acceptName($user, $chatId, $clean),
            'name' => $this->acceptProfession($user, $chatId, $clean),
            'profession' => $this->acceptCity($user, $chatId, $clean),
            'city' => $this->rejectUnexpected($user, $chatId),
            'tone' => $this->acceptCta($user, $chatId, $clean),
            'cta' => $this->acceptAbout($user, $chatId, $clean),
            default => false,
        };
    }

    /**
     * @param array<string, mixed> $user
     */
    public function handleTone(array $user, int $chatId, string $tone): void
    {
        if (!in_array($tone, self::TONES, true)) {
            $this->channel->sendText($chatId, Messages::askTone(), Keyboards::tone());

            return;
        }

        $pending = (string) ($user['pending_action'] ?? '');
        if ($pending === 'edit:tom') {
            $this->users->update((int) $user['id'], [
                'tone' => $tone,
                'pending_action' => null,
            ]);
            $fresh = $this->users->find((int) $user['id']) ?? $user;
            $this->channel->sendText($chatId, Messages::perfil($fresh), Keyboards::perfilFields());

            return;
        }

        $step = (string) ($user['onboarding_step'] ?? 'start');
        if ($step !== 'city') {
            $this->rejectUnexpected($user, $chatId);

            return;
        }

        $this->users->update((int) $user['id'], [
            'tone' => $tone,
            'onboarding_step' => 'tone',
        ]);
        $this->channel->sendText($chatId, Messages::askCta(), Keyboards::skip('cta'));
    }

    /**
     * @param array<string, mixed> $user
     */
    public function handleSkip(array $user, int $chatId, string $step): void
    {
        $current = (string) ($user['onboarding_step'] ?? 'start');
        if ($step === 'cta' && $current === 'tone') {
            $this->users->update((int) $user['id'], [
                'contact_cta' => '',
                'onboarding_step' => 'cta',
            ]);
            $this->channel->sendText($chatId, Messages::askAbout(), Keyboards::skip('about'));

            return;
        }
        if ($step === 'about' && $current === 'cta') {
            $this->finishAbout($user, $chatId, '');

            return;
        }

        $this->rejectUnexpected($user, $chatId);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function beginEdit(array $user, int $chatId, string $fieldKey): void
    {
        if (!isset(self::FIELD_MAP[$fieldKey])) {
            return;
        }

        $this->users->update((int) $user['id'], ['pending_action' => 'edit:' . $fieldKey]);

        match ($fieldKey) {
            'nome' => $this->channel->sendText($chatId, Messages::welcome('')),
            'profissao' => $this->channel->sendText($chatId, Messages::askProfession()),
            'cidade' => $this->channel->sendText($chatId, Messages::askCity()),
            'tom' => $this->channel->sendText($chatId, Messages::askTone(), Keyboards::tone()),
            'contato' => $this->channel->sendText($chatId, Messages::askCta()),
            'sobre' => $this->channel->sendText($chatId, Messages::askAbout()),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $user
     */
    public function offerInstagram(array $user, int $chatId): void
    {
        $state = $this->users->createOauthState((int) $user['id']);
        $url = rtrim(Config::get('APP_URL'), '/') . '/conectar.php?t=' . $state;
        $this->channel->sendText($chatId, Messages::askInstagram(), Keyboards::instagramConnect($url));
    }

    /**
     * @param array<string, mixed> $user
     */
    private function askCurrentStep(array $user, int $chatId): void
    {
        $step = (string) ($user['onboarding_step'] ?? 'start');
        match ($step) {
            'start' => $this->channel->sendText($chatId, Messages::welcome((string) ($user['telegram_username'] ?? ''))),
            'name' => $this->channel->sendText($chatId, Messages::askProfession()),
            'profession' => $this->channel->sendText($chatId, Messages::askCity()),
            'city' => $this->channel->sendText($chatId, Messages::askTone(), Keyboards::tone()),
            'tone' => $this->channel->sendText($chatId, Messages::askCta(), Keyboards::skip('cta')),
            'cta' => $this->channel->sendText($chatId, Messages::askAbout(), Keyboards::skip('about')),
            default => $this->channel->sendText($chatId, Messages::welcomeBack((string) ($user['display_name'] ?? ''))),
        };
    }

    /**
     * @param array<string, mixed> $user
     */
    private function acceptName(array $user, int $chatId, string $clean): bool
    {
        if ($clean === '' || mb_strlen($clean) > self::LIMITS['display_name']) {
            return $this->rejectUnexpected($user, $chatId);
        }
        $this->users->update((int) $user['id'], [
            'display_name' => $clean,
            'onboarding_step' => 'name',
        ]);
        $this->channel->sendText($chatId, Messages::askProfession());

        return true;
    }

    /**
     * @param array<string, mixed> $user
     */
    private function acceptProfession(array $user, int $chatId, string $clean): bool
    {
        if ($clean === '' || mb_strlen($clean) > self::LIMITS['profession']) {
            return $this->rejectUnexpected($user, $chatId);
        }
        $this->users->update((int) $user['id'], [
            'profession' => $clean,
            'onboarding_step' => 'profession',
        ]);
        $this->channel->sendText($chatId, Messages::askCity());

        return true;
    }

    /**
     * @param array<string, mixed> $user
     */
    private function acceptCity(array $user, int $chatId, string $clean): bool
    {
        if ($clean === '' || mb_strlen($clean) > self::LIMITS['city']) {
            return $this->rejectUnexpected($user, $chatId);
        }
        $this->users->update((int) $user['id'], [
            'city' => $clean,
            'onboarding_step' => 'city',
        ]);
        $this->channel->sendText($chatId, Messages::askTone(), Keyboards::tone());

        return true;
    }

    /**
     * @param array<string, mixed> $user
     */
    private function acceptCta(array $user, int $chatId, string $clean): bool
    {
        if (mb_strlen($clean) > self::LIMITS['contact_cta']) {
            return $this->rejectUnexpected($user, $chatId);
        }
        $this->users->update((int) $user['id'], [
            'contact_cta' => $clean,
            'onboarding_step' => 'cta',
        ]);
        $this->channel->sendText($chatId, Messages::askAbout(), Keyboards::skip('about'));

        return true;
    }

    /**
     * @param array<string, mixed> $user
     */
    private function acceptAbout(array $user, int $chatId, string $clean): bool
    {
        if (mb_strlen($clean) > self::LIMITS['about']) {
            return $this->rejectUnexpected($user, $chatId);
        }
        $this->finishAbout($user, $chatId, $clean);

        return true;
    }

    /**
     * @param array<string, mixed> $user
     */
    private function finishAbout(array $user, int $chatId, string $about): void
    {
        $displayName = (string) ($user['display_name'] ?? '');
        $this->users->update((int) $user['id'], [
            'about' => $about,
            'onboarding_step' => 'done',
        ]);
        $this->channel->sendText($chatId, Messages::ready($displayName));
        $fresh = $this->users->find((int) $user['id']) ?? $user;
        $this->offerInstagram($fresh, $chatId);
    }

    /**
     * @param array<string, mixed> $user
     */
    private function saveEditField(array $user, int $chatId, string $fieldKey, string $text): bool
    {
        $column = self::FIELD_MAP[$fieldKey] ?? null;
        if ($column === null || $column === 'tone') {
            return false;
        }

        $clean = $this->sanitize($text);
        $limit = self::LIMITS[$column] ?? 120;
        if (($clean === '' && in_array($column, ['display_name', 'profession', 'city'], true))
            || mb_strlen($clean) > $limit
        ) {
            $this->beginEdit($user, $chatId, $fieldKey);

            return true;
        }

        $this->users->update((int) $user['id'], [
            $column => $clean,
            'pending_action' => null,
        ]);
        $fresh = $this->users->find((int) $user['id']) ?? $user;
        $this->channel->sendText($chatId, Messages::perfil($fresh), Keyboards::perfilFields());

        return true;
    }

    /**
     * @param array<string, mixed> $user
     */
    private function rejectUnexpected(array $user, int $chatId): bool
    {
        $this->channel->sendText($chatId, Messages::repeat());
        $this->askCurrentStep($user, $chatId);

        return true;
    }

    private function sanitize(string $text): string
    {
        return trim(strip_tags($text));
    }
}
