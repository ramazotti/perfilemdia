<?php

declare(strict_types=1);

namespace PerfilEmDia\Tests;

use PerfilEmDia\Site\Layout;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class SeoHeadTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['GA_MEASUREMENT_ID'], $_ENV['GOOGLE_SITE_VERIFICATION'], $_ENV['APP_URL']);
        putenv('GA_MEASUREMENT_ID');
        putenv('GOOGLE_SITE_VERIFICATION');
    }

    public function testPublicHeadCarriesCanonicalSocialAndBreadcrumb(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['REQUEST_URI'] = '/planos';
        $_SERVER['HTTP_HOST'] = 'perfilemdia.com.br';
        $_SERVER['HTTPS'] = 'on';
        $_ENV['APP_URL'] = 'https://perfilemdia.com.br';
        $_ENV['GOOGLE_SITE_VERIFICATION'] = 'abcDEF_123-token';

        $html = $this->meta('Planos e pre\u{00e7}os', '', 200, '');

        $this->assertStringContainsString('<link rel="canonical" href="https://perfilemdia.com.br/planos">', $html);
        $this->assertStringContainsString('name="robots" content="index,follow,max-image-preview:large"', $html);
        $this->assertStringContainsString('name="google-site-verification" content="abcDEF_123-token"', $html);
        $this->assertStringContainsString('property="og:image:width" content="1200"', $html);
        $this->assertStringContainsString('name="twitter:card" content="summary_large_image"', $html);
        $this->assertStringContainsString('Planos e pre\u{00e7}os', $html);
        $this->assertStringContainsString('BreadcrumbList', $html);
    }

    public function testAccountAliasStaysOutOfTheIndex(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['REQUEST_URI'] = '/conta';
        $_SERVER['HTTP_HOST'] = 'perfilemdia.com.br';
        $_SERVER['HTTPS'] = 'on';
        $_ENV['APP_URL'] = 'https://perfilemdia.com.br';

        $html = $this->meta("\u{00c1}rea do cliente", '', 200, '');

        $this->assertStringContainsString('content="noindex,nofollow"', $html);
        $this->assertStringContainsString('href="https://perfilemdia.com.br/manual"', $html);
    }

    public function testAnalyticsSnippetAcceptsOnlyMeasurementIdsOnThePublicHost(): void
    {
        $_SERVER['HTTP_HOST'] = 'perfilemdia.com.br';
        $_ENV['GA_MEASUREMENT_ID'] = 'G-ABC123';
        $this->assertStringContainsString('gtag/js?id=G-ABC123', $this->analytics());
        $this->assertStringContainsString('gtag("config","G-ABC123")', $this->analytics());

        $_ENV['GA_MEASUREMENT_ID'] = 'G-ABC"<script>';
        $this->assertSame('', $this->analytics());

        $_SERVER['HTTP_HOST'] = 'localhost';
        $_ENV['GA_MEASUREMENT_ID'] = 'G-ABC123';
        $this->assertSame('', $this->analytics());
    }

    private function meta(string $title, string $description, int $status, string $jsonLd): string
    {
        $method = new ReflectionMethod(Layout::class, 'meta');
        $method->setAccessible(true);

        return (string) $method->invoke(null, $title, $description, $status, $jsonLd);
    }

    private function analytics(): string
    {
        $method = new ReflectionMethod(Layout::class, 'analytics');
        $method->setAccessible(true);

        return (string) $method->invoke(null);
    }
}
