<?php

declare(strict_types=1);

namespace PerfilEmDia\Site;

use PDO;
use PerfilEmDia\Ai\CaptionGenerator;
use PerfilEmDia\Billing\PlanAccess;
use PerfilEmDia\Channel\WebStudioChannel;
use PerfilEmDia\Domain\PostRepository;
use PerfilEmDia\Domain\PostService;
use PerfilEmDia\Domain\UserRepository;
use PerfilEmDia\Image\ImageNormalizer;
use PerfilEmDia\Instagram\InstagramClient;
use PerfilEmDia\Instagram\InstagramPublisher;
use PerfilEmDia\Security\Crypto;

final class PortalFactory
{
    public static function users(PDO $pdo): UserRepository
    {
        return new UserRepository($pdo, Crypto::fromConfig());
    }

    public static function postService(PDO $pdo, WebStudioChannel $channel): PostService
    {
        return new PostService(
            self::users($pdo),
            new PostRepository($pdo),
            $channel,
            new ImageNormalizer(),
            new CaptionGenerator(),
            new InstagramPublisher(new InstagramClient()),
            new PlanAccess($pdo),
        );
    }
}
