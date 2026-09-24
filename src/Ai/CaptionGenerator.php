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
    private bool $creative = false;

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
        $this->creative = str_starts_with($theme, "[[criacao]]\n");
        if ($this->creative) {
            $theme = substr($theme, strlen("[[criacao]]\n"));
        }

        $primaryModel = self::model('ai_model', 'OPENROUTER_MODEL', 'openai/gpt-4o-mini');
        $fallbackModel = self::model('ai_model_fallback', 'OPENROUTER_MODEL_FALLBACK', 'google/gemini-2.5-flash');
        $legacyTokens = Config::get('ANTHROPIC_MAX_TOKENS', '1024');
        $maxTokens = (int) Config::get('OPENROUTER_MAX_TOKENS', $legacyTokens);

        $imageBlocks = $this->imageBlocks($jpegPaths);
        $contact = trim((string) ($profile['contact_cta'] ?? ''));
        if ($previousCaption !== null && $contact !== '') {
            $previousCaption = self::withoutRepeatedContact($previousCaption, $contact);
        }
        $userText = Prompts::user($profile, $theme, $previousCaption, $feedback, count($jpegPaths));

        $response = $this->requestWithFailover($primaryModel, $fallbackModel, $imageBlocks, $userText, $maxTokens);
        $this->rejectProviderError($response['body']);
        $parsed = $this->decodeModelJson($this->extractText($response['body']));

        if ($parsed === null) {
            $retryText = $userText . "\n\n" . self::JSON_RETRY_HINT;
            $response = $this->requestWithFailover($primaryModel, $fallbackModel, $imageBlocks, $retryText, $maxTokens);
            $this->rejectProviderError($response['body']);
            $parsed = $this->decodeModelJson($this->extractText($response['body']));
        }

        if ($parsed === null) {
            $snippet = trim((string) preg_replace('/\s+/', ' ', $this->extractText($response['body'])));
            throw new CaptionException('Model returned invalid JSON. ' . mb_substr($snippet, 0, 240), 'invalid_json');
        }

        if (array_is_list($parsed) && isset($parsed[0]) && is_array($parsed[0])) {
            $parsed = $parsed[0];
        }

        if (($parsed['erro'] ?? null) === 'conteudo_inadequado') {
            throw new CaptionException('Content flagged as inadequate.', 'conteudo_inadequado');
        }

        $fields = $this->captionFields($parsed);
        if ($fields === null) {
            $snippet = mb_substr((string) json_encode($parsed, JSON_UNESCAPED_UNICODE), 0, 240);
            throw new CaptionException('Model returned invalid JSON. ' . $snippet, 'invalid_json');
        }

        $legenda = $fields['legenda'];
        $hashtags = $this->mergeHashtags($fields['hashtags'], (string) ($profile['fixed_hashtags'] ?? ''));
        $altText = $this->truncate($fields['alt_text'], self::MAX_ALT_TEXT_CHARS);
        $caption = $this->buildCaption($legenda, $hashtags, trim((string) ($profile['contact_cta'] ?? '')));

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
                        'content' => $this->creative ? Prompts::creative() : Prompts::system(),
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

    /**
     * @param array<string, mixed>|string $body
     */
    private function rejectProviderError(array|string $body): void
    {
        if (!is_array($body) || !isset($body['error'])) {
            return;
        }
        $error = $body['error'];
        $message = is_array($error) ? (string) ($error['message'] ?? 'OpenRouter error') : (string) $error;
        throw new CaptionException($message, 'api_error');
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
        if (!is_array($choice)) {
            return '';
        }

        $parts = [];
        foreach ($choice as $part) {
            if (is_string($part)) {
                $parts[] = $part;
                continue;
            }
            if (is_array($part) && is_string($part['text'] ?? null)) {
                $parts[] = $part['text'];
            }
        }

        return implode("\n", $parts);
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

        $candidates = [$text];
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/si', $text, $matches) === 1) {
            $candidates[] = trim($matches[1]);
        }
        $object = $this->firstJsonObject($text);
        if ($object !== null) {
            $candidates[] = $object;
        }

        foreach ($candidates as $candidate) {
            $decoded = json_decode($candidate, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    private function firstJsonObject(string $text): ?string
    {
        $start = strpos($text, '{');
        if ($start === false) {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escape = false;
        $length = strlen($text);
        for ($i = $start; $i < $length; $i++) {
            $char = $text[$i];
            if ($inString) {
                if ($escape) {
                    $escape = false;
                    continue;
                }
                if ($char === '\\') {
                    $escape = true;
                    continue;
                }
                if ($char === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($char === '"') {
                $inString = true;
                continue;
            }
            if ($char === '{') {
                $depth++;
                continue;
            }
            if ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($text, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $parsed
     * @return array{legenda:string, hashtags:list<mixed>, alt_text:string}|null
     */
    private function captionFields(array $parsed): ?array
    {
        $legenda = $parsed['legenda'] ?? $parsed['caption'] ?? $parsed['texto'] ?? null;
        if (!is_string($legenda) || trim($legenda) === '') {
            return null;
        }

        $tags = $parsed['hashtags'] ?? $parsed['tags'] ?? [];
        if (is_string($tags)) {
            $tags = preg_split('/[\s,]+/', trim($tags), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        if (!is_array($tags)) {
            $tags = [];
        }

        $alt = $parsed['alt_text'] ?? $parsed['alt'] ?? '';
        if (!is_string($alt)) {
            $alt = '';
        }

        return [
            'legenda' => $this->normalizeBreaks($legenda),
            'hashtags' => array_values($tags),
            'alt_text' => $this->normalizeBreaks($alt),
        ];
    }

    private function normalizeBreaks(string $text): string
    {
        return str_replace(["\\r\\n", "\\n", "\\r"], "\n", $text);
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
    private function buildCaption(string $legenda, array $hashtags, string $contact = ''): string
    {
        $legenda = self::withoutHashtagBlocks($legenda);
        $contact = trim($contact);
        if ($contact !== '') {
            $legenda = self::withoutRepeatedContact($legenda, $contact);
        }
        $tags = $hashtags;
        $caption = self::assembleCaption($legenda, $tags, $contact);

        while (mb_strlen($caption) > self::MAX_CAPTION_CHARS && $tags !== []) {
            array_pop($tags);
            $caption = self::assembleCaption($legenda, $tags, $contact);
        }

        if (mb_strlen($caption) > self::MAX_CAPTION_CHARS) {
            $tail = $contact === '' ? '' : "\n\n" . $contact;
            $room = self::MAX_CAPTION_CHARS - mb_strlen($tail);
            $caption = $room < 1
                ? $this->truncate($contact, self::MAX_CAPTION_CHARS)
                : $this->truncate($legenda, $room) . $tail;
        }

        return self::dedupeHashtagBlocks($caption);
    }

    public static function dedupeHashtagBlocks(string $caption): string
    {
        $parts = preg_split("/\n{2,}/", trim($caption)) ?: [];
        $seen = [];
        $kept = [];
        foreach ($parts as $part) {
            $trim = trim($part);
            if ($trim === '') {
                continue;
            }
            $flat = trim((string) preg_replace('/\s+/u', ' ', str_replace("\n", ' ', $trim)));
            if (preg_match('/^(?:#[\p{L}\p{N}_]+\s*)+$/u', $flat) === 1) {
                $key = mb_strtolower($flat);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
            }
            $kept[] = $trim;
        }

        return implode("\n\n", $kept);
    }

    /**
     * @param list<string> $tags
     */
    private static function assembleCaption(string $legenda, array $tags, string $contact): string
    {
        $parts = [];
        if ($legenda !== '') {
            $parts[] = $legenda;
        }
        if ($tags !== []) {
            $parts[] = implode(' ', $tags);
        }
        if ($contact !== '') {
            $parts[] = $contact;
        }

        return implode("\n\n", $parts);
    }

    private static function withoutRepeatedContact(string $legenda, string $contact): string
    {
        $compact = static function (string $text): string {
            return mb_strtolower((string) preg_replace('/\s+/u', '', $text));
        };
        $needles = [$compact($contact)];
        if (preg_match_all('#https?://\S+#iu', $contact, $urls) > 0) {
            foreach ($urls[0] as $url) {
                $needles[] = $compact($url);
            }
        }
        $needles = array_values(array_filter($needles, static fn (string $needle): bool => $needle !== ''));
        if ($needles === []) {
            return $legenda;
        }
        $blocks = preg_split("/\n{2,}/", trim($legenda)) ?: [];
        $kept = [];
        foreach ($blocks as $block) {
            $trim = trim($block);
            $flat = $compact($trim);
            $drop = false;
            foreach ($needles as $needle) {
                if ($flat !== '' && str_contains($flat, $needle)) {
                    $drop = true;
                    break;
                }
                if ($flat !== '' && str_contains($needle, $flat) && mb_strlen($trim) <= mb_strlen($contact) + 40) {
                    $drop = true;
                    break;
                }
            }
            if ($drop || $trim === '') {
                continue;
            }
            $kept[] = $trim;
        }

        return implode("\n\n", $kept);
    }

    private static function withoutHashtagBlocks(string $legenda): string
    {
        $lines = preg_split("/\r\n|\n|\r/", $legenda) ?: [];
        $kept = [];
        foreach ($lines as $line) {
            $flat = trim((string) preg_replace('/\s+/u', ' ', $line));
            if ($flat !== '' && preg_match('/^(?:#[\p{L}\p{N}_]+\s*)+$/u', $flat) === 1) {
                continue;
            }
            $kept[] = $line;
        }
        $text = trim(implode("\n", $kept));

        return (string) preg_replace("/\n{3,}/", "\n\n", $text);
    }

    private function truncate(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max);
    }
}
