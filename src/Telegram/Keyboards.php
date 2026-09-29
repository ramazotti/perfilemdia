<?php

declare(strict_types=1);

namespace PerfilEmDia\Telegram;

final class Keyboards
{
    /**
     * @return list<list<array{text:string, callback_data?:string, url?:string}>>
     */
    public static function approval(int $postId, bool $allowStory = false): array
    {
        $rows = [
            [
                ['text' => 'Publicar no Instagram', 'callback_data' => 'a:pub:' . $postId],
                ['text' => 'Agendar horário', 'callback_data' => 'a:sch:' . $postId],
            ],
            [['text' => 'Revisar texto e foto', 'callback_data' => 'a:mor:' . $postId]],
        ];
        if ($allowStory) {
            $rows[] = [['text' => 'Publicar no story', 'callback_data' => 'a:sty:' . $postId]];
        }
        $rows[] = [['text' => 'Descartar este post', 'callback_data' => 'a:can:' . $postId]];

        return $rows;
    }

    /**
     * @return list<list<array{text:string, callback_data?:string, url?:string}>>
     */
    public static function where(): array
    {
        return [[
            ['text' => 'Feed (grade do perfil)', 'callback_data' => 'wh:feed'],
            ['text' => 'Story (24 horas)', 'callback_data' => 'wh:story'],
        ]];
    }

    /**
     * @return list<list<array{text:string, callback_data?:string, url?:string}>>
     */
    public static function storyKind(): array
    {
        return [
            [
                ['text' => 'Enviar minha foto', 'callback_data' => 'pk:foto'],
                ['text' => 'Enviar meu vídeo', 'callback_data' => 'pk:video'],
            ],
            [['text' => 'IA cria a imagem', 'callback_data' => 'pk:ia']],
        ];
    }

    /**
     * @return list<list<array{text:string, callback_data?:string, url?:string}>>
     */
    public static function adjustMenu(
        int $postId,
        bool $storyLook,
        bool $allowRegen,
        bool $allowPhoto,
        bool $allowMark,
        bool $allowIdea,
        bool $allowPhrase,
    ): array {
        $rows = [];
        $textRow = [[
            'text' => $storyLook ? 'Pedir outro texto na foto' : 'Pedir nova legenda (IA)',
            'callback_data' => 'a:adj:' . $postId,
        ]];
        if ($allowRegen) {
            $textRow[] = ['text' => 'Gerar tudo de novo', 'callback_data' => 'a:reg:' . $postId];
        }
        $rows[] = $textRow;
        $rows[] = [[
            'text' => $storyLook ? 'Escrever o texto na foto' : 'Escrever a legenda eu mesma',
            'callback_data' => 'a:man:' . $postId,
        ]];
        if ($storyLook) {
            $rows[] = [['text' => 'Estilo, cor e posição', 'callback_data' => 'a:look:' . $postId]];
        }
        $photoRow = [];
        if ($allowPhrase) {
            $photoRow[] = ['text' => 'Frase extra na imagem', 'callback_data' => 'a:txt:' . $postId];
        }
        if ($allowPhoto) {
            $photoRow[] = ['text' => 'Melhorar foto (IA)', 'callback_data' => 'a:img:' . $postId];
        }
        if ($photoRow !== []) {
            $rows[] = $photoRow;
        }
        if ($allowMark) {
            $rows[] = [['text' => 'Logo ou foto do perfil', 'callback_data' => 'a:wm:' . $postId]];
        }
        if ($allowIdea) {
            $rows[] = [['text' => 'Trocar imagem (IA)', 'callback_data' => 'a:pic:' . $postId]];
        }
        $rows[] = [['text' => 'Voltar à prévia', 'callback_data' => 'a:back:' . $postId]];

        return $rows;
    }

