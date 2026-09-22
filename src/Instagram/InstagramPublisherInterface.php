<?php

declare(strict_types=1);

namespace PerfilEmDia\Instagram;

interface InstagramPublisherInterface
{
    /**
     * Publica uma foto ou um carrossel. Uma URL publica uma foto. Duas ou mais publicam carrossel.
     *
     * @param list<string> $imageUrls
     */
    public function publish(
        string $igUserId,
        string $accessToken,
        array $imageUrls,
        string $caption,
        ?string $altText = null,
    ): PublishedMedia;
}
