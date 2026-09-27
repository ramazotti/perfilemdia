<?php

declare(strict_types=1);

namespace PerfilEmDia\Tests;

use PerfilEmDia\Image\ImageNormalizer;
use PerfilEmDia\Image\ImageUnsupportedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImageNormalizerTest extends TestCase
{
    private const FEED_RATIO = 16 / 9;
    private const FEED_WIDTH = 1080;
    private const FEED_HEIGHT = 608;

    private string $tempRoot;

    private ImageNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempRoot = sys_get_temp_dir() . '/perfilemdia-image-tests-' . bin2hex(random_bytes(8));
        mkdir($this->tempRoot, 0755, true);
        $this->normalizer = new ImageNormalizer();
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tempRoot);
        parent::tearDown();
    }

    public function testPortraitThreeByFourCropsToSixteenByNine(): void
    {
        $source = $this->createJpeg(750, 1000);
        $publicDir = $this->tempRoot . '/public';

        $results = $this->normalizer->normalize([$source], $publicDir);

        $this->assertCount(1, $results);
        $this->assertFeedOutput($results[0]->absolutePath, $results[0]->width, $results[0]->height, $results[0]->ratio);
    }

    public function testTallNineBySixteenCropsToSixteenByNine(): void
    {
        $source = $this->createJpeg(900, 1600);
        $publicDir = $this->tempRoot . '/public';

        $results = $this->normalizer->normalize([$source], $publicDir);

        $this->assertCount(1, $results);
        $this->assertFeedOutput($results[0]->absolutePath, $results[0]->width, $results[0]->height, $results[0]->ratio);
    }

    public function testSquareOneByOneCropsToSixteenByNine(): void
    {
        $source = $this->createJpeg(1000, 1000);
        $publicDir = $this->tempRoot . '/public';

        $results = $this->normalizer->normalize([$source], $publicDir);

        $this->assertCount(1, $results);
        $this->assertFeedOutput($results[0]->absolutePath, $results[0]->width, $results[0]->height, $results[0]->ratio);
    }

    public function testSixteenByNineStaysSixteenByNine(): void
    {
        $source = $this->createJpeg(1600, 900);
        $publicDir = $this->tempRoot . '/public';

        $results = $this->normalizer->normalize([$source], $publicDir);

        $this->assertCount(1, $results);
        $this->assertFeedOutput($results[0]->absolutePath, $results[0]->width, $results[0]->height, $results[0]->ratio);
    }

    public function testUltraWideThreeByOneCropsToSixteenByNine(): void
    {
        $source = $this->createJpeg(3000, 1000);
        $publicDir = $this->tempRoot . '/public';

        $results = $this->normalizer->normalize([$source], $publicDir);

        $this->assertCount(1, $results);
        $this->assertFeedOutput($results[0]->absolutePath, $results[0]->width, $results[0]->height, $results[0]->ratio);
    }

    public function testCarouselForcesSixteenByNineOnEverySlide(): void
    {
        $first = $this->createJpeg(750, 1000);
        $second = $this->createJpeg(1600, 900);
        $publicDir = $this->tempRoot . '/public';

        $results = $this->normalizer->normalize([$first, $second], $publicDir);

        $this->assertCount(2, $results);
        $this->assertFeedOutput($results[0]->absolutePath, $results[0]->width, $results[0]->height, $results[0]->ratio);
        $this->assertFeedOutput($results[1]->absolutePath, $results[1]->width, $results[1]->height, $results[1]->ratio);
        $this->assertEqualsWithDelta($results[0]->ratio, $results[1]->ratio, 0.02);
    }

    public function testCreatesPublicDirectoryWhenMissing(): void
    {
        $source = $this->createJpeg(800, 800);
        $publicDir = $this->tempRoot . '/missing/nested/public';

        $results = $this->normalizer->normalize([$source], $publicDir);

        $this->assertDirectoryExists($publicDir);
        $this->assertFileExists($results[0]->absolutePath);
    }

    public function testRejectsUnsupportedFormat(): void
    {
        $path = $this->tempRoot . '/note.txt';
        file_put_contents($path, 'not an image');

        $this->expectException(ImageUnsupportedException::class);
        $this->normalizer->normalize([$path], $this->tempRoot . '/public');
    }

    public function testPublicNameIsFortyHexCharacters(): void
    {
        $source = $this->createJpeg(640, 640);
        $results = $this->normalizer->normalize([$source], $this->tempRoot . '/public');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $results[0]->publicName);
        $this->assertSame(
            $this->tempRoot . '/public/' . $results[0]->publicName . '.jpg',
            $results[0]->absolutePath,
        );
    }

    #[DataProvider('aspectRatioProvider')]
    public function testAllRequiredAspectsBecomeSixteenByNine(int $width, int $height): void
    {
        $source = $this->createJpeg($width, $height);
        $results = $this->normalizer->normalize([$source], $this->tempRoot . '/public');

        $this->assertCount(1, $results);
        $this->assertFeedOutput($results[0]->absolutePath, $results[0]->width, $results[0]->height, $results[0]->ratio);
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function aspectRatioProvider(): array
    {
        return [
            '3:4' => [750, 1000],
            '9:16' => [900, 1600],
            '1:1' => [1000, 1000],
            '16:9' => [1600, 900],
            '3:1' => [3000, 1000],
        ];
    }

    private function createJpeg(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image);

        $background = imagecolorallocate($image, 40, 120, 200);
        $accent = imagecolorallocate($image, 220, 80, 60);
        self::assertNotFalse($background);
        self::assertNotFalse($accent);

        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $background);
        imagefilledrectangle(
            $image,
            (int) ($width * 0.2),
            (int) ($height * 0.2),
            (int) ($width * 0.8),
            (int) ($height * 0.8),
            $accent,
        );

        $path = $this->tempRoot . '/src-' . $width . 'x' . $height . '-' . bin2hex(random_bytes(4)) . '.jpg';
        $written = imagejpeg($image, $path, 90);
        imagedestroy($image);
        self::assertTrue($written);
        self::assertFileExists($path);

        return $path;
    }

    private function assertFeedOutput(string $path, int $width, int $height, float $ratio): void
    {
        $this->assertFileExists($path);
        $info = getimagesize($path);
        $this->assertNotFalse($info);
        $this->assertSame('image/jpeg', $info['mime']);
        $this->assertSame($width, $info[0]);
        $this->assertSame($height, $info[1]);
        $this->assertSame(self::FEED_WIDTH, $width);
        $this->assertSame(self::FEED_HEIGHT, $height);
        $this->assertEqualsWithDelta(self::FEED_RATIO, $ratio, 0.02);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $full = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($full)) {
                $this->removeTree($full);
            } else {
                unlink($full);
            }
        }

        rmdir($path);
    }
}
