<?php

declare(strict_types=1);

namespace PerfilEmDia\Ai;

final class Prompts
{
    public static function system(): string
    {
        return <<<'PROMPT'
Você é o redator de Instagram de profissionais autônomos brasileiros. Escreve legendas curtas, naturais e verdadeiras a partir da FOTO REAL e do que ele contou.
1. Português do Brasil, no tom indicado.
2. NUNCA invente fatos: preços, prazos, nomes de clientes, marcas, endereços, garantias, números ou resultados não informados.
3. Não descreva nem identifique pessoas na foto.
4. Não faça promessa de resultado.
5. Não mencione inteligência artificial, bot ou ferramenta.
6. Estrutura: 1ª linha gancho; 2 a 4 frases sobre o trabalho; chamada para ação com o contato informado, se houver.
7. Legenda entre 250 e 900 caracteres, sem contar hashtags.
8. No máximo 3 emojis. Zero se o tom for tecnico.
9. 5 a 10 hashtags relevantes, minúsculas, sem acento, sem #love #instagood.
10. Responda SOMENTE com o JSON {"legenda","hashtags","alt_text"}.
11. Se o tema ou a imagem forem impróprios (nudez, violência, ofensa), responda {"erro":"conteudo_inadequado"}.
PROMPT;
    }

    /**
     * @param array{display_name:?string, profession:?string, city:?string, tone:?string, contact_cta:?string, about:?string, fixed_hashtags:?string} $profile
     */
    public static function user(
        array $profile,
        string $theme,
        ?string $previousCaption,
        ?string $feedback,
        int $photoCount,
    ): string {
        $name = self::field($profile['display_name'] ?? null);
        $profession = self::field($profile['profession'] ?? null);
        $city = self::field($profile['city'] ?? null);
        $tone = self::field($profile['tone'] ?? null);
        $about = self::field($profile['about'] ?? null);

        $contactRaw = trim((string) ($profile['contact_cta'] ?? ''));
        $contact = $contactRaw !== '' ? $contactRaw : 'não informado';

        $parts = [
            'Perfil do profissional:',
            'Nome: ' . $name,
            'Profissão: ' . $profession,
            'Cidade: ' . $city,
            'Tom: ' . $tone,
            'Contato: ' . $contact,
            'Sobre: ' . $about,
            '',
            'Tema do post: ' . $theme,
            'Quantidade de fotos: ' . $photoCount,
        ];

        if ($contactRaw === '') {
            $parts[] = "Na chamada para ação, use 'Me chama no direct'.";
        }

        if ($previousCaption !== null && $previousCaption !== '') {
            $parts[] = '';
            $parts[] = 'Versão anterior da legenda:';
            $parts[] = $previousCaption;

            if ($feedback !== null && $feedback !== '') {
                $parts[] = '';
                $parts[] = 'Feedback do profissional: ' . $feedback;
            } else {
                $parts[] = '';
                $parts[] = 'Faça uma versão diferente, com outro gancho e outra estrutura.';
            }
        } elseif ($feedback !== null && $feedback !== '') {
            $parts[] = '';
            $parts[] = 'Feedback do profissional: ' . $feedback;
        }

        return implode("\n", $parts);
    }

    private static function field(mixed $value): string
    {
        $text = trim((string) ($value ?? ''));

        return $text !== '' ? $text : 'não informado';
    }
}
