<?php

declare(strict_types=1);

namespace PerfilEmDia\Tests;

use PerfilEmDia\Image\IdeaImage;
use PerfilEmDia\Image\IdeaPieces;
use PerfilEmDia\Messages;
use PHPUnit\Framework\TestCase;

final class FormatChoiceTest extends TestCase
{
    public function testChosenFormatWinsOverWordsInTheRequest(): void
    {
        $quote = IdeaPieces::applyCue('3 passos para uma boa confissão', IdeaPieces::cueFromKey('quote', '4:5'));
        $this->assertSame('quote', IdeaPieces::kind($quote));
        $this->assertCount(1, IdeaPieces::pieces($quote, '4:5'));

        $photo = IdeaPieces::applyCue('passo a passo da missa em 5 slides', IdeaPieces::cueFromKey('photo', '4:5'));
        $this->assertFalse(IdeaImage::isDesigned($photo));
        $this->assertStringContainsString('No text, letters', IdeaImage::promptFor($photo, false, '', '4:5'));
        $this->assertStringNotContainsString('Foto simples', IdeaImage::promptFor($photo, false, '', '4:5'));

        $steps = IdeaPieces::applyCue("Infogr\u{00e1}fico com 3 slides: o jejum", IdeaPieces::cueFromKey('steps3', '4:5'));
        $this->assertStringStartsWith('Passo a passo com 3:', $steps);
        $this->assertCount(3, IdeaPieces::pieces($steps, '4:5'));
    }

    public function testCueIsNotRepeated(): void
    {
        $cue = IdeaPieces::cueFromKey('infographic', '9:16');
        $once = IdeaPieces::applyCue('a oração', $cue);
        $this->assertSame($once, IdeaPieces::applyCue($once, $cue));
        $this->assertCount(3, IdeaPieces::pieces($once, '9:16'));
    }

    public function testQuoteWithoutExactWordsAsksForARealQuote(): void
    {
        $prompt = IdeaImage::promptFor("Cita\u{00e7}\u{00e3}o: perdão e misericórdia", false, '', '4:5');
        $this->assertStringContainsString('real, well-known quote', $prompt);
        $this->assertStringContainsString('Never invent an author', $prompt);
        $this->assertStringNotContainsString('write new short ones', $prompt);
    }

    public function testFeedDesignKeepsTextAwayFromTheTrimmedEdges(): void
    {
        $prompt = IdeaImage::promptFor('Checklist: o que levar', false, '', '4:5');
        $this->assertStringContainsString('trimmed to 4:5', $prompt);
    }

    public function testEveryMessageSurvivesJsonForTelegram(): void
    {
        $samples = ['int' => 3, 'string' => 'x', 'bool' => false, '?string' => null, 'float' => 1.0, 'array' => []];
        $class = new \ReflectionClass(Messages::class);
        $checked = 0;
        foreach ($class->getMethods(\ReflectionMethod::IS_STATIC | \ReflectionMethod::IS_PUBLIC) as $method) {
            $args = [];
            foreach ($method->getParameters() as $param) {
                if ($param->isOptional()) {
                    break;
                }
                $type = (string) $param->getType();
                if (!array_key_exists($type, $samples)) {
                    continue 2;
                }
                $args[] = $samples[$type];
            }
            $text = $method->invokeArgs(null, $args);
            if (!is_string($text)) {
                continue;
            }
            $this->assertTrue(mb_check_encoding($text, 'UTF-8'), $method->getName());
            json_encode(['text' => $text], JSON_THROW_ON_ERROR);
            $checked++;
        }
        $this->assertGreaterThan(100, $checked);
    }

    public function testSourcesAreUtf8(): void
    {
        $root = dirname(__DIR__);
        $files = array_merge(
            glob($root . '/src/*.php') ?: [],
            glob($root . '/src/*/*.php') ?: [],
            glob($root . '/public/*.php') ?: [],
            glob($root . '/public/assets/*.js') ?: [],
            glob($root . '/tests/*.php') ?: [],
        );
        $this->assertNotEmpty($files);
        foreach ($files as $file) {
            $this->assertTrue(mb_check_encoding((string) file_get_contents($file), 'UTF-8'), $file);
        }
    }
}
