<?php

declare(strict_types=1);

namespace PerfilEmDia\Instagram;

use InvalidArgumentException;

final class InstagramPublisher implements InstagramPublisherInterface
{
    private const POLL_INTERVAL_SECONDS = 2;
    private const POLL_MAX_SECONDS = 30;

    public function __construct(
        private readonly InstagramClient $client,
    ) {
    }

    public function publish(
        string $igUserId,
        string $accessToken,
        array $imageUrls,
        string $caption,
        ?string $altText = null,
        ?string $containerId = null,
        ?string $mediaId = null,
        ?callable $checkpoint = null,
    ): PublishedMedia {
        $note = static function (string $field, string $value) use ($checkpoint): void {
            if ($checkpoint !== null) {
                $checkpoint($field, $value);
            }
        };

        if ($mediaId === null || $mediaId === '') {
            if ($containerId === null || $containerId === '') {
                $count = count($imageUrls);
                if ($count === 0) {
                    throw new InvalidArgumentException('At least one image URL is required');
                }

                if ($count === 1) {
                    $containerId = $this->client->createImageContainer(
                        $igUserId,
                        $accessToken,
                        $imageUrls[0],
                        $caption,
                        $altText,
                        false,
                    );
                } else {
                    $childrenIds = [];
                    foreach ($imageUrls as $url) {
                        $childrenIds[] = $this->client->createImageContainer(
                            $igUserId,
                            $accessToken,
                            $url,
                            null,
                            null,
                            true,
                        );
                    }
                    $containerId = $this->client->createCarouselContainer(
                        $igUserId,
                        $accessToken,
                        $childrenIds,
                        $caption,
                    );
                }
                $note('container', $containerId);
            }

            $this->waitUntilReady($containerId, $accessToken);
            $mediaId = $this->client->publishContainer($igUserId, $accessToken, $containerId);
            $note('media', $mediaId);
        }

        $permalink = $this->client->permalink($mediaId, $accessToken);

        return new PublishedMedia($containerId ?? '', $mediaId, $permalink);
    }

    public function publishStory(
        string $igUserId,
        string $accessToken,
        string $mediaUrl,
        bool $video,
        ?string $containerId = null,
        ?string $mediaId = null,
        ?callable $checkpoint = null,
    ): PublishedMedia {
        $note = static function (string $field, string $value) use ($checkpoint): void {
            if ($checkpoint !== null) {
                $checkpoint($field, $value);
            }
        };

        if ($mediaId === null || $mediaId === '') {
            if ($containerId === null || $containerId === '') {
                $containerId = $this->client->createStoryContainer($igUserId, $accessToken, $mediaUrl, $video);
                $note('container', $containerId);
            }
            $this->waitUntilReady($containerId, $accessToken);
            $mediaId = $this->client->publishContainer($igUserId, $accessToken, $containerId);
            $note('media', $mediaId);
        }

        $permalink = $this->client->permalink($mediaId, $accessToken);

        return new PublishedMedia($containerId ?? '', $mediaId, $permalink);
    }

    public function publishReel(
        string $igUserId,
        string $accessToken,
        string $videoUrl,
        string $caption,
        ?string $containerId = null,
        ?string $mediaId = null,
        ?callable $checkpoint = null,
    ): PublishedMedia {
        $note = static function (string $field, string $value) use ($checkpoint): void {
            if ($checkpoint !== null) {
                $checkpoint($field, $value);
            }
        };

        if ($mediaId === null || $mediaId === '') {
            if ($containerId === null || $containerId === '') {
                $containerId = $this->client->createReelContainer($igUserId, $accessToken, $videoUrl, $caption);
                $note('container', $containerId);
            }

            $this->waitUntilReady($containerId, $accessToken, 45);
            $mediaId = $this->client->publishContainer($igUserId, $accessToken, $containerId);
            $note('media', $mediaId);
        }

        $permalink = $this->client->permalink($mediaId, $accessToken);

        return new PublishedMedia($containerId ?? '', $mediaId, $permalink);
    }

    private function waitUntilReady(string $containerId, string $token, int $maxSeconds = self::POLL_MAX_SECONDS): void
    {
        $deadline = time() + $maxSeconds;

        while (true) {
            try {
                $status = $this->client->containerStatus($containerId, $token);
            } catch (InstagramApiException $e) {
                if ($e->kind === 'not_ready') {
                    if (time() >= $deadline) {
                        throw $e;
                    }
                    sleep(self::POLL_INTERVAL_SECONDS);
                    continue;
                }
                throw $e;
            }

            if ($status === 'FINISHED') {
                return;
            }

            if ($status === 'ERROR' || $status === 'EXPIRED') {
                throw new InstagramApiException(
                    'Instagram container status: ' . $status,
                    null,
                    null,
                    false,
                    'media_invalid',
                );
            }

            if (time() >= $deadline) {
                throw new InstagramApiException(
                    'Instagram container not ready within timeout',
                    null,
                    null,
                    true,
                    'not_ready',
                );
            }

            sleep(self::POLL_INTERVAL_SECONDS);
        }
    }
}
