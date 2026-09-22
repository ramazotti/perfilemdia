<?php

declare(strict_types=1);

namespace PerfilEmDia\Ai;

interface CaptionGeneratorInterface
{
    /**
     * @param array{display_name:?string, profession:?string, city:?string, tone:?string, contact_cta:?string, about:?string, fixed_hashtags:?string} $profile
     * @param list<string> $jpegPaths
     */
    public function generate(
        array $profile,
        string $theme,
        array $jpegPaths,
        ?string $previousCaption = null,
        ?string $feedback = null,
    ): CaptionResult;
}
