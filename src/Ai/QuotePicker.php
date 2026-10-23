<?php

declare(strict_types=1);

namespace PerfilEmDia\Ai;

use PerfilEmDia\Billing\Settings;
use PerfilEmDia\Config;
use PerfilEmDia\Support\GuzzleHttpPoster;
use PerfilEmDia\Support\HttpPoster;

/**
 * Escolhe a citação (texto e crédito) antes de desenhar, para a pessoa conferir.
 */
final class QuotePicker implements QuotePickerInterface
{
    private const API_URL = 'https://openrouter.ai/api/v1/chat/completions';

    public function __construct(private readonly ?HttpPoster $http = null)
    {
    }

    public function pick(string $idea, array $profile, string $avoid = ''): ?array
    {
        Config::load();
        $key = trim(Config::get('OPENROUTER_API_KEY', ''));
        if ($key === '') {
            return null;
        }
        $lines = ['Pedido: ' . mb_substr(trim($idea), 0, 600)];
        foreach ([
            'Profissão / negócio' => (string) ($profile['profession'] ?? ''),
            'Sobre' => (string) ($profile['about'] ?? ''),
        ] as $label => $value) {
            if (trim($value) !== '') {
                $lines[] = $label . ': ' . mb_substr(trim($value), 0, 300);
            }
        }
        if (trim($avoid) !== '') {
            $lines[] = 'Não repita esta citação: ' . mb_substr(trim($avoid), 0, 200);
        }
        $http = $this->http ?? new GuzzleHttpPoster();
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
                        'max_tokens' => 300,
                        'temperature' => 0.4,
                        'messages' => [
                            ['role' => 'system', 'content' => implode("\n", [
                                'Você escolhe uma citação real e conhecida para um post de Instagram no Brasil.',
                                'Responda SOMENTE com JSON válido: {"quote":"...","credit":"..."}',
                                'quote: em português, até 180 caracteres, exatamente como é conhecida.',
                                'credit: autor real, ou livro capítulo,versículo exato se for da Bíblia (ex.: 1 João 1,9).',
                                'Nunca invente autor, versículo ou frase. Se não tiver certeza, escolha outra citação mais conhecida.',
                            ])],
                            ['role' => 'user', 'content' => implode("\n", $lines)],
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
            $picked = self::decode(is_string($content) ? $content : '');
            if ($picked !== null) {
                return $picked;
            }
        }

        return null;
    }

    /**
     * @return array{quote:string, credit:string}|null
     */
    public static function decode(string $raw): ?array
    {
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);
        if (!is_array($decoded)) {
            return null;
        }
        $quote = trim(strip_tags((string) ($decoded['quote'] ?? '')), " \t\n\"\u{00ab}\u{00bb}\u{201c}\u{201d}");
        $credit = trim(strip_tags((string) ($decoded['credit'] ?? '')));
        if (mb_strlen($quote) < 4 || mb_strlen($quote) > 220) {
            return null;
        }

        return ['quote' => $quote, 'credit' => mb_substr($credit, 0, 80)];
    }

    private function model(string $setting, string $env, string $default): string
    {
        $fromAdmin = trim(Settings::get($setting));

        return $fromAdmin !== '' ? $fromAdmin : Config::get($env, $default);
    }
}
