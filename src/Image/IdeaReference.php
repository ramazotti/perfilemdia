<?php

declare(strict_types=1);

namespace PerfilEmDia\Image;

use PerfilEmDia\Config;

/**
 * Keeps the user's IA reference photo when they send the image before the idea (text or voice).
 */
final class IdeaReference
{
    public static function stashPath(int $userId): string
    {
        return Config::root() . '/storage/media/u' . $userId . '_ia_ref.jpg';
    }

    public static function postPath(int $postId): string
    {
        return Config::root() . '/storage/media/' . $postId . '_ref.jpg';
    }

    public static function hasStash(int $userId): bool
    {
        $path = self::stashPath($userId);

        return is_file($path) && filesize($path) > 0;
    }

    public static function clearStash(int $userId): void
    {
        $path = self::stashPath($userId);
        if (is_file($path)) {
            unlink($path);
        }
    }

    /**
     * Moves the stashed photo to the post reference path for generation and later regen.
     */
    public static function adoptStash(int $userId, int $postId): ?string
    {
        $stash = self::stashPath($userId);
        if (!is_file($stash)) {
            return null;
        }
        $dest = self::postPath($postId);
        $dir = dirname($dest);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }
        if (!@rename($stash, $dest)) {
            if (!@copy($stash, $dest)) {
                return null;
            }
            unlink($stash);
        }

        return $dest;
    }
}
