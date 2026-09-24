<?php

declare(strict_types=1);

namespace PerfilEmDia\Ai;

use PerfilEmDia\Config;
use PerfilEmDia\Support\GuzzleHttpPoster;
use PerfilEmDia\Support\HttpPoster;
use RuntimeException;

final class OpenRouterSpeechTranscriber implements SpeechTranscriber
{
    private const URL = 'https://openrouter.ai/api/v1/audio/transcriptions';

    public function __construct(private readonly ?HttpPoster $http = null)
    {
    }

    public function transcribe(string $bytes, string $format): string
    {
        if ($bytes === '') {
            return '';
        }
        Config::load();
        $http = $this->http ?? new GuzzleHttpPoster();
        $primary = Config::get('OPENROUTER_STT_MODEL', 'openai/whisper-1');
        $fallback = Config::get('OPENROUTER_STT_MODEL_FALLBACK', 'openai/whisper-large-v3');
        $response = $this->post($http, $primary, $bytes, $format);
        if (!$this->ok($response) && $fallback !== '' && $fallback !== $primary) {
            $response = $this->post($http, $fallback, $bytes, $format);
        }
        if (!$this->ok($response)) {
            throw new RuntimeException('Transcricao recusada');
        }
        $body = $response['body'];
        if (!is_array($body)) {
            return '';
        }

        return trim((string) ($body['text'] ?? ''));
    }

    /**
     * @return array{status:int, body:array<string, mixed>|string}
     */
    private function post(HttpPoster $http, string $model, string $bytes, string $format): array
    {
        return $http->request('POST', self::URL, [
            'headers' => [
                'Authorization' => 'Bearer ' . Config::get('OPENROUTER_API_KEY'),
                'HTTP-Referer' => Config::get('APP_URL', 'https://perfilemdia.com.br'),
                'X-Title' => Config::get('OPENROUTER_APP_TITLE', 'Perfil em Dia'),
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => $model,
                'language' => 'pt',
                'temperature' => 0,
                'input_audio' => [
                    'data' => base64_encode($bytes),
                    'format' => $format,
                ],
            ],
            'timeout' => 60,
        ]);
    }

    /**
     * @param array{status:int, body:array<string, mixed>|string} $response
     */
    private function ok(array $response): bool
    {
        if ($response['status'] < 200 || $response['status'] >= 300) {
            return false;
        }
        $body = $response['body'];

        return is_array($body) && !isset($body['error']);
    }
}
