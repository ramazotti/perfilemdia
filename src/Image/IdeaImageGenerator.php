<?php

declare(strict_types=1);

namespace PerfilEmDia\Image;

interface IdeaImageGenerator
{
    public function create(string $idea, ?string $referenceJpeg = null, string $brand = ''): string;
}
