<?php

declare(strict_types=1);

namespace PerfilEmDia\Image;

interface IdeaVideoGenerator
{
    public function submit(string $prompt, int $seconds, ?string $referencePath = null): string;

    /**
     * @return array{status:string, url:?string, error:?string}
     */
    public function status(string $jobId): array;

    public function download(string $url): string;
}
