<?php

declare(strict_types=1);

namespace PerfilEmDia\Image;

final class NormalizedImage
{
    public function __construct(
        public readonly string $publicName,
        public readonly string $absolutePath,
        public readonly int $width,
        public readonly int $height,
        public readonly float $ratio,
    ) {
    }
}
