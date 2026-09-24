<?php

declare(strict_types=1);

namespace PerfilEmDia\Image;

interface ImageEditorInterface
{
    /**
     * Returns JPEG bytes of the edited photo.
     */
    public function edit(string $jpegPath, string $instruction): string;
}
