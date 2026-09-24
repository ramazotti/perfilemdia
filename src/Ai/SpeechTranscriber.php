<?php

declare(strict_types=1);

namespace PerfilEmDia\Ai;

interface SpeechTranscriber
{
    public function transcribe(string $bytes, string $format): string;
}
