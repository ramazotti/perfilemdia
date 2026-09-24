<?php

declare(strict_types=1);

namespace PerfilEmDia\Ai;

final class Prompts
{
    public static function system(): string
    {
        return <<<'PROMPT'
Você é o redator de Instagram de quem cuida do próprio perfil, profissional ou produto. Escreve legendas curtas, naturais e verdadeiras a partir da FOTO REAL e do que a pessoa contou.
1. Português do Brasil, no tom indicado.
2. NUNCA invente fatos: preços, prazos, nomes de clientes, marcas, endereços, garantias, números ou resultados não informados.
3. Não descreva nem identifique pessoas na foto.
4. Não faça promessa de resultado.
5. Não mencione inteligência artificial, bot ou ferramenta.
6. Estrutura: 1ª linha gancho; 2 a 4 frases sobre o que a foto mostra, o serviço ou o produto; chamada para ação sem o contato do perfil. Não escreva telefone nem link na legenda. O contato entra depois das hashtags.
7. Legenda entre 250 e 900 caracteres, sem contar hashtags.
8. No máximo 3 emojis. Zero se o tom for tecnico.
9. 5 a 10 hashtags relevantes, minúsculas, sem acento, sem #love #instagood.
10. Responda SOMENTE com o JSON {"legenda","hashtags","alt_text"}.
11. Se o tema ou a imagem forem impróprios (nudez, violência, ofensa), responda {"erro":"conteudo_inadequado"}.
12. Na legenda, separe parágrafos com quebra de linha real. Não escreva a barra invertida seguida da letra n.
PROMPT;
    }

    /**
     * @param array{display_name:?string, profession:?string, city:?string, tone:?string, contact_cta:?string, about:?string, fixed_hashtags:?string} $profile
     */

    public static function creative(): string
    {
        return 'Você escreve a legenda de um post de Instagram criado a partir de uma ideia da pessoa.
1. Português do Brasil, no tom indicado.
2. A ideia é um pedido criativo. A imagem foi feita a partir dela. Não copie o pedido palavra por palavra.
3. Não invente preço, telefone, endereço ou nome de cliente que não estejam na ideia ou no perfil.
4. Não mencione inteligência artificial, bot ou ferramenta.
5. Legenda entre 250 e 900 caracteres, sem contar hashtags. No máximo 3 emojis.
6. 5 a 10 hashtags relevantes, minúsculas, sem acento.
7. Responda SOMENTE com o JSON {"legenda","hashtags","alt_text"}.
8. Se a ideia for imprópria, responda somente o JSON de erro conteudo_inadequado.
9. Separe parágrafos com quebra de linha real.
10. Não escreva telefone, link nem o contato do perfil. Ele entra depois das hashtags.
';
    }

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
            'Perfil:',
            'Nome: ' . $name,
            'O que faz: ' . $profession,
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
        } else {
            $parts[] = 'Não escreva o contato na legenda. Ele entra depois das hashtags.';
        }

        if ($previousCaption !== null && $previousCaption !== '') {
            $parts[] = '';
            $parts[] = 'Versão anterior da legenda:';
            $parts[] = $previousCaption;

            if ($feedback !== null && $feedback !== '') {
                $parts[] = '';
                $parts[] = 'Feedback: ' . $feedback;
            } else {
                $parts[] = '';
                $parts[] = 'Faça uma versão diferente, com outro gancho e outra estrutura.';
            }
        } elseif ($feedback !== null && $feedback !== '') {
            $parts[] = '';
            $parts[] = 'Feedback: ' . $feedback;
        }

        return implode("\n", $parts);
    }

    private static function field(mixed $value): string
    {
        $text = trim((string) ($value ?? ''));

        return $text !== '' ? $text : 'não informado';
    }
}
