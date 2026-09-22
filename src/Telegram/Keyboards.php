<?php

declare(strict_types=1);

namespace PerfilEmDia\Telegram;

final class Keyboards
{
    /**
     * @return list<list<array{text:string, callback_data?:string, url?:string}>>
     */
    public static function approval(int $postId, bool $allowRegen): array
    {
        $rows = [
            [['text' => 'Publicar', 'callback_data' => 'a:pub:' . $postId]],
        ];
        if ($allowRegen) {
            $rows[] = [
                ['text' => 'Ajustar', 'callback_data' => 'a:adj:' . $postId],
                ['text' => 'Outra versao', 'callback_data' => 'a:reg:' . $postId],
            ];
        }
        $rows[] = [['text' => 'Escrever eu mesmo', 'callback_data' => 'a:man:' . $postId]];
        $rows[] = [['text' => 'Cancelar', 'callback_data' => 'a:can:' . $postId]];

        return $rows;
    }

    /**
     * @return list<list<array{text:string, callback_data?:string, url?:string}>>
     */
    public static function tone(): array
    {
        return [
            [
                ['text' => 'Profissional', 'callback_data' => 'tom:profissional'],
                ['text' => 'Descontraido', 'callback_data' => 'tom:descontraido'],
            ],
            [
                ['text' => 'Tecnico', 'callback_data' => 'tom:tecnico'],
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
            [['text' => 'Sim, comecar novo', 'callback_data' => 'novo:sim']],
            [['text' => 'Nao, voltar ao anterior', 'callback_data' => 'novo:nao']],
        ];
    }

    /**
     * @return list<list<array{text:string, callback_data?:string, url?:string}>>
     */
    public static function instagramConnect(string $url): array
    {
        return [
            [['text' => 'Ja e profissional, conectar', 'url' => $url]],
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
                ['text' => 'Profissao', 'callback_data' => 'perfil:profissao'],
            ],
            [
                ['text' => 'Cidade', 'callback_data' => 'perfil:cidade'],
                ['text' => 'Tom', 'callback_data' => 'perfil:tom'],
            ],
            [
                ['text' => 'Contato', 'callback_data' => 'perfil:contato'],
                ['text' => 'Sobre', 'callback_data' => 'perfil:sobre'],
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
    public static function openUrl(string $label, string $url): array
    {
        return [[['text' => $label, 'url' => $url]]];
    }
}