    /**
     * @return list<list<array{text:string, callback_data?:string, url?:string}>>
     */
    public static function storyLook(int $postId, string $style, string $place, string $color): array
    {
        $styles = ['limpa' => 'Só texto', 'caixa' => 'Faixa escura', 'balao' => 'Balão branco'];
        $styleRow = [];
        foreach ($styles as $key => $label) {
            if ($key === $style) {
                $label .= " \u{2713}";
            }
            $styleRow[] = ['text' => $label, 'callback_data' => 'f:' . $key . ':' . $postId];
        }
        $places = ['topo' => 'Texto no topo', 'meio' => 'Texto no meio', 'rodape' => 'Texto embaixo'];
        $placeRow = [];
        foreach ($places as $key => $label) {
            if ($key === $place) {
                $label .= " \u{2713}";
            }
            $placeRow[] = ['text' => $label, 'callback_data' => 'pp:' . $key . ':' . $postId];
        }
        $colors = ['branco' => 'Letra branca', 'preto' => 'Letra preta'];
        $colorRow = [];
        foreach ($colors as $key => $label) {
            if ($key === $color) {
                $label .= " \u{2713}";
            }
            $colorRow[] = ['text' => $label, 'callback_data' => 'pc:' . $key . ':' . $postId];
        }

        return [
            $styleRow,
            $placeRow,
            $colorRow,
            [['text' => 'Voltar à prévia', 'callback_data' => 'a:back:' . $postId]],
        ];
    }

    public static function scheduleChoices(int $postId, bool $todayEvening): array
    {
        $rows = [];
        if ($todayEvening) {
            $rows[] = [['text' => 'Hoje 18h', 'callback_data' => 's:t18:' . $postId]];
        }
        $rows[] = [
            ['text' => 'Amanhã 9h', 'callback_data' => 's:n9:' . $postId],
            ['text' => 'Amanhã 18h', 'callback_data' => 's:n18:' . $postId],
        ];
        $rows[] = [['text' => 'Outro horário', 'callback_data' => 's:in:' . $postId]];

        return $rows;
    }

    /**
     * @return list<list<array{text:string, callback_data?:string, url?:string}>>
     */
    public static function scheduled(int $postId): array
    {
        return [
            [['text' => 'Publicar no Instagram agora', 'callback_data' => 'a:pub:' . $postId]],
            [['text' => 'Cancelar o agendamento', 'callback_data' => 'a:uns:' . $postId]],
        ];
    }

    /**
     * @return list<list<array{text:string, callback_data?:string, url?:string}>>
     */
    public static function markSource(int $postId, bool $hasLogo): array
    {
        $rows = [
            [['text' => 'Usar foto do Instagram', 'callback_data' => 'm:ig:' . $postId]],
        ];
        if ($hasLogo) {
            $rows[] = [['text' => 'Usar logo que enviei', 'callback_data' => 'm:ok:' . $postId]];
        }
        $rows[] = [['text' => 'Enviar arquivo de logo', 'callback_data' => 'm:up:' . $postId]];

        return $rows;
    }

    /**
     * @return list<list<array{text:string, callback_data?:string, url?:string}>>
     */
    public static function phraseStyles(int $postId, string $current, string $color = 'branco', string $place = 'rodape', string $size = 'normal', bool $canRemove = false): array
    {
        $labels = [
            'cursiva' => 'Fonte cursiva',
            'classica' => "Fonte cl\u{00e1}ssica",
            'limpa' => "S\u{00f3} texto",
            'forte' => 'Texto em negrito',
            'balao' => "Bal\u{00e3}o branco",
            'caixa' => 'Faixa escura',
        ];
        $button = static function (string $style) use ($postId, $current, $labels): array {
            $text = $labels[$style];
            if ($style === $current) {
                $text .= " \u{2713}";
            }

            return ['text' => $text, 'callback_data' => 'f:' . $style . ':' . $postId];
        };
        $colors = [
            'branco' => 'Letra branca',
            'preto' => 'Letra preta',
        ];
        $colorRow = [];
        foreach ($colors as $key => $label) {
            if ($key === $color) {
                $label .= " \u{2713}";
            }
            $colorRow[] = ['text' => $label, 'callback_data' => 'pc:' . $key . ':' . $postId];
        }
        $places = [
            'topo' => 'Texto no topo',
            'meio' => 'Texto no meio',
            'rodape' => 'Texto embaixo',
        ];
        $placeRow = [];
        foreach ($places as $key => $label) {
            if ($key === $place) {
                $label .= " \u{2713}";
            }
            $placeRow[] = ['text' => $label, 'callback_data' => 'pp:' . $key . ':' . $postId];
        }
        $sizes = [
            'menor' => 'Letra menor',
            'normal' => "Letra m\u{00e9}dia",
            'maior' => 'Letra maior',
        ];
        $sizeRow = [];
        foreach ($sizes as $key => $label) {
            if ($key === $size) {
                $label .= " \u{2713}";
            }
            $sizeRow[] = ['text' => $label, 'callback_data' => 'ps:' . $key . ':' . $postId];
        }
        $rows = [
            [$button('cursiva'), $button('classica')],
            [$button('limpa'), $button('forte')],
            [$button('balao'), $button('caixa')],
            $colorRow,
            $placeRow,
            $sizeRow,
        ];
        if ($canRemove) {
            $rows[] = [['text' => 'Remover frase da foto', 'callback_data' => 'px:' . $postId]];
        }

        return $rows;
    }

