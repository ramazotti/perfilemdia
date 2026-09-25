<?php

declare(strict_types=1);

namespace PerfilEmDia\Telegram;

final class Keyboards
{
    /**
     * @return list<list<array{text:string, callback_data?:string, url?:string}>>
     */
    public static function approval(int $postId, bool $allowRegen, bool $allowPhoto = false, bool $allowMark = false, bool $allowStory = false, bool $allowIdea = false, bool $allowPhrase = false): array
    {
        $rows = [
            [
                ['text' => 'Publicar', 'callback_data' => 'a:pub:' . $postId],
                ['text' => 'Agendar', 'callback_data' => 'a:sch:' . $postId],
            ],
        ];
        if ($allowRegen) {
            $rows[] = [
                ['text' => 'Ajustar', 'callback_data' => 'a:adj:' . $postId],
                ['text' => 'Outra versão', 'callback_data' => 'a:reg:' . $postId],
            ];
        }
        if ($allowIdea) {
            $rows[] = [['text' => 'Outra foto', 'callback_data' => 'a:pic:' . $postId]];
        }
        $rows[] = [['text' => 'Escrever eu mesmo', 'callback_data' => 'a:man:' . $postId]];
        if ($allowPhrase || $allowPhoto) {
            $photoRow = [];
            if ($allowPhrase) {
                $photoRow[] = ['text' => 'Texto na foto', 'callback_data' => 'a:txt:' . $postId];
            }
            if ($allowPhoto) {
                $photoRow[] = ['text' => 'Tratar foto', 'callback_data' => 'a:img:' . $postId];
            }
            $rows[] = $photoRow;
        }
        if ($allowMark) {
            $rows[] = [['text' => 'Marca d\'água', 'callback_data' => 'a:wm:' . $postId]];
        }
        if ($allowStory) {
            $rows[] = [['text' => 'Publicar no story', 'callback_data' => 'a:sty:' . $postId]];
        }
        $rows[] = [['text' => 'Cancelar', 'callback_data' => 'a:can:' . $postId]];

        return $rows;
    }

    /**
     * @return list<list<array{text:string, callback_data?:string, url?:string}>>
     */
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
            [['text' => 'Publicar agora', 'callback_data' => 'a:pub:' . $postId]],
            [['text' => 'Cancelar agendamento', 'callback_data' => 'a:uns:' . $postId]],
        ];
    }

    /**
     * @return list<list<array{text:string, callback_data?:string, url?:string}>>
     */
    public static function markSource(int $postId, bool $hasLogo): array
    {
        $rows = [
            [['text' => 'Foto do perfil', 'callback_data' => 'm:ig:' . $postId]],
        ];
        if ($hasLogo) {
            $rows[] = [['text' => 'Minha logo', 'callback_data' => 'm:ok:' . $postId]];
        }
        $rows[] = [['text' => 'Enviar logo', 'callback_data' => 'm:up:' . $postId]];

        return $rows;
    }

    /**
     * @return list<list<array{text:string, callback_data?:string, url?:string}>>
     */
    public static function phraseStyles(int $postId, string $current): array
    {
        $labels = [
            'cursiva' => 'Cursiva',
            'classica' => "Cl\u{00e1}ssica",
            'limpa' => 'Limpa',
            'forte' => 'Forte',
        ];
        $button = static function (string $style) use ($postId, $current, $labels): array {
            $text = $labels[$style];
            if ($style === $current) {
                $text .= " \u{2713}";
            }

            return ['text' => $text, 'callback_data' => 'f:' . $style . ':' . $postId];
        };

        return [
            [$button('cursiva'), $button('classica')],
            [$button('limpa'), $button('forte')],
        ];
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
        return [[['text' => 'Pular', 'callback_data' => 'pular:' . $step]]];
    }

    /**
     * @return list<list<array{text:string, callback_data?:string, url?:string}>>
     */
    public static function yesNoPending(): array
    {
        return [
            [['text' => 'Sim, começar novo', 'callback_data' => 'novo:sim']],
            [['text' => 'Não, voltar ao anterior', 'callback_data' => 'novo:nao']],
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
                ['text' => 'Foto única', 'callback_data' => 'pk:foto'],
                ['text' => 'Carrossel', 'callback_data' => 'pk:album'],
            ],
            [
                ['text' => 'Vídeo curto', 'callback_data' => 'pk:video'],
                ['text' => 'Criado pela IA', 'callback_data' => 'pk:ia'],
            ],
        ];
        if ($aiVideo) {
            $rows[] = [['text' => "V\u{00ed}deo com IA", 'callback_data' => 'pk:aivideo']];
        }

        return $rows;
    }

    /**
     * @return list<list<array{text:string, callback_data?:string, url?:string}>>
     */
    public static function videoSeconds(): array
    {
        return [[
            ['text' => '4 segundos', 'callback_data' => 'vd:4'],
            ['text' => '6 segundos', 'callback_data' => 'vd:6'],
            ['text' => '8 segundos', 'callback_data' => 'vd:8'],
        ]];
    }

    public static function publishRetry(int $postId): array
    {
        return [[['text' => 'Tentar de novo', 'callback_data' => 'a:pub:' . $postId]]];
    }

    /**
     * @return list<list<array{text:string, callback_data?:string, url?:string}>>
     */
    public static function ideaActions(): array
    {
        return [
            [['text' => 'Criar pela IA', 'callback_data' => 'id:ia']],
            [
                ['text' => 'Receber todo dia', 'callback_data' => 'id:on'],
                ['text' => 'Parar', 'callback_data' => 'id:off'],
            ],
        ];
    }
}
