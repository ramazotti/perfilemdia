<?php

declare(strict_types=1);

namespace PerfilEmDia\Image;

interface ImageNormalizerInterface
{
    /**
     * Converte as fotos para JPEG publicável no feed do Instagram.
     * No carrossel, todas saem na proporção da primeira depois do recorte.
     *
     * @param list<string> $sourcePaths
     * @param 'feed'|'story' $canvas story is exactly 1080x1920 (9:16)
     * @return list<NormalizedImage>
     */
    public function normalize(array $sourcePaths, string $publicDirectory, string $canvas = 'feed'): array;
}
