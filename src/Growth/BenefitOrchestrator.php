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

        return self::withPlace($user, $base);
    }

    /**
     * Uma cena nova a cada pedido. A ideia do dia continua fixa.
     *
     * @param array<string, mixed> $user
     * @return array{idea:string, phrase:string}
     */
    public static function surpriseBrief(array $user, DateTimeImmutable $now, ?int $roll = null): array
    {
        $now = $now->setTimezone(new DateTimeZone('America/Sao_Paulo'));
        $scenes = self::surpriseScenes($now, (string) ($user['tone'] ?? ''), (string) ($user['profession'] ?? ''));
        $index = ($roll ?? random_int(0, PHP_INT_MAX)) % count($scenes);
        $scene = $scenes[$index];

        return [
            'idea' => self::withPlace($user, $scene[0]),
            'phrase' => $scene[1],
        ];
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
            'professor' => self::job('Professor', 'a sala de aula calma, com carteiras, quadro e parede clara, sem poluição visual', 'Sala pronta para a aula.'),
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

    /**
     * @param array<string, mixed> $user
     */
    private static function withPlace(array $user, string $base): string
    {
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
     * @return list<array{0:string,1:string}>
     */
    private static function surpriseScenes(DateTimeImmutable $now, string $tone = '', string $profession = ''): array
    {
        $calm = in_array($tone, ['acolhedor', 'profissional'], true);
        $scenes = $calm ? self::surpriseScenesCalm($profession) : self::surpriseScenesDefault();
        if ($tone === 'tecnico') {
            $scenes = array_merge(self::surpriseScenesTechnical(), $scenes);
        }
        $date = self::commemorative($now);
        if ($date !== null) {
            $hint = $date['hint'];
            if ($calm) {
                $hint .= ' Prefira um enquadramento simples, com poucos objetos.';
            }
            $scenes[] = [$hint, 'Hoje, neste tema.'];
        }

        return $scenes;
    }

    /**
     * @return list<array{0:string,1:string}>
     */
    private static function surpriseScenesCalm(string $profession): array
    {
        $who = mb_strtolower(trim($profession));
        if (preg_match('/\b(professor|professora|docente|teacher|educador|educadora)\b/u', $who) === 1
            || str_contains($who, 'escola')
            || str_contains($who, 'ensino')) {
            return [
                ['Mostre a sala de aula vazia e calma: carteiras, quadro e parede clara, sem cartazes ou bagunça.', 'Sala pronta.'],
                ['Mostre a mesa da professora ou do professor, com poucos objetos, luz natural.', 'Mesa do dia.'],
                ['Mostre o quadro ou o livro aberto, com o resto da sala simples ao fundo.', 'Antes da aula.'],
                ['Mostre o corredor quieto da escola, limpo e com luz suave.', 'Escola em silêncio.'],
                ['Mostre um detalhe do material de aula, sozinho sobre a carteira.', 'Material do dia.'],
            ];
        }

        return [
            ['Mostre o espaço de trabalho vazio e quieto, com parede clara e poucos objetos.', 'O dia começa assim.'],
            ['Mostre um único detalhe em close, com fundo limpo e desfocado.', 'De perto, agora.'],
            ['Mostre a sala pronta para o próximo horário, sem pessoas e sem excesso de decoração.', 'Pronto para receber.'],
            ['Mostre a mesa ou bancada organizada, só com o essencial do trabalho.', 'Só o essencial.'],
            ['Mostre um canto calmo do ambiente que quase ninguém fotografa, sem poluição visual.', 'Por dentro.'],
            ['Mostre luz natural entrando em um ambiente simples e cuidado.', 'Luz do dia.'],
            ['Mostre o material do ofício, sozinho, sobre superfície lisa.', 'Ferramenta do dia.'],
            ['Mostre o fim do expediente: tudo no lugar, ambiente sereno.', 'Fim do expediente.'],
        ];
    }

    /**
     * @return list<array{0:string,1:string}>
     */
    private static function surpriseScenesDefault(): array
    {
        return [
            ['Mostre o começo do dia, com o espaço ainda quieto.', 'O dia começa assim.'],
            ['Mostre um detalhe de perto do que foi feito agora.', 'De perto, agora.'],
            ['Mostre o bastidor, antes de ficar pronto.', 'Antes de ficar pronto.'],
            ['Mostre a ferramenta ou o material em uso, sem gente identificável.', 'A ferramenta do dia.'],
            ['Mostre o que mudou no serviço ou no produto nesta semana.', 'O que mudou.'],
            ['Mostre o espaço pronto para o próximo horário.', 'Pronto para o próximo.'],
            ['Mostre o resultado acabado, sem prometer milagre.', 'Ficou pronto.'],
            ['Mostre um cuidado pequeno que costuma passar despercebido.', 'O detalhe que passa.'],
            ['Mostre a mesa ou a bancada no meio do trabalho.', 'No meio do trabalho.'],
            ['Mostre o que saiu hoje, ainda quente ou recém-terminado.', 'Saiu agora.'],
            ['Mostre uma cena da pergunta que o cliente faz toda semana.', 'A pergunta da semana.'],
            ['Mostre o fim do expediente e o que ficou pronto.', 'Fim do expediente.'],
            ['Mostre um canto do espaço que o cliente quase não vê.', 'Por dentro.'],
            ['Mostre o preparo, o passo anterior ao resultado.', 'O passo anterior.'],
        ];
    }

    /**
     * @return list<array{0:string,1:string}>
     */
    private static function surpriseScenesTechnical(): array
    {
        return [
            ['Mostre um passo do processo, com foco na ferramenta ou no material.', 'O passo a passo.'],
            ['Mostre o detalhe técnico que faz diferença no serviço, fundo neutro.', 'Detalhe que importa.'],
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
