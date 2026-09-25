<?php

declare(strict_types=1);

namespace PerfilEmDia\Growth;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Junta os recursos que o cliente usa no dia a dia: marca, ideia, story, resultado e páginas por profissão.
 */
final class BenefitOrchestrator
{
    /**
     * @param array<string, mixed> $user
     */
    public static function brandBrief(array $user): string
    {
        $style = trim((string) ($user['brand_style'] ?? ''));
        if ($style === '') {
            return '';
        }

        return 'Keep this brand look: ' . $style . '.';
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function suggestion(array $user, DateTimeImmutable $now): string
    {
        $now = $now->setTimezone(new DateTimeZone('America/Sao_Paulo'));
        $date = self::commemorative($now);
        $base = $date['hint'] ?? self::weekdayHint($now);
        $who = trim((string) ($user['profession'] ?? ''));
        $where = trim((string) ($user['city'] ?? ''));
        $brand = trim((string) ($user['brand_style'] ?? ''));
        $line = $base;
        if ($who !== '') {
            $line .= ' Para ' . $who . '.';
        }
        if ($where !== '' && mb_strtolower($where) !== 'online') {
            $line .= ' Em ' . $where . '.';
        }
        if ($brand !== '') {
            $line .= ' Visual: ' . $brand . '.';
        }

        return mb_substr($line, 0, 240);
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function ideaMessage(array $user, DateTimeImmutable $now, bool $surprise = true): string
    {
        $now = $now->setTimezone(new DateTimeZone('America/Sao_Paulo'));
        $date = self::commemorative($now);
        $head = $date !== null ? 'Hoje é ' . $date['name'] . '.' : 'Ideia para o post de hoje.';
        $tail = $surprise
            ? 'Toque em Surpreenda-me para eu montar a foto, o texto e a marca. Ou mande a sua foto com essa frase. Nada sai sem a sua aprovação.'
            : 'Mande a foto com essa frase. Nada sai sem a sua aprovação.';

        return $head . "\n\n" . self::suggestion($user, $now) . "\n\n" . $tail;
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function nudge(array $user, DateTimeImmutable $now, bool $surprise = true): string
    {
        $tail = $surprise
            ? 'Toque em Surpreenda-me para eu montar a foto, o texto e a marca. Nada sai sem a sua aprovação.'
            : 'Mande a foto com essa frase. Nada sai sem a sua aprovação.';

        return "Oi! Faz mais de 1 dia que você não posta.\n\n"
            . "Separei um pedido pronto, ligado ao que você faz:\n"
            . self::suggestion($user, $now)
            . "\n\n" . $tail;
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function photoPhrase(array $user): string
    {
        $who = mb_strtolower(trim((string) ($user['profession'] ?? '')));
        if ($who !== '') {
            foreach (self::professions() as $job) {
                $name = mb_strtolower($job['name']);
                if ($who === $name || str_contains($who, $name)) {
                    return mb_substr($job['phrase'], 0, 80);
                }
            }
        }

        return 'Hoje, de perto.';
    }

    public static function storyFits(int $mediaCount): bool
    {
        return $mediaCount === 1;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function formatInsights(array $payload): string
    {
        $labels = [
            'reach' => 'Alcance',
            'views' => 'Visualizações',
        ];
        $lines = [];
        $rows = $payload['data'] ?? [];
        if (!is_array($rows)) {
            $rows = [];
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = (string) ($row['name'] ?? '');
            $label = $labels[$name] ?? '';
            if ($label === '') {
                continue;
            }
            $value = $row['total_value']['value'] ?? null;
            if ($value === null && isset($row['values'][0]['value'])) {
                $value = $row['values'][0]['value'];
            }
            if (!is_numeric($value)) {
                continue;
            }
            $lines[] = $label . ': ' . number_format((float) $value, 0, ',', '.');
        }
        if ($lines === []) {
            return 'O Instagram ainda não devolveu números deste período.';
        }

        return "Resultado dos últimos 7 dias:\n" . implode("\n", $lines);
    }

    /**
     * @return array<string, array{name:string, title:string, lead:string, photo:string, phrase:string}>
     */
    public static function professions(): array
    {
        return [
            'nutricionista' => self::job('Nutricionista', 'uma consulta, um prato ou a mesa de atendimento', 'Consulta de hoje, foco no prato que montei.'),
            'advogado' => self::job('Advogado', 'a mesa de trabalho, um documento sem dados de cliente, ou a sala de reunião', 'Bastidor do escritório antes do atendimento.'),
            'dentista' => self::job('Dentista', 'o consultório pronto, sem paciente identificável', 'Consultório pronto para o próximo horário.'),
            'salao-de-beleza' => self::job('Salão de beleza', 'o resultado de um corte ou a bancada organizada, com autorização de quem aparece', 'Corte finalizado e bancada organizada.'),
            'personal' => self::job('Personal trainer', 'um treino, um equipamento ou o espaço da aula', 'Treino de hoje, detalhe do movimento.'),
            'restaurante' => self::job('Restaurante', 'o prato, a cozinha ou o salão antes de abrir', 'Prato do dia, saindo agora da cozinha.'),
            'psicologo' => self::job('Psicólogo', 'a sala de atendimento vazia, a poltrona ou a estante', 'Sala pronta para o próximo horário.'),
            'corretor-de-imoveis' => self::job('Corretor de imóveis', 'a fachada, um cômodo ou a planta, sem dados pessoais', 'Imóvel visitado hoje, o cômodo que mais chamou atenção.'),
            'loja' => self::job('Loja', 'o produto na estante, a vitrine ou uma novidade chegando', 'Novidade na estante, chegou hoje.'),
            'estetica' => self::job('Estética', 'o ambiente, um produto da cabine ou o antes de abrir', 'Cabine pronta para o horário da tarde.'),
            'eletricista' => self::job('Eletricista', 'o serviço terminado, o quadro ou a ferramenta', 'Serviço concluído, detalhe da instalação.'),
            'confeitaria' => self::job('Confeitaria', 'o bolo, a fornada ou a bancada', 'Fornada de hoje, ainda na bancada.'),
            'pet-shop' => self::job('Pet shop', 'um cuidado no pet, com o tutor de acordo, ou o produto na prateleira', 'Banho terminado e toalha dobrada.'),
            'academia' => self::job('Academia', 'a sala, um aparelho ou uma aula começando', 'Sala de aula pronta para o horário.'),
        ];
    }

    /**
     * @return array{name:string, title:string, lead:string, photo:string, phrase:string}|null
     */
    public static function profession(string $slug): ?array
    {
        return self::professions()[$slug] ?? null;
    }

    /**
     * @return array{name:string, hint:string}|null
     */
    public static function commemorative(DateTimeImmutable $now): ?array
    {
        $now = $now->setTimezone(new DateTimeZone('America/Sao_Paulo'));
        $key = $now->format('m-d');
        $dates = [
            '01-01' => ['Ano novo', 'Mostre o que abre o ano no seu trabalho.'],
            '03-08' => ['Dia da mulher', 'Mostre o trabalho de uma mulher do seu negócio, com a autorização dela.'],
            '04-07' => ['Dia mundial da saúde', 'Mostre um cuidado concreto do seu dia, sem prometer resultado.'],
            '04-21' => ['Tiradentes', 'Mostre um bastidor do trabalho de hoje.'],
            '04-22' => ['Dia da terra', 'Mostre um cuidado simples com o material ou com o espaço.'],
            '05-01' => ['Dia do trabalho', 'Mostre o serviço ou o produto de hoje, de perto.'],
            '06-05' => ['Dia do meio ambiente', 'Mostre como você reduz desperdício no dia a dia.'],
            '06-12' => ['Dia dos namorados', 'Mostre um produto ou um serviço que combina com um presente.'],
            '09-07' => ['Independência do Brasil', 'Mostre algo feito aqui, no seu espaço.'],
            '10-12' => ['Dia das crianças', 'Mostre um detalhe do espaço pensado para quem chega com criança.'],
            '10-15' => ['Dia do professor', 'Mostre algo que você ensina no seu ofício.'],
            '11-20' => ['Dia da consciência negra', 'Mostre um trabalho, um produto ou uma pessoa da equipe, com autorização.'],
        ];
        $mothers = self::nthWeekday((int) $now->format('Y'), 5, 0, 2);
        $fathers = self::nthWeekday((int) $now->format('Y'), 8, 0, 2);
        if ($key === $mothers) {
            return ['name' => 'Dia das mães', 'hint' => 'Mostre um cuidado ou um produto que combina com um presente.'];
        }
        if ($key === $fathers) {
            return ['name' => 'Dia dos pais', 'hint' => 'Mostre um cuidado ou um produto que combina com um presente.'];
        }
        if (!isset($dates[$key])) {
            return null;
        }

        return ['name' => $dates[$key][0], 'hint' => $dates[$key][1]];
    }

    /**
     * @return array{name:string, title:string, lead:string, photo:string, phrase:string}
     */
    public static function hubTitle(): string
    {
        return "Posts por profiss\u{00e3}o";
    }

    public static function hubLead(): string
    {
        return "Escolha o of\u{00ed}cio. A p\u{00e1}gina mostra o que fotografar e uma frase para mandar no Telegram.";
    }

    private static function job(string $name, string $photo, string $phrase): array
    {
        return [
            'name' => $name,
            'title' => 'Instagram para ' . mb_strtolower($name) . ' sem parar o expediente',
            'lead' => 'Mande a foto do seu trabalho no Telegram. O Perfil em Dia escreve a legenda e publica no Instagram quando você aprova.',
            'photo' => 'Uma boa foto aqui é ' . $photo . '.',
            'phrase' => $phrase,
        ];
    }

    private static function weekdayHint(DateTimeImmutable $now): string
    {
        $pool = [
            'Mostre um trabalho de hoje, de perto.',
            'Conte o que mudou no produto ou no serviço nesta semana.',
            'Mostre o bastidor, antes de ficar pronto.',
            'Destaque um detalhe que o cliente costuma não ver.',
            'Mostre a abertura do dia, com o espaço pronto.',
            'Mostre o que saiu hoje, ainda na bancada ou na mesa.',
            'Mostre um antes e um depois, sem inventar resultado.',
        ];

        return $pool[(int) $now->format('z') % count($pool)];
    }

    private static function nthWeekday(int $year, int $month, int $weekday, int $nth): string
    {
        $cursor = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), new DateTimeZone('America/Sao_Paulo'));
        $seen = 0;
        while ((int) $cursor->format('n') === $month) {
            if ((int) $cursor->format('w') === $weekday) {
                $seen++;
                if ($seen === $nth) {
                    return $cursor->format('m-d');
                }
            }
            $cursor = $cursor->modify('+1 day');
        }

        return '00-00';
    }
}
