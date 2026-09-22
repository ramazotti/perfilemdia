<?php

declare(strict_types=1);

namespace PerfilEmDia\Instagram;

final class PublishedMedia
{
    public function __construct(
        public readonly string $containerId,
        public readonly string $mediaId,
        public readonly string $permalink,
    ) {
    }
}