    /**
     * @return list<list<array{text:string, callback_data?:string, url?:string}>>
     */
    public static function markPlace(int $postId, string $source = 'ig'): array
    {
        $source = $source === 'lg' ? 'lg' : 'ig';

        return [
            [
                ['text' => 'Em cima, à esquerda', 'callback_data' => 'w:tl:' . $source . ':' . $postId],
                ['text' => 'Em cima, à direita', 'callback_data' => 'w:tr:' . $source . ':' . $postId],
            ],
            [
                ['text' => 'Embaixo, à esquerda', 'callback_data' => 'w:bl:' . $source . ':' . $postId],
                ['text' => 'Embaixo, à direita', 'callback_data' => 'w:br:' . $source . ':' . $postId],
            ],
            [
                ['text' => 'No centro', 'callback_data' => 'w:c:' . $source . ':' . $postId],
            ],
        ];
    }

    public static function tone(): array
    {
        return [
            [
                ['text' => 'Profissional', 'callback_data' => 'tom:profissional'],
                ['text' => 'Descontraído', 'callback_data' => 'tom:descontraido'],
            ],
            [
                ['text' => 'Técnico', 'callback_data' => 'tom:tecnico'],
                ['text' => 'Acolhedor', 'callback_data' => 'tom:acolhedor'],
            ],
        ];
    }

    /**
     * @return list<list<array{text:string, callback_data?:string, url?:string}>>
     */
    public static function skip(string $step): array
    {
        return [[['text' => 'Pular esta etapa', 'callback_data' => 'pular:' . $step]]];
    }

    /**
     * @return list<list<array{text:string, callback_data?:string, url?:string}>>
     */
    public static function yesNoPending(): array
    {
        return [
            [['text' => 'Sim, descartar o anterior', 'callback_data' => 'novo:sim']],
            [['text' => 'Não, manter o anterior', 'callback_data' => 'novo:nao']],
        ];
    }

    /**
     * @return list<list<array{text:string, callback_data?:string, url?:string}>>
     */
    public static function instagramConnect(string $url): array
    {
        return [
            [['text' => 'Já é profissional, conectar', 'url' => $url]],
            [['text' => 'Me mostra como', 'callback_data' => 'como:como']],
        ];
    }

    /**
     * @return list<list<array{text:string, callback_data?:string, url?:string}>>
     */
    public static function perfilFields(): array
    {
        return [
            [
                ['text' => 'Nome', 'callback_data' => 'perfil:nome'],
                ['text' => 'O que faz', 'callback_data' => 'perfil:profissao'],
            ],
            [
                ['text' => 'Cidade', 'callback_data' => 'perfil:cidade'],
                ['text' => 'Tom', 'callback_data' => 'perfil:tom'],
            ],
            [
                ['text' => 'Contato', 'callback_data' => 'perfil:contato'],
                ['text' => 'Sobre', 'callback_data' => 'perfil:sobre'],
                ['text' => 'Marca', 'callback_data' => 'perfil:marca'],
            ],
        ];
    }

    /**
     * @return list<list<array{text:string, callback_data?:string, url?:string}>>
     */
    public static function deleteConfirm(): array
    {
        return [
            [['text' => 'Apagar meus dados', 'callback_data' => 'del:sim']],
            [['text' => 'Cancelar', 'callback_data' => 'del:nao']],
        ];
    }

