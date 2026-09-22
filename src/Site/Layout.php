<?php

declare(strict_types=1);

namespace PerfilEmDia\Site;

final class Layout
{
    public static function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    public static function base(): string
    {
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));

        return rtrim(dirname($script), '/');
    }

    public static function url(string $path = '/'): string
    {
        if ($path === '' || $path === '/') {
            return self::base() . '/';
        }

        return self::base() . '/' . ltrim($path, '/');
    }

    public static function money(int $cents): string
    {
        $decimals = $cents % 100 === 0 ? 0 : 2;

        return 'R$ ' . number_format($cents / 100, $decimals, ',', '.');
    }

    public static function csrf(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }

        return (string) $_SESSION['csrf'];
    }

    public static function checkCsrf(): bool
    {
        $sent = (string) ($_POST['csrf'] ?? '');

        return $sent !== '' && hash_equals(self::csrf(), $sent);
    }

    public static function path(): string
    {
        $uri = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
        $base = self::base();
        if ($base !== '' && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base)) ?: '/';
        }
        $uri = '/' . trim($uri, '/');

        return $uri === '/' ? '/' : rtrim($uri, '/');
    }

    public static function page(string $title, string $main, string $active = '', int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        $e = [self::class, 'e'];
        echo '<!DOCTYPE html><html lang="pt-BR"><head>';
        echo '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>' . $e($title) . '</title>';
        echo '<link rel="preconnect" href="https://fonts.googleapis.com">';
        echo '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,800&family=Figtree:wght@400;600;700&display=swap">';
        echo '<link rel="stylesheet" href="' . $e(self::url('assets/site.css')) . '">';
        echo '<script>(function(){try{var t=localStorage.getItem("pd_theme");if(t==="light"||t==="dark")document.documentElement.setAttribute("data-theme",t);}catch(e){}})();</script>';
        echo '</head><body>';
        echo self::header($active);
        echo '<main id="conteudo">' . $main . '</main>';
        echo self::footer();
        echo '<script src="' . $e(self::url('assets/site.js')) . '"></script>';
        echo '</body></html>';
    }

    public static function admin(string $title, string $main, string $active): void
    {
        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        $e = [self::class, 'e'];
        $item = static function (string $href, string $label, string $key) use ($active, $e): string {
            $current = $active === $key ? ' aria-current="page"' : '';

            return '<a href="' . $e(self::url($href)) . '"' . $current . '>' . $e($label) . '</a>';
        };
        echo '<!DOCTYPE html><html lang="pt-BR"><head>';
        echo '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>' . $e($title) . '</title>';
        echo '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,800&family=Figtree:wght@400;600;700&display=swap">';
        echo '<link rel="stylesheet" href="' . $e(self::url('assets/site.css')) . '">';
        echo '</head><body><div class="adm"><aside class="adm-side">';
        echo '<a class="brand" href="' . $e(self::url('admin')) . '"><span class="mark">' . self::mark() . '</span>Perfil em Dia</a>';
        echo $item('admin', 'Painel', 'painel');
        echo $item('admin/clientes', 'Clientes', 'clientes');
        echo $item('admin/posts', 'Posts', 'posts');
        echo $item('admin/pagamentos', 'Pagamentos', 'pagamentos');
        echo $item('admin/planos', 'Planos', 'planos');
        echo $item('admin/cupons', 'Cupons', 'cupons');
        echo $item('admin/ia', 'Uso de IA', 'ia');
        echo $item('admin/eventos', 'Erros e eventos', 'eventos');
        echo $item('admin/config', 'Configurações', 'config');
        echo $item('admin/manual', 'Manual', 'manual');
        echo '<span class="sp"></span>';
        echo '<a href="' . $e(self::url('/')) . '">Ver o site</a>';
        echo '<a href="' . $e(self::url('admin/sair')) . '">Sair</a>';
        echo '</aside><div class="adm-main"><div class="adm-top"><h1>' . $e($title) . '</h1></div>';
        echo $main;
        echo '</div></div></body></html>';
    }

    private static function header(string $active): string
    {
        $e = [self::class, 'e'];
        $a = static function (string $key) use ($active): string {
            return $active === $key ? ' aria-current="page"' : '';
        };

        return '<header class="site-head"><div class="wrap">'
            . '<a class="brand" href="' . $e(self::url('/')) . '"><span class="mark">' . self::mark() . '</span>Perfil em Dia</a>'
            . '<nav class="site-nav" aria-label="Principal">'
            . '<a href="' . $e(self::url('/#como')) . '"' . $a('como') . '>Como funciona</a>'
            . '<a href="' . $e(self::url('planos')) . '"' . $a('planos') . '>Planos</a>'
            . '<a href="' . $e(self::url('ajuda')) . '"' . $a('ajuda') . '>Ajuda</a>'
            . '<a href="' . $e(self::url('manual')) . '"' . $a('manual') . '>Passo a passo</a>'
            . '</nav>'
            . '<a class="btn btn-primary btn-sm" href="' . $e(self::url('planos')) . '">Começar</a>'
            . '</div></header>';
    }

    private static function footer(): string
    {
        $e = [self::class, 'e'];

        return '<footer class="site-foot"><div class="wrap"><span class="grow">© ' . date('Y') . ' Perfil em Dia. perfilemdia.com.br</span>'
            . '<a href="' . $e(self::url('privacidade')) . '">Privacidade</a>'
            . '<a href="' . $e(self::url('termos')) . '">Termos de uso</a>'
            . '<a href="' . $e(self::url('exclusao-de-dados')) . '">Exclusão de dados</a>'
            . '<a href="' . $e(self::url('manual')) . '">Área do cliente</a>'
            . '<a href="' . $e(self::url('admin')) . '">Área administrativa</a>'
            . '<button class="theme-t" type="button" data-act="theme">Tema: automático</button>'
            . '</div></footer>';
    }

    public static function mark(): string
    {
        return '<svg viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 9V5a1 1 0 0 1 1-1h4"/><path d="M20 15v4a1 1 0 0 1-1 1h-4"/><path d="M7.5 12.5l3 3 6-7"/></svg>';
    }

    public static function check(): string
    {
        return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>';
    }
}
