<?php

declare(strict_types=1);

namespace PerfilEmDia;

final class Http
{
    /**
     * Libera a resposta HTTP para o cliente e segue o processamento.
     * Retorna false quando o SAPI nao oferece fastcgi_finish_request nem litespeed_finish_request.
     */
    public static function finishRequest(): bool
    {
        if (function_exists('fastcgi_finish_request')) {
            return fastcgi_finish_request();
        }

        if (function_exists('litespeed_finish_request')) {
            return litespeed_finish_request();
        }

        return false;
    }
}
