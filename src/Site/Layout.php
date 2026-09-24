<?php

declare(strict_types=1);

namespace PerfilEmDia\Site;

use PerfilEmDia\Config;

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

    public static function when(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $raw = substr($value, 0, 19);
        $date = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $raw)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d', substr($raw, 0, 10));
        if (!$date instanceof \DateTimeImmutable) {
            return $value;
        }
        if (strlen($raw) === 10) {
            $date = $date->setTime(0, 0, 0);
        }

        return $date->format('d/m/Y H:i:s');
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

    public static function page(string $title, string $main, string $active = '', int $status = 200, string $description = '', string $jsonLd = ''): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        if (self::indexable(self::path(), $status)) {
            header('Cache-Control: public, max-age=600');
        }
        $e = [self::class, 'e'];
        echo '<!DOCTYPE html><html lang="pt-BR"><head>';
        echo '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>' . $e(self::documentTitle($title)) . '</title>';
        echo self::meta($title, $description, $status, $jsonLd);
        echo '<link rel="preconnect" href="https://fonts.googleapis.com">';
        echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
        echo '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,800&family=Figtree:wght@400;600;700&display=swap">';
        echo '<link rel="stylesheet" href="' . $e(self::asset('assets/site.css')) . '">';
        echo '<script>(function(){try{var t=localStorage.getItem("pd_theme");if(t==="light"||t==="dark")document.documentElement.setAttribute("data-theme",t);}catch(e){}})();</script>';
        echo '</head><body>';
        echo self::header($active);
        echo '<main id="conteudo">' . $main . '</main>';
        echo self::footer();
        echo self::telegramHelp();
        echo self::cookieBar();
        echo '<script src="' . $e(self::asset('assets/site.js')) . '"></script>';
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
        echo '<title>' . $e($title . ' | Perfil em Dia') . '</title>';
        echo '<meta name="robots" content="noindex,nofollow">';
        echo self::icons();
        echo '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,800&family=Figtree:wght@400;600;700&display=swap">';
        echo '<link rel="stylesheet" href="' . $e(self::asset('assets/site.css')) . '">';
        echo '</head><body><div class="adm"><aside class="adm-side">';
        echo '<a class="brand" href="' . $e(self::url('admin')) . '"><span class="mark">' . self::mark() . '</span>Perfil em Dia</a>';
        echo $item('admin', 'Painel', 'painel');
        echo $item('admin/clientes', 'Clientes', 'clientes');
        echo $item('admin/chamados', 'Chamados', 'chamados');
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
            . '<button class="nav-toggle" type="button" aria-expanded="false" aria-controls="site-nav">Menu</button>'
            . '<nav id="site-nav" class="site-nav" aria-label="Principal">'
            . '<a href="' . $e(self::url('/#como')) . '"' . $a('como') . '>Como funciona</a>'
            . '<a href="' . $e(self::url('planos')) . '"' . $a('planos') . '>Planos</a>'
            . '<a href="' . $e(self::url('ajuda')) . '"' . $a('ajuda') . '>Ajuda</a>'
            . '<a href="' . $e(self::url('manual')) . '"' . $a('manual') . '>Passo a passo</a>'
            . '</nav>'
            . '<a class="btn btn-primary btn-sm" href="' . $e(self::url('planos')) . '">Começar</a>'
            . '</div></header>';
    }

    private static function asset(string $file): string
    {
        $path = Config::root() . '/public/' . $file;
        $version = is_file($path) ? (string) filemtime($path) : '1';

        return self::url($file) . '?v=' . $version;
    }

    private static function footer(): string
    {
        $e = [self::class, 'e'];

        return '<footer class="site-foot"><div class="wrap"><span class="grow">© ' . date('Y') . ' Perfil em Dia. perfilemdia.com.br</span>'
            . '<a href="' . $e(self::url('profissoes')) . '">Profissões</a>'
            . '<a href="' . $e(self::url('privacidade')) . '">Privacidade</a>'
            . '<a href="' . $e(self::url('termos')) . '">Termos de uso</a>'
            . '<a href="' . $e(self::url('exclusao-de-dados')) . '">Exclusão de dados</a>'
            . '<a href="' . $e(self::url('manual')) . '">Área do cliente</a>'
            . '<button class="theme-t" type="button" data-act="theme">Tema: automático</button>'
            . '</div></footer>';
    }


    public static function absolute(string $path = '/'): string
    {
        $base = rtrim(Config::get('APP_URL', ''), '/');
        if ($base === '') {
            $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
            $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
            $base = ($https ? 'https' : 'http') . '://' . $host . self::base();
        }
        if ($path === '' || $path === '/') {
            return $base . '/';
        }

        return $base . '/' . ltrim($path, '/');
    }

    private static function documentTitle(string $title): string
    {
        if ($title === 'Perfil em Dia') {
            return 'Perfil em Dia: Instagram em dia sem tempo para postar';
        }

        return $title . ' | Perfil em Dia';
    }

    private static function blurb(string $path): string
    {
        return match ($path) {
            '/' => 'Mande a foto do serviço ou do produto no Telegram. O Perfil em Dia escreve a legenda, mostra a prévia e publica no Instagram quando você aprova.',
            '/planos' => 'Planos do Perfil em Dia para publicar no Instagram pelo Telegram, no mensal ou no anual, sem fidelidade.',
            '/ajuda' => 'Como conectar o Instagram, ajustar a foto, cancelar a assinatura e pedir a exclusão dos dados no Perfil em Dia.',
            '/manual', '/conta' => 'Passo a passo para pagar, abrir o bot, conectar o Instagram e publicar a primeira foto.',
            '/privacidade' => 'Como o Perfil em Dia trata nome, celular, fotos e a conexão com o Instagram.',
            '/termos' => 'Condições de uso da assinatura, da publicação no Instagram e do cancelamento.',
            '/exclusao-de-dados' => 'Como pedir a exclusão dos seus dados e acompanhar o protocolo.',
            default => 'Mande a foto do serviço ou do produto no Telegram. O Perfil em Dia escreve a legenda, mostra a prévia e publica no Instagram quando você aprova.',
        };
    }

    private static function indexable(string $path, int $status): bool
    {
        if ($status !== 200) {
            return false;
        }

        if ($path === '/profissoes' || preg_match('#^/profissoes/[a-z0-9-]+$#', $path) === 1) {
            return true;
        }

        return in_array($path, ['/', '/planos', '/ajuda', '/manual', '/privacidade', '/termos', '/exclusao-de-dados'], true);
    }

    private static function meta(string $title, string $description, int $status, string $jsonLd): string
    {
        $path = self::path();
        $canonPath = $path === '/conta' ? '/manual' : $path;
        $desc = $description !== '' ? $description : self::blurb($path);
        $canon = self::absolute($canonPath);
        $image = self::absolute('assets/og.png') . '?v=2';
        $index = self::indexable($path, $status);
        $e = [self::class, 'e'];
        $docTitle = self::documentTitle($title);
        $html = '<meta name="description" content="' . $e($desc) . '">';
        $html .= '<link rel="canonical" href="' . $e($canon) . '">';
        $html .= '<meta name="robots" content="' . ($index ? 'index,follow,max-image-preview:large' : 'noindex,nofollow') . '">';
        $html .= self::verification();
        $html .= self::icons();
        $html .= '<meta property="og:locale" content="pt_BR">';
        $html .= '<meta property="og:type" content="website">';
        $html .= '<meta property="og:site_name" content="Perfil em Dia">';
        $html .= '<meta property="og:title" content="' . $e($docTitle) . '">';
        $html .= '<meta property="og:description" content="' . $e($desc) . '">';
        $html .= '<meta property="og:url" content="' . $e($canon) . '">';
        $html .= '<meta property="og:image" content="' . $e($image) . '">';
        $html .= '<meta property="og:image:width" content="1200">';
        $html .= '<meta property="og:image:height" content="630">';
        $html .= '<meta property="og:image:alt" content="Perfil em Dia: a foto vai no Telegram e a publicação sai no Instagram depois da sua aprovação">';
        $html .= '<meta name="twitter:card" content="summary_large_image">';
        $html .= '<meta name="twitter:title" content="' . $e($docTitle) . '">';
        $html .= '<meta name="twitter:description" content="' . $e($desc) . '">';
        $html .= '<meta name="twitter:image" content="' . $e($image) . '">';
        if ($path === '/') {
            $site = [
                '@context' => 'https://schema.org',
                '@graph' => [
                    [
                        '@type' => 'Organization',
                        '@id' => self::absolute('/') . '#organizacao',
                        'name' => 'Perfil em Dia',
                        'url' => self::absolute('/'),
                        'logo' => self::absolute('assets/logo.png'),
                        'email' => 'ajuda@perfilemdia.com.br',
                    ],
                    [
                        '@type' => 'WebSite',
                        '@id' => self::absolute('/') . '#site',
                        'name' => 'Perfil em Dia',
                        'url' => self::absolute('/'),
                        'inLanguage' => 'pt-BR',
                        'description' => self::blurb('/'),
                        'publisher' => ['@id' => self::absolute('/') . '#organizacao'],
                    ],
                ],
            ];
            $html .= '<script type="application/ld+json">' . json_encode($site, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>';
        }
        $crumb = self::breadcrumb($path, $index);
        if ($crumb !== '') {
            $html .= '<script type="application/ld+json">' . $crumb . '</script>';
        }
        if ($jsonLd !== '') {
            $html .= '<script type="application/ld+json">' . $jsonLd . '</script>';
        }

        return $html;
    }

    private static function verification(): string
    {
        $token = Config::get('GOOGLE_SITE_VERIFICATION', '');
        if ($token === '') {
            $token = 'qQlFRHVjNXgwnk_KqrMSS2_XMIdHvbYXgVHCKCA9Fis';
        }
        if (!preg_match('/^[A-Za-z0-9_-]{8,200}$/', $token)) {
            return '';
        }

        return '<meta name="google-site-verification" content="' . self::e($token) . '">';
    }

    private static function measurementId(): string
    {
        if (!self::publicHost()) {
            return '';
        }
        $id = Config::get('GA_MEASUREMENT_ID', '');
        if ($id === '') {
            $id = 'G-B78FS7GJCY';
        }
        if (!preg_match('/^G-[A-Z0-9]+$/', $id)) {
            return '';
        }

        return $id;
    }

    private static function analytics(): string
    {
        $id = self::measurementId();
        if ($id === '') {
            return '';
        }
        $safe = self::e($id);

        return '<script async src="https://www.googletagmanager.com/gtag/js?id=' . $safe . '"></script>'
            . '<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag("js",new Date());gtag("config","' . $safe . '");</script>';
    }

    private static function telegramUser(string $key, string $fallback): string
    {
        $username = preg_replace('/[^A-Za-z0-9_]/', '', Config::get($key, $fallback)) ?? '';

        return $username !== '' ? $username : $fallback;
    }

    private static function telegramHelp(): string
    {
        $bot = self::e('https://t.me/' . self::telegramUser('TELEGRAM_BOT_USERNAME', 'PerfilEmDiaBot'));
        $contact = self::e('https://t.me/' . self::telegramUser('TELEGRAM_CONTACT_BOT_USERNAME', 'PerfilEmDiaContatoBot'));
        $icon = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M9.04 15.3 8.9 19.1c.4 0 .57-.17.79-.38l1.9-1.83 3.96 2.92c.73.4 1.25.19 1.45-.67l2.62-12.4c.23-1.08-.39-1.5-1.1-1.24L3.4 9.28c-1.05.41-1.03.99-.18 1.25l4.28 1.34 9.96-6.28c.47-.29.9-.13.55.18"/></svg>';

        return '<button class="tg-help" type="button" aria-haspopup="dialog" aria-controls="tg-escolha" aria-expanded="false" aria-label="Abrir o Telegram">'
            . $icon
            . '</button>'
            . '<div class="tg-dialog" id="tg-escolha" data-tg-dialog hidden>'
            . '<div class="tg-dialog-card" role="dialog" aria-modal="true" aria-labelledby="tg-escolha-titulo">'
            . '<h2 id="tg-escolha-titulo">Qual conversa abrir?</h2>'
            . '<a class="tg-choice" href="' . $bot . '" target="_blank" rel="noopener noreferrer"><strong>Bot</strong><span>Manda a foto, recebe a legenda e publica no Instagram.</span></a>'
            . '<a class="tg-choice" href="' . $contact . '" target="_blank" rel="noopener noreferrer"><strong>Contato</strong><span>Fala com a gente sobre plano, dúvida ou problema.</span></a>'
            . '<button class="tg-close" type="button" data-tg-close>Fechar</button>'
            . '</div></div>';
    }

    private static function cookieBar(): string
    {
        $id = self::measurementId();
        $ga = $id !== '' ? ' data-ga="' . self::e($id) . '"' : '';
        $privacy = self::e(self::url('privacidade') . '#cookies');

        return '<div class="cookie-bar" data-cookie hidden' . $ga . ' role="dialog" aria-label="Cookies">'
            . '<p>Medimos as visitas deste site com o Google Analytics, só se você aceitar. '
            . 'A sessão do pagamento e da conta é necessária e segue nos dois casos. '
            . '<a href="' . $privacy . '">Privacidade</a></p>'
            . '<div class="cookie-actions">'
            . '<button class="btn btn-ghost btn-sm" type="button" data-choice="recusado">Agora não</button>'
            . '<button class="btn btn-primary btn-sm" type="button" data-choice="aceito">Aceitar</button>'
            . '</div></div>';
    }

    private static function publicHost(): bool
    {
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $host = (string) preg_replace('/:\d+$/', '', $host);

        return $host === 'perfilemdia.com.br' || $host === 'www.perfilemdia.com.br';
    }

    private static function breadcrumb(string $path, bool $index): string
    {
        $name = match ($path) {
            '/planos' => 'Planos e preços',
            '/ajuda' => 'Dúvidas frequentes',
            '/manual' => 'Área do cliente',
            '/privacidade' => 'Privacidade',
            '/termos' => 'Termos de uso',
            '/exclusao-de-dados' => 'Exclusão de dados',
            default => '',
        };
        if (!$index || $name === '') {
            return '';
        }
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                [
                    '@type' => 'ListItem',
                    'position' => 1,
                    'name' => 'Início',
                    'item' => self::absolute('/'),
                ],
                [
                    '@type' => 'ListItem',
                    'position' => 2,
                    'name' => $name,
                    'item' => self::absolute($path),
                ],
            ],
        ];

        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function icons(): string
    {
        $e = [self::class, 'e'];

        return '<link rel="icon" href="' . $e(self::url('favicon.ico')) . '?v=2" sizes="32x32">'
            . '<link rel="icon" href="' . $e(self::url('assets/favicon-32.png')) . '?v=2" type="image/png" sizes="32x32">'
            . '<link rel="icon" href="' . $e(self::url('assets/favicon-192.png')) . '?v=2" type="image/png" sizes="192x192">'
            . '<link rel="apple-touch-icon" href="' . $e(self::url('assets/apple-touch-icon.png')) . '?v=2">'
            . '<meta name="theme-color" content="#1F6F47">';
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