    /**
     * @return list<list<array{text:string, callback_data?:string, url?:string}>>
     */
    /**
     * @param list<int> $openIds
     * @return list<list<array{text:string, callback_data?:string, url?:string}>>
     */
    public static function ticketMenu(array $openIds): array
    {
        $rows = [[['text' => 'Abrir chamado', 'callback_data' => 'ch:novo']]];
        foreach ($openIds as $id) {
            $rows[] = [['text' => 'Chamado #' . $id, 'callback_data' => 'ch:ver:' . $id]];
        }

        return $rows;
    }

    /**
     * @return list<list<array{text:string, callback_data?:string, url?:string}>>
     */
    public static function ticketCompose(): array
    {
        return [[['text' => 'Desistir', 'callback_data' => 'ch:sair']]];
    }

    /**
     * @return list<list<array{text:string, callback_data?:string, url?:string}>>
     */
    public static function ticketOpen(int $id, bool $closed): array
    {
        if ($closed) {
            return [
                [['text' => 'Abrir outro chamado', 'callback_data' => 'ch:novo']],
                [['text' => 'Voltar', 'callback_data' => 'ch:menu']],
            ];
        }

        return [
            [['text' => 'Escrever neste chamado', 'callback_data' => 'ch:resp:' . $id]],
            [['text' => 'Voltar', 'callback_data' => 'ch:menu']],
        ];
    }

    /**
     * @return list<list<array{text:string, callback_data?:string, url?:string}>>
     */
    public static function openUrl(string $label, string $url): array
    {
        return [[['text' => $label, 'url' => $url]]];
    }

    /**
     * @return list<list<array{text:string, callback_data?:string, url?:string}>>
     */
    public static function postKind(bool $aiVideo = false): array
    {
        $rows = [
            [
                ['text' => '1 foto no feed', 'callback_data' => 'pk:foto'],
                ['text' => 'Carrossel (várias fotos)', 'callback_data' => 'pk:album'],
            ],
            [
                ['text' => 'Vídeo curto', 'callback_data' => 'pk:video'],
                ['text' => 'IA cria imagem e legenda', 'callback_data' => 'pk:ia'],
            ],
        ];
        if ($aiVideo) {
            $rows[] = [['text' => 'IA gera vídeo curto', 'callback_data' => 'pk:aivideo']];
        }

        return $rows;
    }

    /**
     * @return list<list<array{text:string, callback_data?:string, url?:string}>>
     */
    public static function videoSeconds(): array
    {
        return [
            [
                ['text' => '4 segundos', 'callback_data' => 'vd:4'],
                ['text' => '5 segundos', 'callback_data' => 'vd:5'],
                ['text' => '6 segundos', 'callback_data' => 'vd:6'],
            ],
            [
                ['text' => '8 segundos', 'callback_data' => 'vd:8'],
                ['text' => '15 segundos', 'callback_data' => 'vd:15'],
            ],
        ];
    }

    public static function publishRetry(int $postId): array
    {
        return [[['text' => 'Tentar publicar de novo', 'callback_data' => 'a:pub:' . $postId]]];
    }

    /**
     * @return list<list<array{text:string, callback_data?:string, url?:string}>>
     */
    public static function ideaActions(bool $surprise = true): array
    {
        $rows = [];
        if ($surprise) {
            $rows[] = [['text' => 'Surpreenda-me (IA decide)', 'callback_data' => 'id:sur']];
            $rows[] = [['text' => 'Criar post com IA', 'callback_data' => 'id:ia']];
        }
        $rows[] = [
            ['text' => 'Ideia diária no Telegram', 'callback_data' => 'id:on'],
            ['text' => 'Parar ideias diárias', 'callback_data' => 'id:off'],
        ];

        return $rows;
    }

    /**
     * @return list<list<array{text:string, callback_data?:string, url?:string}>>
     */
    public static function surpriseMe(): array
    {
        return [[['text' => 'Surpreenda-me (IA decide)', 'callback_data' => 'pk:surpresa']]];
    }
}
