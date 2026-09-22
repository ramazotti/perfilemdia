<?php

declare(strict_types=1);

namespace PerfilEmDia\Ai;

use PerfilEmDia\Billing\Settings;
use PerfilEmDia\Config;
use PerfilEmDia\Support\GuzzleHttpPoster;
use PerfilEmDia\Support\HttpPoster;
use Throwable;

final class CaptionGenerator implements CaptionGeneratorInterface
{
    private const API_URL = 'https://openrouter.ai/api/v1/chat/completions';
    private const MAX_HASHTAGS = 30;
    private const MAX_CAPTION_CHARS = 2200;
    private const MAX_ALT_TEXT_CHARS = 150;
    private const JSON_RETRY_HINT = 'Responda somente com o JSON válido.';

    private HttpPoster $http;
    private bool $allowSleep;

    public function __construct(?HttpPoster $http = null)
    {
        $this->http = $http ?? new GuzzleHttpPoster();
        $this->allowSleep = $this->http instanceof GuzzleHttpPoster;
    }

    public function generate(
        array $profile,
        string $theme,
        array $jpegPaths,
        ?string $previousCaption = null,
        ?string $feedback = null,
    ): CaptionResult {
        Config::load();

        $primaryModel = self::model('ai_model', 'OPENROUTER_MODEL', 'openai/gpt-4o-mini');
        $fallbackModel = self::model('ai_model_fallback', 'OPENROUTER_MODEL_FALLBACK', 'google/gemini-2.5-flash');
        $maxTokens = (int) Config::get('ANTHROPIC_MAX_TOKENS', '1024');

        $imageBlocks = $this->imageBlocks($jpegPaths);
        $userText = Prompts::user($profile, $theme, $previousCaption, $feedback, count($jpegPaths));

        $response = $this->requestWithFailover($primaryModel, $fallbackModel, $imageBlocks, $userText, $maxTokens);
        $parsed = $this->decodeModelJson($this->extractText($response['body']));

        if ($parsed === null) {
            $retryText = $userText . "\n\n" . self::JSON_RETRY_HINT;
            $response = $this->requestWithFailover($primaryModel, $fallbackModel, $imageBlocks, $retryText, $maxTokens);
            $parsed = $this->decodeModelJson($this->extractText($response['body']));
        }

        if ($parsed === null) {
            throw new CaptionException('Model returned invalid JSON.', 'invalid_json');
        }

        if (($parsed['erro'] ?? null) === 'conteudo_inadequado') {
            throw new CaptionException('Content flagged as inadequate.', 'conteudo_inadequado');
        }

        if (!isset($parsed['legenda'], $parsed['hashtags'], $parsed['alt_text']) || !is_array($parsed['hashtags'])) {
            throw new CaptionException('Model returned invalid JSON.', 'invalid_json');
        }

        $legenda = (string) $parsed['legenda'];
        $hashtags = $this->mergeHashtags($parsed['hashtags'], (string) ($profile['fixed_hashtags'] ?? ''));
        $altText = $this->truncate((string) $parsed['alt_text'], self::MAX_ALT_TEXT_CHARS);
        $caption = $this->buildCaption($legenda, $hashtags);

        $usage = is_array($response['body']['usage'] ?? null) ? $response['body']['usage'] : [];
        $model = (string) ($response['body']['model'] ?? $response['model']);

        return new CaptionResult(
            $legenda,
            $hashtags,
            $altText,
            $caption,
            $model,
            (int) ($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0),
            (int) ($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0),
        );
    }

    /**
     * @param list<string> $jpegPaths
     * @return list<array<string, mixed>>
     */
    private function imageBlocks(array $jpegPaths): array
    {
        $blocks = [];
        foreach ($jpegPaths as $path) {
            $bytes = @file_get_contents($path);
            if ($bytes === false) {
                throw new CaptionException('Unable to read image: ' . $path, 'api_error');
            }

            $blocks[] = [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => 'image/jpeg',
                    'data' => base64_encode($bytes),
                ],
            ];
        }

