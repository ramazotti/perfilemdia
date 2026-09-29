<?php

declare(strict_types=1);

namespace PerfilEmDia\Image;

interface ImageNormalizerInterface
{
    /**
     * Converte as fotos para JPEG publicável no Instagram.
     * Feed e carrossel saem em 1080×1350 (4:5). Story em 1080×1920 (9:16).
     *
     * @param list<string> $sourcePaths
     * @param 'feed'|'story' $canvas
     * @return list<NormalizedImage>
     */
    public function normalize(array $sourcePaths, string $publicDirectory, string $canvas = 'feed'): array;
}
