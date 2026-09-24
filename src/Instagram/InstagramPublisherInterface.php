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
        ?string $containerId = null,
        ?string $mediaId = null,
        ?callable $checkpoint = null,
    ): PublishedMedia;

    /**
     * Publica um vídeo curto como Reels e também no feed.
     */
    public function publishReel(
        string $igUserId,
        string $accessToken,
        string $videoUrl,
        string $caption,
        ?string $containerId = null,
        ?string $mediaId = null,
        ?callable $checkpoint = null,
    ): PublishedMedia;

    /**
     * Publica uma foto ou um vídeo único como story.
     */
    public function publishStory(
        string $igUserId,
        string $accessToken,
        string $mediaUrl,
        bool $video,
        ?string $containerId = null,
        ?string $mediaId = null,
        ?callable $checkpoint = null,
    ): PublishedMedia;
}
