<?php

declare(strict_types=1);

namespace PerfilEmDia\Tests;

use DateTimeImmutable;
use DateTimeZone;
use PerfilEmDia\Ai\SurpriseBriefGenerator;
use PerfilEmDia\Support\HttpPoster;
use PHPUnit\Framework\TestCase;

final class SurpriseBriefGeneratorTest extends TestCase
{
    public function testDecodeJsonIdeaAndPhrase(): void
    {
        $http = new class implements HttpPoster {
            public function request(string $method, string $url, array $options = []): array
            {
                return [
                    'status' => 200,
                    'body' => [
                        'choices' => [[
                            'message' => [
                                'content' => '{"idea":"Mostre um detalhe do seu trabalho com luz natural e fundo limpo.","phrase":"Feito com cuidado."}',
                            ],
                        ]],
                    ],
                ];
            }
        };

        $_ENV['OPENROUTER_API_KEY'] = 'unit-test-key';
        putenv('OPENROUTER_API_KEY=unit-test-key');

        $gen = new SurpriseBriefGenerator($http);
        $result = $gen->generate(
            ['about' => 'Confeitaria artesanal', 'tone' => 'acolhedor'],
            new DateTimeImmutable('2026-06-10', new DateTimeZone('America/Sao_Paulo')),
            'feed',
        );

        $this->assertNotNull($result);
        $this->assertStringContainsString('detalhe', mb_strtolower($result['idea']));
        $this->assertSame('Feito com cuidado.', $result['phrase']);
    }
}
