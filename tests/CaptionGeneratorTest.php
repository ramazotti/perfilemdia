<?php

declare(strict_types=1);

namespace PerfilEmDia\Tests;

use PerfilEmDia\Ai\CaptionException;
use PerfilEmDia\Ai\CaptionGenerator;
use PerfilEmDia\Ai\CaptionResult;
use PerfilEmDia\Config;
use PerfilEmDia\Support\HttpPoster;
use PHPUnit\Framework\TestCase;

final class CaptionGeneratorTest extends TestCase
{
    private string $jpegPath;

    protected function setUp(): void
    {
        Config::load();
        $this->jpegPath = sys_get_temp_dir() . '/perfilemdia-caption-' . uniqid('', true) . '.jpg';
        file_put_contents($this->jpegPath, 'fake-jpeg-bytes');
    }

    protected function tearDown(): void
    {
        if (is_file($this->jpegPath)) {
            unlink($this->jpegPath);
        }
    }

    public function testValidJsonBecomesCaptionResultWithFixedHashtags(): void
    {
        $http = $this->fakeHttp(function () {
            return $this->okResponse(json_encode([
                'legenda' => 'Gancho do serviço feito hoje.',
                'hashtags' => ['reforma', 'Pedreiro', 'sãoPaulo'],
                'alt_text' => 'Parede reformada',
            ], JSON_UNESCAPED_UNICODE));
        });

        $generator = new CaptionGenerator($http);
        $result = $generator->generate($this->profile('#pedreiro #sp'), 'reforma de parede', [$this->jpegPath]);

        $this->assertInstanceOf(CaptionResult::class, $result);
        $this->assertSame('Gancho do serviço feito hoje.', $result->legenda);
        $this->assertSame(['#reforma', '#pedreiro', '#saopaulo', '#sp'], $result->hashtags);
        $this->assertStringContainsString('Gancho do serviço feito hoje.', $result->caption);
        $this->assertStringContainsString('#reforma #pedreiro #saopaulo #sp', $result->caption);
        $this->assertSame('Parede reformada', $result->altText);
        $this->assertSame(11, $result->inputTokens);
        $this->assertSame(22, $result->outputTokens);
    }

    public function testJsonFenceStillParses(): void
    {
        $payload = "```json\n"
            . json_encode([
                'legenda' => 'Legenda cercada.',
                'hashtags' => ['obra'],
                'alt_text' => 'Alt',
            ], JSON_UNESCAPED_UNICODE)
            . "\n```";

        $http = $this->fakeHttp(fn () => $this->okResponse($payload));
        $result = (new CaptionGenerator($http))->generate($this->profile(null), 'obra', [$this->jpegPath]);

        $this->assertSame('Legenda cercada.', $result->legenda);
        $this->assertSame(['#obra'], $result->hashtags);
    }

    public function testJsonInsideProseStillParses(): void
    {
        $payload = "Segue a legenda:\n"
            . json_encode([
                'legenda' => 'Texto no meio da resposta.',
                'hashtags' => ['vaticano'],
                'alt_text' => 'Praca',
            ], JSON_UNESCAPED_UNICODE)
            . "\nEspero que sirva.";

        $http = $this->fakeHttp(fn () => $this->okResponse($payload));
        $result = (new CaptionGenerator($http))->generate($this->profile(null), 'vaticano', [$this->jpegPath]);

        $this->assertSame('Texto no meio da resposta.', $result->legenda);
    }

    public function testArrayContentStillParses(): void
    {
        $json = json_encode([
            'legenda' => 'Veio em partes.',
            'hashtags' => ['foto'],
            'alt_text' => 'alt',
        ], JSON_UNESCAPED_UNICODE);
        $http = $this->fakeHttp(function () use ($json) {
            $response = $this->okResponse('');
            $response['body']['choices'][0]['message']['content'] = [
                ['type' => 'text', 'text' => (string) $json],
            ];

            return $response;
        });

        $result = (new CaptionGenerator($http))->generate($this->profile(null), 'tema', [$this->jpegPath]);

        $this->assertSame('Veio em partes.', $result->legenda);
    }

    public function testStringHashtagsStillParse(): void
    {
        $http = $this->fakeHttp(fn () => $this->okResponse(json_encode([
            'legenda' => 'Hashtags em texto.',
            'hashtags' => '#Vaticano, Roma',
            'alt_text' => 'Praca',
        ], JSON_UNESCAPED_UNICODE)));

        $result = (new CaptionGenerator($http))->generate($this->profile(null), 'vaticano', [$this->jpegPath]);

        $this->assertSame('Hashtags em texto.', $result->legenda);
        $this->assertSame(['#vaticano', '#roma'], $result->hashtags);
    }

    public function testProfileContactIsPlacedAfterHashtags(): void
    {
        $http = $this->fakeHttp(fn () => $this->okResponse(json_encode([
            'legenda' => "Texto da legenda.\n\nAcesse: https://\nwww.exemplo.com para um exame que transforma a manha e o restante do dia com calma.",
            'hashtags' => ['fe'],
            'alt_text' => 'alt',
        ], JSON_UNESCAPED_UNICODE)));
        $profile = $this->profile(null);
        $profile['contact_cta'] = 'https://www.exemplo.com';

        $result = (new CaptionGenerator($http))->generate($profile, 'tema', [$this->jpegPath]);

        $contactAt = mb_strpos($result->caption, 'https://www.exemplo.com');
        $hashAt = mb_strpos($result->caption, '#fe');
        $this->assertNotFalse($contactAt);
        $this->assertNotFalse($hashAt);
        $this->assertGreaterThan($hashAt, $contactAt);
        $this->assertSame(1, substr_count($result->caption, 'https://www.exemplo.com'));
        $this->assertStringNotContainsString('transforma a manha', $result->caption);
    }

