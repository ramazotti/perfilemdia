<?php

declare(strict_types=1);

use PerfilEmDia\Image\ImageNormalizer;
use PerfilEmDia\Image\PhraseColor;
use PerfilEmDia\Image\PhrasePlace;
use PerfilEmDia\Image\PhraseSize;
use PerfilEmDia\Image\PhraseStyle;
use PerfilEmDia\Image\StoryCard;
use PerfilEmDia\Image\StoryScript;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Gera JPEGs de story em storage/examples/ para revisar tipografia na foto.
 *
 * Uso: php bin/render_story_examples.php
 */

function storySampleText(): string
{
    $text = 'Quando você busca orientação jurídica com clareza, cada palavra do story precisa caber bem. Este texto de teste tem exatamente 270 caracteres, o máximo de um quadro. Assim dá para ver quebra de linha, faixa escura, balão e sombra no modo limpo. Abra no celular. Confira.';

    $len = mb_strlen($text);
    if ($len !== StoryScript::MAX_PART_CHARS) {
        throw new RuntimeException("Texto de exemplo deve ter 270 caracteres; tem {$len}.");
    }

    return $text;
}

function makeSourcePhoto(string $path): void
{
    $w = 1200;
    $h = 1600;
    $img = imagecreatetruecolor($w, $h);
    if ($img === false) {
        throw new RuntimeException('GD não disponível.');
    }
    for ($y = 0; $y < $h; $y++) {
        $t = $y / max(1, $h - 1);
        $r = (int) (45 + (110 - 45) * $t);
        $g = (int) (62 + (128 - 62) * $t);
        $b = (int) (88 + (145 - 88) * $t);
        $line = imagecolorallocate($img, $r, $g, $b);
        if ($line !== false) {
            imageline($img, 0, $y, $w, $y, $line);
        }
    }
    $wash = imagecolorallocatealpha($img, 240, 230, 210, 110);
    if ($wash !== false) {
        imagefilledellipse($img, (int) ($w * 0.72), (int) ($h * 0.28), (int) ($w * 0.55), (int) ($h * 0.35), $wash);
    }
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Não foi possível criar ' . $dir);
    }
    if (!imagejpeg($img, $path, 92)) {
        imagedestroy($img);
        throw new RuntimeException('Falha ao gravar foto base.');
    }
    imagedestroy($img);
}

$root = dirname(__DIR__);
$stamp = date('Y-m-d_His');
$outDir = $root . '/storage/examples/story-270-' . $stamp;
$workDir = $outDir . '/_work';
if (!is_dir($outDir) && !mkdir($outDir, 0775, true) && !is_dir($outDir)) {
    fwrite(STDERR, "Não foi possível criar {$outDir}\n");
    exit(1);
}

$text = storySampleText();
$parts = StoryScript::parts($text);
file_put_contents($outDir . '/texto.txt', $text . "\n\n# mb_strlen=" . mb_strlen($text) . "\n# partes=" . count($parts) . "\n");

$source = $outDir . '/_source.jpg';
makeSourcePhoto($source);

$normalizer = new ImageNormalizer();
$normalized = $normalizer->normalize([$source], $workDir, 'story');
$base = $normalized[0]->absolutePath;
copy($base, $outDir . '/00_sem_texto.jpg');

$styles = [PhraseStyle::LIMPA, PhraseStyle::CAIXA, PhraseStyle::BALAO];
$places = [PhrasePlace::TOPO, PhrasePlace::MEIO, PhrasePlace::RODAPE];
$colors = [PhraseColor::DOURADO, PhraseColor::BRANCO, PhraseColor::PRETO];
$count = 0;

foreach ($styles as $style) {
    foreach ($places as $place) {
        foreach ($colors as $color) {
            $name = sprintf('%s_%s_%s.jpg', $style, $place, $color);
            $dest = $outDir . '/' . $name;
            if (!copy($base, $dest)) {
                continue;
            }
            StoryCard::draw($dest, $text, $color, $place, PhraseSize::NORMAL, PhraseStyle::storyChoice($style));
            ++$count;
        }
    }
}

$meioCaixaBranco = $outDir . '/destaque_meio_caixa_branco.jpg';
copy($base, $meioCaixaBranco);
StoryCard::draw($meioCaixaBranco, $text, PhraseColor::BRANCO, PhrasePlace::MEIO, PhraseSize::NORMAL, PhraseStyle::CAIXA);

$shortText = 'Sala calma, luz suave. Texto curto para ver a fonte maior com o marcador linha a linha.';
$compareDir = $outDir . '/comparacao_curto_vs_longo';
mkdir($compareDir, 0775, true);
file_put_contents($compareDir . '/texto_curto.txt', $shortText . "\n# mb_strlen=" . mb_strlen($shortText) . "\n");
file_put_contents($compareDir . '/texto_longo.txt', $text . "\n# mb_strlen=" . mb_strlen($text) . "\n");
foreach ([
    ['limpa', PhraseStyle::LIMPA],
    ['caixa', PhraseStyle::CAIXA],
    ['balao', PhraseStyle::BALAO],
] as [$label, $style]) {
    foreach (['curto' => $shortText, 'longo' => $text] as $tag => $body) {
        $dest = $compareDir . "/{$label}_meio_branco_{$tag}.jpg";
        copy($base, $dest);
        StoryCard::draw($dest, $body, PhraseColor::BRANCO, PhrasePlace::MEIO, PhraseSize::NORMAL, PhraseStyle::storyChoice($style));
    }
}

echo "Pronto: {$outDir}\n";
echo "Texto: " . mb_strlen($text) . " caracteres, " . count($parts) . " quadro(s) no fluxo real.\n";
echo "Arquivos com texto: {$count} combinações + destaque + comparacao_curto_vs_longo + sem texto.\n";
echo "Abra no Finder: open \"{$outDir}\"\n";
