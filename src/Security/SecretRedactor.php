<?php

declare(strict_types=1);

namespace PerfilEmDia\Security;

final class SecretRedactor
{
    public static function redact(string $text): string
    {
        if ($text === '') {
            return $text;
        }
        $text = (string) preg_replace('#/bot\d{5,}:[A-Za-z0-9_-]{20,}#', '/bot[redacted]', $text);
        $text = (string) preg_replace('#(access_token|Authorization|Bearer)[=:\s]+[A-Za-z0-9._-]{8,}#i', '$1=[redacted]', $text);
        $text = (string) preg_replace('#([?&]access_token=)[^&\s]+#i', '$1[redacted]', $text);

        return $text;
    }
}
