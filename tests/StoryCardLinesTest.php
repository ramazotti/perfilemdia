<?php

declare(strict_types=1);

namespace PerfilEmDia\Tests;

use PerfilEmDia\Image\StoryCard;
use PerfilEmDia\Image\StoryScript;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class StoryCardLinesTest extends TestCase
{
    public function test270CharactersFitInAtMostSevenLines(): void
    {
        $text = 'Quando você busca orientação jurídica com clareza, cada palavra do story precisa caber bem. Este texto de teste tem exatamente 270 caracteres, o máximo de um quadro. Assim dá para ver quebra de linha, faixa escura, balão e sombra no modo limpo. Abra no celular. Confira.';
        $this->assertSame(StoryScript::MAX_PART_CHARS, mb_strlen($text));

        $fit = new ReflectionMethod(StoryCard::class, 'fitBlock');
        $fit->setAccessible(true);
        /** @var array{lines:list<string>,size:int,widths:list<int>} $result */
        $result = $fit->invoke(null, 1080, 1920, $text, 'normal', static function (int $size, string $line): int {
            return (int) (mb_strlen($line) * $size * 0.45);
        });

        $this->assertLessThanOrEqual(7, count($result['lines']));
        $this->assertGreaterThanOrEqual(32, $result['size']);
    }
}
