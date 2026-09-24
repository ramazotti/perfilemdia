<?php

declare(strict_types=1);

namespace PerfilEmDia\Telegram;

use CURLFile;
use PerfilEmDia\Config;
use PerfilEmDia\Support\GuzzleHttpPoster;
use PerfilEmDia\Support\HttpPoster;
use RuntimeException;

final class TelegramClient
{
    private string $token;
    private HttpPoster $http;

    public function __construct(?HttpPoster $http = null, ?string $token = null)
    {
        Config::load();
        $this->token = $token ?? Config::get('TELEGRAM_BOT_TOKEN');
        $this->http = $http ?? new GuzzleHttpPoster();
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function request(string $method, array $params = []): array
    {
        $url = 'https://api.telegram.org/bot' . $this->token . '/' . $method;
        $hasFile = false;
        foreach ($params as $value) {
            if ($value instanceof CURLFile) {
                $hasFile = true;
                break;
            }
        }

        if ($hasFile) {
            $multipart = [];
            foreach ($params as $key => $value) {
                if ($value instanceof CURLFile) {
                    $multipart[] = [
                        'name' => $key,
                        'contents' => fopen($value->getFilename(), 'rb'),
                        'filename' => basename($value->getFilename()),
                    ];
                    continue;
                }
                if (is_array($value) || is_object($value)) {
                    $multipart[] = [
                        'name' => $key,
                        'contents' => json_encode($value, JSON_UNESCAPED_UNICODE),
                    ];
                    continue;
                }
                if (is_bool($value)) {
                    $multipart[] = ['name' => $key, 'contents' => $value ? 'true' : 'false'];
                    continue;
                }
                $multipart[] = ['name' => $key, 'contents' => (string) $value];
            }
            $response = $this->http->request('POST', $url, ['multipart' => $multipart, 'timeout' => 60]);
        } else {
            $response = $this->http->request('POST', $url, ['json' => $params, 'timeout' => 45]);
        }

        $body = $response['body'];
        if (!is_array($body)) {
            throw new RuntimeException('Resposta invalida do Telegram em ' . $method);
        }
        if (($body['ok'] ?? false) !== true) {
            $description = (string) ($body['description'] ?? 'erro desconhecido');
            throw new RuntimeException('Telegram ' . $method . ' falhou: ' . $description);
        }

        $result = $body['result'] ?? null;
        if (is_array($result)) {
            return $result;
        }

        return $body;
    }

    /**
     * @return array<string, mixed>
     */
    public function getFile(string $fileId): array
    {
        return $this->request('getFile', ['file_id' => $fileId]);
    }

    public function download(string $fileId, string $destPath): void
    {
        $file = $this->getFile($fileId);
        $filePath = (string) ($file['file_path'] ?? '');
        if ($filePath === '') {
            throw new RuntimeException('file_path ausente no getFile.');
        }

        $url = 'https://api.telegram.org/file/bot' . $this->token . '/' . $filePath;
        $response = $this->http->request('GET', $url, ['timeout' => 60]);
        $body = $response['body'];
        if (is_array($body)) {
            throw new RuntimeException('Download do Telegram retornou JSON inesperado.');
        }

        $dir = dirname($destPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Nao foi possivel criar ' . $dir);
        }

        if (file_put_contents($destPath, (string) $body) === false) {
            throw new RuntimeException('Falha ao gravar ' . $destPath);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function setWebhook(string $url, string $secret): array
    {
        return $this->request('setWebhook', [
            'url' => $url,
            'secret_token' => $secret,
            'allowed_updates' => ['message', 'callback_query'],
            'drop_pending_updates' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function setMyCommands(): array
    {
        return $this->request('setMyCommands', [
            'commands' => [
                ['command' => 'start', 'description' => 'Comecar ou retomar'],
                ['command' => 'novo', 'description' => 'Como enviar um post'],
                ['command' => 'perfil', 'description' => 'Ver e editar perfil'],
                ['command' => 'conectar', 'description' => 'Conectar Instagram'],
                ['command' => 'status', 'description' => 'Conta e limite do mes'],
                ['command' => 'assinatura', 'description' => 'Ver ou cancelar a assinatura'],
                ['command' => 'cancelar', 'description' => 'Cancelar post pendente'],
                ['command' => 'chamado', 'description' => 'Abrir ou ver um chamado'],
                ['command' => 'ideia', 'description' => 'Ideia para o post de hoje'],
                ['command' => 'resultado', 'description' => 'Alcance dos ultimos 7 dias'],
                ['command' => 'marca', 'description' => 'Cor e estilo da marca'],
                ['command' => 'ajuda', 'description' => 'Lista de comandos'],
                ['command' => 'help', 'description' => 'O mesmo que /ajuda'],
                ['command' => 'excluirconta', 'description' => 'Apagar seus dados'],
            ],
        ]);
    }
}
