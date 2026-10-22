<?php

declare(strict_types=1);

namespace PerfilEmDia\Ai;

use DateTimeImmutable;
use DateTimeZone;
use PerfilEmDia\Billing\Settings;
use PerfilEmDia\Config;
use PerfilEmDia\Growth\BenefitOrchestrator;
use PerfilEmDia\Image\IdeaFormatBrief;
use PerfilEmDia\Support\GuzzleHttpPoster;
use PerfilEmDia\Support\HttpPoster;

/**
 * Sugere tema visual + frase curta para o Surpreenda-me (texto-only, sem imagem).
 */
final class SurpriseBriefGenerator
{
    private const API_URL = 'https://openrouter.ai/api/v1/chat/completions';

    public function __construct(private readonly ?HttpPoster $http = null)
    {
    }

    /**
     * @param array<string, mixed> $user Perfil (about, profession, tone, brand_style, city, display_name, contact_cta)
     * @return array{idea:string, phrase:string}|null
     */
    public function generate(
        array $user,
        DateTimeImmutable $now,
        string $destination = 'feed',
        ?string $formatKey = null,
    ): ?array {
        Config::load();
        $key = trim(Config::get('OPENROUTER_API_KEY', ''));
        if ($key === '') {
            return null;
        }

        $destination = $destination === 'story' ? 'story' : 'feed';
        $formatKey = IdeaFormatBrief::normalizeKey($formatKey);
        $now = $now->setTimezone(new DateTimeZone('America/Sao_Paulo'));
        $model = $this->model();
        $fallback = $this->fallbackModel();
        $userText = $this->buildUserPrompt($user, $now, $destination, $formatKey);
        $http = $this->http ?? new GuzzleHttpPoster();

        foreach ([$model, $fallback] as $attemptModel) {
            if ($attemptModel === '') {
                continue;
            }
            try {
                $response = $http->request('POST', self::API_URL, [
                    'timeout' => 45,
                    'headers' => [
                        'Authorization' => 'Bearer ' . $key,
                        'HTTP-Referer' => Config::get('APP_URL', 'https://perfilemdia.com.br'),
                        'X-Title' => Config::get('OPENROUTER_APP_TITLE', 'Perfil em Dia'),
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'model' => $attemptModel,
                        'max_tokens' => 700,
                        'temperature' => 0.9,
                        'messages' => [
                            ['role' => 'system', 'content' => $this->systemPrompt($formatKey)],
                            ['role' => 'user', 'content' => $userText],
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

            $parsed = $this->decodePayload($this->extractText($body));
            if ($parsed === null) {
                continue;
            }

            return $parsed;
        }

        return null;
    }

    private function systemPrompt(string $formatKey): string
    {
        $lines = [
            'Você cria sugestões de post para Instagram no Brasil, para qualquer nicho ou profissão.',
            'Responda SOMENTE com JSON válido, sem markdown, no formato:',
            '{"idea":"...","phrase":"..."}',
        ];
        if (IdeaFormatBrief::isDesignedKey($formatKey)) {
            $lines[] = 'idea: briefing em português para gerar ARTE com texto legível (layout, cores, tipografia). Siga o formato pedido no prompt do usuário.';
            $lines[] = 'phrase: gancho curto para legenda do Instagram (4 a 55 caracteres).';
        } else {
            $lines[] = 'idea: briefing visual em português (2 a 4 frases) para gerar foto depois. Descreva cena, luz, objetos, clima. Sem rostos identificáveis, sem marcas de terceiros, sem texto longo dentro da imagem.';
            $lines[] = 'phrase: frase curta para sobrepor na foto (4 a 55 caracteres), impacto emocional ou curiosidade.';
            $lines[] = 'Diversifique sempre: mude cenário, época do dia, tipo de objeto e ângulo. Não repita o mesmo molde entre sugestões.';
            $lines[] = 'Evite clichês visuais batidos: mesa genérica com Bíblia aberta, vela, terço, xícara e “luz acolhedora”, salvo se o perfil pedir isso explicitamente.';
            $lines[] = 'Prefira metáforas concretas, cenas do cotidiano, detalhes inesperados, perguntas visuais, natureza urbana, mãos em ação (sem rosto), etc.';
        }
        $addon = IdeaFormatBrief::surpriseSystemAddon($formatKey);
        if ($addon !== '') {
            $lines[] = $addon;
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $user
     */
    private function buildUserPrompt(array $user, DateTimeImmutable $now, string $destination, string $formatKey): string
    {
        $lines = [
            'Data (Brasil): ' . $now->format('d/m/Y') . ' (' . $this->weekdayLabel($now) . ').',
            'Formato: ' . ($destination === 'story' ? 'story vertical 9:16' : 'feed 4:5'),
            IdeaFormatBrief::surpriseUserAddonForProfile($user, $formatKey, $destination),
        ];
        $date = BenefitOrchestrator::commemorative($now);
        if ($date !== null) {
            $lines[] = 'Data comemorativa: ' . $date['name'] . '. Pode usar como gancho se couber ao perfil.';
        }
        foreach ([
            'Nome' => (string) ($user['display_name'] ?? ''),
            'Profissão / negócio' => (string) ($user['profession'] ?? ''),
            'Sobre' => (string) ($user['about'] ?? ''),
            'Tom' => (string) ($user['tone'] ?? ''),
            'Visual da marca' => (string) ($user['brand_style'] ?? ''),
            'Cidade' => (string) ($user['city'] ?? ''),
            'Chamada de contato' => (string) ($user['contact_cta'] ?? ''),
        ] as $label => $value) {
            $value = trim($value);
            if ($value !== '') {
                $lines[] = $label . ': ' . mb_substr($value, 0, 400);
            }
        }
        $lines[] = 'Invente um tema novo e específico para ESTE perfil, não um post genérico.';
        if (!IdeaFormatBrief::isDesignedKey($formatKey)) {
            $lines[] = 'Enfoque desta sugestão (obrigatório): ' . $this->pickAngle($now);
        }

        return implode("\n", $lines);
    }

    private function pickAngle(DateTimeImmutable $now): string
    {
        $angles = [
            'Pergunta provocativa ilustrada por uma cena simples, sem objetos religiosos genéricos.',
            'Detalhe do cotidiano que remete ao tema do perfil, fora de mesa com vela ou Bíblia.',
            'Metáfora visual (caminho, ponte, porta, janela, passo) ligada ao propósito do perfil.',
            'Mãos fazendo uma ação concreta relacionada ao conteúdo, rosto fora de quadro.',
            'Contraste antes/depois simbólico, sem prometer milagre.',
            'Objeto único em close com fundo limpo, cor e luz diferentes do “estúdio espiritual”.',
            'Cena ao ar livre ou na rua, clima real, pessoas apenas como silhueta distante.',
            'Mini dica prática mostrada por objetos do dia a dia, não por pôster motivacional.',
            'Momento de pausa (banco, parapeito, escada) sem kit “oração de stock”.',
            'Textura, material ou ferramenta ligada ao ofício ou missão do perfil.',
        ];
        $seed = (int) $now->format('z') + (int) $now->format('G') + random_int(0, 1000);

        return $angles[$seed % count($angles)];
    }

    private function weekdayLabel(DateTimeImmutable $now): string
    {
        $names = ['domingo', 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado'];

        return $names[(int) $now->format('w')] ?? 'dia';
    }

    /**
     * @param array<string, mixed> $body
     */
    private function extractText(array $body): string
    {
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
     * @return array{idea:string, phrase:string}|null
     */
    private function decodePayload(string $raw): ?array
    {
        $text = trim($raw);
        if ($text === '') {
            return null;
        }
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/si', $text, $matches) === 1) {
            $text = trim($matches[1]);
        }
        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            $start = strpos($text, '{');
            $end = strrpos($text, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
            }
        }
        if (!is_array($decoded)) {
            return null;
        }
        $idea = trim(strip_tags((string) ($decoded['idea'] ?? '')));
        $phrase = trim(strip_tags((string) ($decoded['phrase'] ?? '')));
        if (mb_strlen($idea) < 24) {
            return null;
        }
        if ($phrase === '') {
            $phrase = 'Hoje, de perto.';
        }
        $idea = mb_substr($idea, 0, 480);
        $phrase = mb_substr($phrase, 0, 55);

        return ['idea' => $idea, 'phrase' => $phrase];
    }

    private function model(): string
    {
        $fromAdmin = trim(Settings::get('ai_model'));
        if ($fromAdmin !== '') {
            return $fromAdmin;
        }

        return Config::get('OPENROUTER_MODEL', 'openai/gpt-4o-mini');
    }

    private function fallbackModel(): string
    {
        $fromAdmin = trim(Settings::get('ai_model_fallback'));
        if ($fromAdmin !== '') {
            return $fromAdmin;
        }

        return Config::get('OPENROUTER_MODEL_FALLBACK', 'google/gemini-2.5-flash');
    }
}
