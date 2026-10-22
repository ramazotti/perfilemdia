<?php

declare(strict_types=1);

namespace PerfilEmDia\Image;

/**
 * Regras de conteúdo por formato visual (citação, checklist, etc.) ligadas ao perfil.
 */
final class IdeaFormatBrief
{
    public static function normalizeKey(?string $formatKey): string
    {
        $key = trim((string) $formatKey);

        return IdeaPieces::isFormatKey($key) ? $key : 'photo';
    }

    public static function isDesignedKey(string $formatKey): bool
    {
        return $formatKey !== '' && $formatKey !== 'photo';
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function niche(array $user): string
    {
        $signal = self::signalText($user);
        if ($signal === '') {
            return 'generic';
        }
        if (preg_match('/confiss|consci[eê]ncia|cat[oó]lic|espiritual|\bora[cç][aã]o|\bigreja|ter[cç]o|b[ií]bl|evangel|sacramento|penit[eê]ncia|exame de consci/u', $signal) === 1) {
            return 'spiritual';
        }
        if (preg_match('/cont[aá]bil|contador|contadora|fiscal|imposto|tribut|financ|dp\b|folha de pagamento/u', $signal) === 1) {
            return 'accounting';
        }
        if (preg_match('/professor|professora|docente|escola|ensino|pedagog/u', $signal) === 1) {
            return 'education';
        }

        return 'generic';
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function profileContentHint(array $user, string $formatKey): string
    {
        $niche = self::niche($user);
        $who = trim((string) ($user['about'] ?? ''));
        if ($who === '') {
            $who = trim((string) ($user['profession'] ?? ''));
        }
        $label = trim((string) ($user['display_name'] ?? ''));
        $context = $who !== '' ? mb_substr($who, 0, 200) : ($label !== '' ? $label : 'este perfil');

        return match ($formatKey) {
            'quote' => match ($niche) {
                'spiritual' => 'Perfil espiritual/católico (' . $context . '). Escolha um trecho bíblico real em português (até 2 frases curtas), '
                    . 'fiel ao tema de consciência, conversão ou misericórdia. Inclua referência (livro capítulo:versículo) na linha Crédito.',
                'accounting' => 'Perfil contábil/fiscal (' . $context . '). Cite orientação prática ou lembrete ético do ofício (sem prometer resultado fiscal). '
                    . 'Crédito: nome genérico do ofício ou “Dica contábil”.',
                'education' => 'Perfil educacional (' . $context . '). Cite frase sobre aprendizado, paciência ou sala de aula.',
                default => 'Perfil: ' . $context . '. Cite frase curta que faça sentido para quem segue este negócio.',
            },
            'checklist' => 'Itens práticos do dia a dia de ' . $context . ', linguagem simples, 4 a 6 tópicos.',
            'compare' => 'Compare duas opções reais do universo de ' . $context . ' (colunas com rótulos claros).',
            'before_after' => 'Antes e depois simbólico ou prático ligado a ' . $context . ', sem prometer milagre.',
            'steps', 'steps3' => 'Passos concretos que alguém de ' . $context . ' reconheceria, numerados.',
            'infographic' => 'Série de slides com dicas ou conceitos de ' . $context . ', um ponto por imagem.',
            default => 'Conteúdo alinhado a ' . $context . '.',
        };
    }

    public static function surpriseSystemAddon(string $formatKey): string
    {
        if (!self::isDesignedKey($formatKey)) {
            return '';
        }

        $label = IdeaPieces::formatLabel($formatKey);

        return implode("\n", [
            'Formato escolhido pelo cliente: ' . $label . ' (obrigatório respeitar).',
            'Isto NÃO é foto stock decorativa: é peça gráfica com texto legível dentro da imagem.',
            'Campo idea deve começar com o rótulo do formato (ex.: Citação:, Checklist:) e incluir linhas estruturadas quando pedido abaixo.',
            'Campo phrase: gancho curto para legenda do Instagram (pode complementar, não repetir palavra por palavra o texto da imagem).',
            self::surpriseIdeaSchema($formatKey),
        ]);
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function surpriseUserAddonForProfile(array $user, string $formatKey, string $destination): string
    {
        if (!self::isDesignedKey($formatKey)) {
            return 'Tipo: foto simples ou cena fotográfica. Sem pôster com parágrafo; sem texto longo dentro da imagem.';
        }
        $frame = $destination === 'story' ? 'story 9:16' : 'feed 4:5';

        return 'Tipo: ' . IdeaPieces::formatLabel($formatKey) . ' para ' . $frame . '. '
            . self::profileContentHint($user, $formatKey) . ' '
            . self::surpriseIdeaSchema($formatKey);
    }

    private static function surpriseIdeaSchema(string $formatKey): string
    {
        return match ($formatKey) {
            'quote' => 'Na idea inclua: Texto na imagem: «citação em português» e Crédito: (autor ou referência bíblica). '
                . 'Descreva também fundo/cores tipográficas, sem pedir só uma porta ou paisagem sem texto.',
            'checklist' => 'Na idea liste Item 1: … até Item 6: … (títulos curtos que entram na arte).',
            'compare' => 'Na idea defina Coluna esquerda: … Coluna direita: … com 2 a 4 linhas cada.',
            'before_after' => 'Na idea descreva estado ANTES e estado DEPOIS (rótulos ANTES/DEPOIS na arte).',
            'steps', 'steps3' => 'Na idea numerar Passo 1 … Passo N com título curto em cada passo.',
            'infographic' => 'Na idea indique título da série e ponto deste slide; se for capa, diga Capa:.',
            default => '',
        };
    }

    /**
     * @param array<string, mixed> $user
     */
    private static function signalText(array $user): string
    {
        $chunks = [
            (string) ($user['profession'] ?? ''),
            (string) ($user['about'] ?? ''),
            (string) ($user['display_name'] ?? ''),
            (string) ($user['brand_style'] ?? ''),
            (string) ($user['contact_cta'] ?? ''),
        ];

        return mb_strtolower(trim(implode(' ', array_filter($chunks, static fn (string $p): bool => trim($p) !== ''))));
    }
}
