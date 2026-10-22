<?php

declare(strict_types=1);

namespace PerfilEmDia\Ai;

use PerfilEmDia\Billing\Settings;
use PerfilEmDia\Config;
use PerfilEmDia\Support\GuzzleHttpPoster;
use PerfilEmDia\Support\HttpPoster;

/**
 * Planeja o texto de cada slide de uma série (infográfico, passo a passo, antes e depois)
 * antes de desenhar, para os slides não repetirem nem inventarem pontos soltos.
 */
final class SeriesPlanner
{
    private const API_URL = 'https://openrouter.ai/api/v1/chat/completions';

    public function __construct(private readonly ?HttpPoster $http = null)
    {
    }

    /**
     * @param array<string, mixed> $profile
     * @return list<array{title:string, text:string}>|null null quando não deu para planejar (desenha sem plano)
     */
    public function plan(string $idea, string $kind, int $count, bool $story, array $profile): ?array
    {
        Config::load();
        $key = trim(Config::get('OPENROUTER_API_KEY', ''));
        if ($key === '' || $count < 2) {
            return null;
        }
        $http = $this->http ?? new GuzzleHttpPoster();
        $user = $this->userPrompt($idea, $kind, $count, $story, $profile);
        foreach ([$this->model('ai_model', 'OPENROUTER_MODEL', 'openai/gpt-4o-mini'), $this->model('ai_model_fallback', 'OPENROUTER_MODEL_FALLBACK', 'google/gemini-2.5-flash')] as $model) {
            if ($model === '') {
                continue;
            }
            try {
                $response = $http->request('POST', self::API_URL, [
                    'timeout' => 30,
                    'headers' => [
                        'Authorization' => 'Bearer ' . $key,
                        'HTTP-Referer' => Config::get('APP_URL', 'https://perfilemdia.com.br'),
                        'X-Title' => Config::get('OPENROUTER_APP_TITLE', 'Perfil em Dia'),
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'model' => $model,
                        'max_tokens' => 700,
                        'temperature' => 0.5,
                        'messages' => [
                            ['role' => 'system', 'content' => self::systemPrompt()],
                            ['role' => 'user', 'content' => $user],
                        ],
                    ],
                ]);
            } catch (\Throwable) {
                continue;
            }
            $status = (int) ($response['status'] ?? 0);
            $body = $response['body'] ?? null;
            if ($status < 200 || $status >= 300 || !is_array($body)) {
                continue;
            }
            $content = $body['choices'][0]['message']['content'] ?? '';
            $slides = self::decode(is_string($content) ? $content : '', $count, $story);
            if ($slides !== null) {
                return $slides;
            }
        }

        return null;
    }

    /**
     * @return list<array{title:string, text:string}>|null
     */
    public static function decode(string $raw, int $count, bool $story): ?array
    {
        $text = trim($raw);
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/si', $text, $match) === 1) {
            $text = trim($match[1]);
        }
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
        $rows = is_array($decoded) ? ($decoded['slides'] ?? null) : null;
        if (!is_array($rows) || count($rows) < $count) {
            return null;
        }
        $limit = $story ? 110 : 140;
        $slides = [];
        foreach (array_slice(array_values($rows), 0, $count) as $row) {
            if (!is_array($row)) {
                return null;
            }
            $title = trim(strip_tags((string) ($row['title'] ?? '')));
            $body = trim(strip_tags((string) ($row['text'] ?? '')));
            if ($title === '') {
                return null;
            }
            $slides[] = [
                'title' => mb_substr($title, 0, 48),
                'text' => mb_substr($body, 0, $limit),
            ];
        }

        return $slides;
    }

    private static function systemPrompt(): string
    {
        return implode("\n", [
            'Você planeja o texto de séries de slides para Instagram no Brasil.',
            'Responda SOMENTE com JSON válido, sem markdown: {"slides":[{"title":"...","text":"..."}]}',
            'Português do Brasil correto. Cada slide traz uma ideia diferente; nada se repete entre slides.',
            'title: até 40 caracteres. text: frase curta e concreta (pode ficar vazio na capa).',
            'Não invente dados, preços, leis ou citações. Use só o que o pedido e o perfil sustentam.',
        ]);
    }

    /**
     * @param array<string, mixed> $profile
     */
    private function userPrompt(string $idea, string $kind, int $count, bool $story, array $profile): string
    {
        $shape = match ($kind) {
            'steps' => 'Passo a passo: cada slide é um passo, na ordem. title começa com "Passo N".',
            'before_after' => 'Antes e depois: o slide 1 mostra o ANTES, o último mostra o DEPOIS' . ($count > 2 ? ', os do meio mostram a mudança.' : '.'),
            default => 'Infográfico: o slide 1 é a capa (título forte e uma linha de contexto); os do meio trazem um ponto cada; o último fecha com a conclusão ou o convite do pedido.',
        };
        $lines = [
            'Série com exatamente ' . $count . ' slides para ' . ($story ? 'story 9:16 (text até 110 caracteres)' : 'feed 4:5 (text até 140 caracteres)') . '.',
            $shape,
            'Pedido: ' . mb_substr(trim($idea), 0, 900),
        ];
        foreach ([
            'Profissão / negócio' => (string) ($profile['profession'] ?? ''),
            'Sobre' => (string) ($profile['about'] ?? ''),
            'Tom' => (string) ($profile['tone'] ?? ''),
        ] as $label => $value) {
            if (trim($value) !== '') {
                $lines[] = $label . ': ' . mb_substr(trim($value), 0, 300);
            }
        }

        return implode("\n", $lines);
    }

    private function model(string $setting, string $env, string $default): string
    {
        $fromAdmin = trim(Settings::get($setting));

        return $fromAdmin !== '' ? $fromAdmin : Config::get($env, $default);
    }
}
