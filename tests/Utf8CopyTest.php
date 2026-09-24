<?php

declare(strict_types=1);

namespace PerfilEmDia\Tests;

use PerfilEmDia\Telegram\Keyboards;
use PHPUnit\Framework\TestCase;

final class Utf8CopyTest extends TestCase
{
    public function testKeyboardsSurviveJsonForTelegram(): void
    {
        $sets = [
            Keyboards::approval(7, true),
            Keyboards::tone(),
            Keyboards::yesNoPending(),
            Keyboards::instagramConnect("https://exemplo"),
            Keyboards::perfilFields(),
            Keyboards::deleteConfirm(),
        ];
        foreach ($sets as $rows) {
            $json = json_encode(["inline_keyboard" => $rows], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $this->assertNotSame("", $json);
        }
        $this->assertSame("Outra vers\u{00e3}o", Keyboards::approval(7, true)[1][1]["text"]);
        $this->assertSame("Descontra\u{00ed}do", Keyboards::tone()[0][1]["text"]);
        $this->assertSame("T\u{00e9}cnico", Keyboards::tone()[1][0]["text"]);
        $this->assertSame("Sim, come\u{00e7}ar novo", Keyboards::yesNoPending()[0][0]["text"]);
        $this->assertSame("N\u{00e3}o, voltar ao anterior", Keyboards::yesNoPending()[1][0]["text"]);
        $this->assertSame("J\u{00e1} \u{00e9} profissional, conectar", Keyboards::instagramConnect("https://exemplo")[0][0]["text"]);
    }

    public function testAdminDeleteConfirmIsUtf8(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__) . "/src/Site/AdminSite.php");
        $this->assertTrue(mb_check_encoding($src, "UTF-8"));
        $needle = "Isso n\u{00e3}o volta atr\u{00e1}s.";
        $this->assertNotFalse(strpos($src, $needle));
        $json = json_encode($needle, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $html = htmlspecialchars($json, ENT_QUOTES);
        $this->assertStringContainsString("n\u{00e3}o", $html);
    }
}