        return $blocks;
    }

    /**
     * @param list<array<string, mixed>> $imageBlocks
     * @return array{status:int, body:array<string, mixed>|string, model:string}
     */
    private function requestWithFailover(
        string $primaryModel,
        string $fallbackModel,
        array $imageBlocks,
        string $userText,
        int $maxTokens,
    ): array {
        $failures = 0;
        $lastMessage = 'OpenRouter request failed.';

        while ($failures < 2) {
            try {
                $response = $this->attemptModel($primaryModel, $imageBlocks, $userText, $maxTokens);
                if ($this->isSuccess($response['status'])) {
                    return $response + ['model' => $primaryModel];
                }
                if ($this->isRetryableStatus($response['status'])) {
                    $failures++;
                    $lastMessage = 'OpenRouter HTTP ' . $response['status'];
                    continue;
                }

                throw new CaptionException('OpenRouter HTTP ' . $response['status'], 'api_error');
            } catch (CaptionException $e) {
                throw $e;
            } catch (Throwable $e) {
                $failures++;
                $lastMessage = $e->getMessage();
            }
        }

        try {
            $response = $this->attemptModel($fallbackModel, $imageBlocks, $userText, $maxTokens);
            if ($this->isSuccess($response['status'])) {
                return $response + ['model' => $fallbackModel];
            }

            throw new CaptionException(
                'OpenRouter HTTP ' . $response['status'] . ' (fallback)',
                'api_error',
            );
        } catch (CaptionException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new CaptionException($lastMessage . '; fallback: ' . $e->getMessage(), 'api_error');
        }
    }

    /**
     * @param list<array<string, mixed>> $imageBlocks
     * @return array{status:int, body:array<string, mixed>|string}
     */
    private function attemptModel(
        string $model,
        array $imageBlocks,
        string $userText,
        int $maxTokens,
    ): array {
        $response = $this->post($model, $imageBlocks, $userText, $maxTokens);
        if ($this->isRetryableStatus($response['status'])) {
            $this->backoff();
            $response = $this->post($model, $imageBlocks, $userText, $maxTokens);
        }

        return $response;
    }

    /**
     * @param list<array<string, mixed>> $imageBlocks
     * @return array{status:int, body:array<string, mixed>|string}
     */
    private function post(
        string $model,
        array $imageBlocks,
        string $userText,
        int $maxTokens,
    ): array {
        $content = [];
        foreach ($imageBlocks as $block) {
            $data = (string) ($block['source']['data'] ?? '');
            $content[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => 'data:image/jpeg;base64,' . $data,
                ],
            ];
        }
        $content[] = [
            'type' => 'text',
            'text' => $userText,
        ];

        return $this->http->request('POST', self::API_URL, [
            'headers' => [
                'Authorization' => 'Bearer ' . Config::get('OPENROUTER_API_KEY'),
                'HTTP-Referer' => Config::get('APP_URL', 'https://perfilemdia.com.br'),
                'X-Title' => Config::get('OPENROUTER_APP_TITLE', 'Perfil em Dia'),
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => $model,
                'max_tokens' => $maxTokens,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => Prompts::system(),
                    ],
                    [
                        'role' => 'user',
                        'content' => $content,
                    ],
                ],
            ],
        ]);
    }

    private static function model(string $setting, string $env, string $default): string
    {
        $fromAdmin = trim(Settings::get($setting));
        if ($fromAdmin !== '') {
            return $fromAdmin;
        }

        return Config::get($env, $default);
    }

    private function isRetryableStatus(int $status): bool
    {
        return $status === 429 || $status >= 500;
    }

    private function isSuccess(int $status): bool
    {
        return $status >= 200 && $status < 300;
    }

    private function backoff(): void
    {
        if ($this->allowSleep) {
            usleep(250000);
        }
    }

    /**
     * @param array<string, mixed>|string $body
     */
    private function extractText(array|string $body): string
    {
        if (!is_array($body)) {
            return '';
        }

        $choice = $body['choices'][0]['message']['content'] ?? null;
        if (is_string($choice)) {
            return $choice;
        }

        return '';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeModelJson(string $raw): ?array
    {
        $text = trim($raw);
        if ($text === '') {
            return null;
        }

        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/si', $text, $matches) === 1) {
            $text = trim($matches[1]);
        }

        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    /**
     * @param list<mixed> $modelHashtags
     * @return list<string>
     */
    private function mergeHashtags(array $modelHashtags, string $fixedHashtags): array
    {
        $merged = [];
        $seen = [];

        foreach ($modelHashtags as $tag) {
            $this->pushHashtag($merged, $seen, (string) $tag);
        }

        $fixedParts = preg_split('/[\s,]+/', trim($fixedHashtags), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($fixedParts as $tag) {
            $this->pushHashtag($merged, $seen, (string) $tag);
        }

        return array_slice($merged, 0, self::MAX_HASHTAGS);
    }

    /**
     * @param list<string> $merged
     * @param array<string, true> $seen
     */
    private function pushHashtag(array &$merged, array &$seen, string $tag): void
    {
        $normalized = $this->normalizeHashtag($tag);
        if ($normalized === '' || isset($seen[$normalized])) {
            return;
        }

        $seen[$normalized] = true;
        $merged[] = '#' . $normalized;
    }

    private function normalizeHashtag(string $tag): string
    {
        $tag = trim($tag);
        $tag = ltrim($tag, '#');
        $tag = mb_strtolower($tag, 'UTF-8');

        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $tag);
        if ($ascii !== false) {
            $tag = $ascii;
        }

        $tag = preg_replace('/[^a-z0-9_]/', '', $tag) ?? '';

        return $tag;
    }

    /**
     * @param list<string> $hashtags
     */
    private function buildCaption(string $legenda, array $hashtags): string
    {
        $tags = $hashtags;
        $caption = $legenda;
        if ($tags !== []) {
            $caption = $legenda . "\n\n" . implode(' ', $tags);
        }

        while (mb_strlen($caption) > self::MAX_CAPTION_CHARS && $tags !== []) {
            array_pop($tags);
            $caption = $tags === []
                ? $legenda
                : $legenda . "\n\n" . implode(' ', $tags);
        }

        if (mb_strlen($caption) > self::MAX_CAPTION_CHARS) {
            $caption = $this->truncate($caption, self::MAX_CAPTION_CHARS);
        }

        return $caption;
    }

    private function truncate(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max);
    }
}