    public function testHashtagsAlreadyInLegendAreNotRepeated(): void
    {
        $http = $this->fakeHttp(fn () => $this->okResponse(json_encode([
            'legenda' => "Texto da legenda.\n\n#fe #oracao",
            'hashtags' => ['fe', 'oracao'],
            'alt_text' => 'alt',
        ], JSON_UNESCAPED_UNICODE)));

        $result = (new CaptionGenerator($http))->generate($this->profile(null), 'padre pio', [$this->jpegPath]);

        $this->assertSame(1, substr_count($result->caption, '#fe'));
        $this->assertSame(1, substr_count($result->caption, '#oracao'));
        $this->assertStringContainsString('Texto da legenda.', $result->caption);
    }

    public function testLiteralNewlineEscapesBecomeRealBreaks(): void
    {
        $http = $this->fakeHttp(fn () => $this->okResponse(json_encode([
            'legenda' => "Primeira linha.\\n\\nSegunda linha.",
            'hashtags' => ['vaticano'],
            'alt_text' => 'alt',
        ], JSON_UNESCAPED_UNICODE)));

        $result = (new CaptionGenerator($http))->generate($this->profile(null), 'vaticano', [$this->jpegPath]);

        $this->assertSame("Primeira linha.\n\nSegunda linha.", $result->legenda);
        $this->assertStringNotContainsString('\\n', $result->caption);
    }

    public function testConteudoInadequadoThrows(): void
    {
        $http = $this->fakeHttp(fn () => $this->okResponse(json_encode([
            'erro' => 'conteudo_inadequado',
        ])));

        try {
            (new CaptionGenerator($http))->generate($this->profile(null), 'tema', [$this->jpegPath]);
            $this->fail('Expected CaptionException');
        } catch (CaptionException $e) {
            $this->assertSame('conteudo_inadequado', $e->kind);
        }
    }

    public function testInvalidJsonThenValidJsonSucceeds(): void
    {
        $calls = 0;
        $http = $this->fakeHttp(function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                return $this->okResponse('isto nao e json');
            }

            return $this->okResponse(json_encode([
                'legenda' => 'Segunda tentativa ok.',
                'hashtags' => ['ok'],
                'alt_text' => 'alt',
            ]));
        });

        $result = (new CaptionGenerator($http))->generate($this->profile(null), 'tema', [$this->jpegPath]);

        $this->assertSame(2, $calls);
        $this->assertSame('Segunda tentativa ok.', $result->legenda);
        $this->assertStringContainsString('Responda somente com o JSON', $http->calls[1]['options']['json']['messages'][1]['content'][1]['text']);
    }

    public function testRequestBodyIncludesImageOnOpenRouter(): void
    {
        $http = $this->fakeHttp(fn () => $this->okResponse(json_encode([
            'legenda' => 'Com imagem.',
            'hashtags' => ['foto'],
            'alt_text' => 'alt',
        ])));

        (new CaptionGenerator($http))->generate($this->profile(null), 'tema', [$this->jpegPath]);

        $this->assertNotEmpty($http->calls);
        $options = $http->calls[0]['options'];
        $this->assertStringStartsWith('Bearer ', $options['headers']['Authorization']);
        $this->assertSame('Perfil em Dia', $options['headers']['X-Title']);
        $this->assertSame('POST', $http->calls[0]['method']);
        $this->assertSame('https://openrouter.ai/api/v1/chat/completions', $http->calls[0]['url']);
        $this->assertArrayNotHasKey('anthropic-version', $options['headers']);

        $content = $options['json']['messages'][1]['content'];
        $this->assertSame('image_url', $content[0]['type']);
        $this->assertSame('data:image/jpeg;base64,' . base64_encode('fake-jpeg-bytes'), $content[0]['image_url']['url']);
        $this->assertSame('text', $content[1]['type']);
        $this->assertSame('system', $options['json']['messages'][0]['role']);
    }

    /**
     * @return array{display_name:?string, profession:?string, city:?string, tone:?string, contact_cta:?string, about:?string, fixed_hashtags:?string}
     */
    private function profile(?string $fixedHashtags): array
    {
        return [
            'display_name' => 'Ana',
            'profession' => 'pedreira',
            'city' => 'São Paulo',
            'tone' => 'amigavel',
            'contact_cta' => 'WhatsApp 11 99999-0000',
            'about' => 'Reformas residenciais',
            'fixed_hashtags' => $fixedHashtags,
        ];
    }

    /**
     * @param callable(): array{status:int, body:array<string, mixed>|string} $handler
     * @return HttpPoster&object{calls: list<array{method:string, url:string, options:array<string, mixed>}>}
     */
    private function fakeHttp(callable $handler): HttpPoster
    {
        return new class ($handler) implements HttpPoster {
            /** @var list<array{method:string, url:string, options:array<string, mixed>}> */
            public array $calls = [];

            /** @param callable(): array{status:int, body:array<string, mixed>|string} $handler */
            public function __construct(private $handler)
            {
            }

            public function request(string $method, string $url, array $options = []): array
            {
                $this->calls[] = [
                    'method' => $method,
                    'url' => $url,
                    'options' => $options,
                ];

                return ($this->handler)();
            }
        };
    }

    /**
     * @return array{status:int, body:array<string, mixed>}
     */
    private function okResponse(string $text): array
    {
        return [
            'status' => 200,
            'body' => [
                'model' => 'openai/gpt-4o-mini',
                'choices' => [
                    ['message' => ['role' => 'assistant', 'content' => $text]],
                ],
                'usage' => [
                    'prompt_tokens' => 11,
                    'completion_tokens' => 22,
                ],
            ],
        ];
    }
}
