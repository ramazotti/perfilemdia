<?php

declare(strict_types=1);

namespace PerfilEmDia\Image;

use GdImage;
use Imagick;
use ImagickException;
use ImagickPixel;
use RuntimeException;

final class ImageNormalizer implements ImageNormalizerInterface
{
    private const MIN_RATIO = 0.8;
    private const MAX_RATIO = 1.91;
    private const PORTRAIT_CROP_RATIO = 4 / 5;
    private const LANDSCAPE_CROP_RATIO = 1.91;
    private const TARGET_WIDTH = 1080;
    private const MIN_UPSCALE_WIDTH = 320;
    private const MAX_FILE_BYTES = 8 * 1024 * 1024;
    private const JPEG_QUALITY_START = 90;
    private const JPEG_QUALITY_STEP = 5;
    private const JPEG_QUALITY_FLOOR = 40;

    public function normalize(array $sourcePaths, string $publicDirectory): array
    {
        $this->ensureDirectory($publicDirectory);

        $results = [];
        $carouselRatio = null;

        foreach ($sourcePaths as $sourcePath) {
            [$normalized, $usedCropRatio] = $this->normalizeOne($sourcePath, $publicDirectory, $carouselRatio);
            $carouselRatio ??= $usedCropRatio;
            $results[] = $normalized;
        }

        return $results;
    }

    /**
     * @return array{0: NormalizedImage, 1: float}
     */
    private function normalizeOne(
        string $sourcePath,
        string $publicDirectory,
        ?float $carouselRatio,
    ): array {
        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            throw new ImageUnsupportedException(sprintf('Cannot read image: %s', $sourcePath));
        }

        if ($this->preferImagick()) {
            return $this->normalizeWithImagick($sourcePath, $publicDirectory, $carouselRatio);
        }

        return $this->normalizeWithGd($sourcePath, $publicDirectory, $carouselRatio);
    }

    private function preferImagick(): bool
    {
        return extension_loaded('imagick') && class_exists(Imagick::class);
    }

    private function ensureDirectory(string $publicDirectory): void
    {
        if (is_dir($publicDirectory)) {
            return;
        }

        if (!mkdir($publicDirectory, 0755, true) && !is_dir($publicDirectory)) {
            throw new RuntimeException(sprintf('Cannot create public directory: %s', $publicDirectory));
        }
    }

    private function resolveCropRatio(float $ratio, ?float $carouselRatio): float
    {
        if ($carouselRatio !== null) {
            return $carouselRatio;
        }

        if ($ratio < self::MIN_RATIO) {
            return self::PORTRAIT_CROP_RATIO;
        }

        if ($ratio > self::MAX_RATIO) {
            return self::LANDSCAPE_CROP_RATIO;
        }

        return $ratio;
    }

    private function resolveOutputWidth(int $width): int
    {
        if ($width >= self::TARGET_WIDTH) {
            return self::TARGET_WIDTH;
        }

        if ($width < self::MIN_UPSCALE_WIDTH) {
            return self::MIN_UPSCALE_WIDTH;
        }

        return $width;
    }

    /**
     * @return array{width: int, height: int, x: int, y: int}
     */
    private function computeCropBox(int $width, int $height, float $targetRatio): array
    {
        $currentRatio = $width / $height;

        if (abs($currentRatio - $targetRatio) < 0.00001) {
            return ['width' => $width, 'height' => $height, 'x' => 0, 'y' => 0];
        }

        if ($currentRatio > $targetRatio) {
            // Floor keeps width/height from drifting above the target (important for 1.91).
            $cropWidth = max(1, (int) floor($height * $targetRatio));
            $cropHeight = $height;
            $x = (int) floor(($width - $cropWidth) / 2);
            $y = 0;
        } else {
            // Floor keeps width/height from drifting below the target (important for 0.8).
            $cropWidth = $width;
            $cropHeight = max(1, (int) floor($width / $targetRatio));
            $x = 0;
            $y = (int) floor(($height - $cropHeight) / 2);
        }

        return ['width' => $cropWidth, 'height' => $cropHeight, 'x' => $x, 'y' => $y];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function computeOutputSize(int $width, int $height): array
    {
        $outputWidth = $this->resolveOutputWidth($width);
        $outputHeight = max(1, (int) round($height * ($outputWidth / $width)));

        if ($outputWidth / $outputHeight < self::MIN_RATIO) {
            $outputHeight = max(1, (int) floor($outputWidth / self::MIN_RATIO));
        }

        if ($outputWidth / $outputHeight > self::MAX_RATIO) {
            $outputHeight = max(1, (int) ceil($outputWidth / self::MAX_RATIO));
        }

        return [$outputWidth, $outputHeight];
    }

    private function makePublicName(): string
    {
        return bin2hex(random_bytes(20));
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function destinationPaths(string $publicDirectory): array
    {
        $publicName = $this->makePublicName();
        $absolutePath = rtrim($publicDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $publicName . '.jpg';

        return [$publicName, $absolutePath];
    }

    /**
     * @return array{0: NormalizedImage, 1: float}
     */
    private function normalizeWithImagick(
        string $sourcePath,
        string $publicDirectory,
        ?float $carouselRatio,
    ): array {
        try {
            $image = new Imagick($sourcePath);
        } catch (ImagickException $exception) {
            throw new ImageUnsupportedException(
                sprintf('Unsupported or unreadable image: %s', $sourcePath),
                0,
                $exception,
            );
        }

        try {
            $format = strtoupper($image->getImageFormat());
            if (!in_array($format, ['JPEG', 'JPG', 'PNG', 'WEBP', 'HEIC', 'HEIF'], true)) {
                throw new ImageUnsupportedException(sprintf('Unsupported image format: %s', $format));
            }

            if (method_exists($image, 'autoOrient')) {
                $image->autoOrient();
            } else {
                $this->orientImagickFromExif($image);
            }

            $image->stripImage();

            if (defined('Imagick::COLORSPACE_SRGB')) {
                try {
                    $image->transformImageColorspace(Imagick::COLORSPACE_SRGB);
                } catch (ImagickException) {
                    // Color space conversion is best-effort when the library allows it.
                }
            }

            $width = $image->getImageWidth();
            $height = $image->getImageHeight();
            if ($width < 1 || $height < 1) {
                throw new ImageUnsupportedException('Image has invalid dimensions');
            }

            $cropRatio = $this->resolveCropRatio($width / $height, $carouselRatio);
            $this->cropImagickToRatio($image, $cropRatio);

            $width = $image->getImageWidth();
            $height = $image->getImageHeight();
            [$outputWidth, $outputHeight] = $this->computeOutputSize($width, $height);
            if ($outputWidth !== $width || $outputHeight !== $height) {
                $image->resizeImage($outputWidth, $outputHeight, Imagick::FILTER_LANCZOS, 1.0);
            }

            $image->setImageFormat('jpeg');
            $image->setImageCompression(Imagick::COMPRESSION_JPEG);
            try {
                $image->setImageBackgroundColor(new ImagickPixel('white'));
                if (defined('Imagick::ALPHACHANNEL_REMOVE')) {
                    $image->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
                }
                if (defined('Imagick::LAYERMETHOD_FLATTEN')) {
                    $flattened = $image->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
                    $image->clear();
                    $image->destroy();
                    $image = $flattened;
                }
            } catch (ImagickException) {
                // Opaque JPEGs may not need alpha flattening.
            }

            [$publicName, $absolutePath] = $this->destinationPaths($publicDirectory);
            $this->writeImagickJpeg($image, $absolutePath);

            $finalWidth = $image->getImageWidth();
            $finalHeight = $image->getImageHeight();

            return [
                new NormalizedImage(
                    $publicName,
                    $absolutePath,
                    $finalWidth,
                    $finalHeight,
                    $finalWidth / $finalHeight,
                ),
                $cropRatio,
            ];
        } finally {
            $image->clear();
            $image->destroy();
        }
    }

    private function orientImagickFromExif(Imagick $image): void
    {
        $orientation = $image->getImageOrientation();

        switch ($orientation) {
            case Imagick::ORIENTATION_TOPRIGHT:
                $image->flopImage();
                break;
            case Imagick::ORIENTATION_BOTTOMRIGHT:
                $image->rotateImage(new ImagickPixel('none'), 180);
                break;
            case Imagick::ORIENTATION_BOTTOMLEFT:
                $image->flipImage();
                break;
            case Imagick::ORIENTATION_LEFTTOP:
                $image->flopImage();
                $image->rotateImage(new ImagickPixel('none'), -90);
                break;
            case Imagick::ORIENTATION_RIGHTTOP:
                $image->rotateImage(new ImagickPixel('none'), 90);
                break;
            case Imagick::ORIENTATION_RIGHTBOTTOM:
                $image->flopImage();
                $image->rotateImage(new ImagickPixel('none'), 90);
                break;
            case Imagick::ORIENTATION_LEFTBOTTOM:
                $image->rotateImage(new ImagickPixel('none'), -90);
                break;
        }

        $image->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
    }

    private function cropImagickToRatio(Imagick $image, float $targetRatio): void
    {
        $box = $this->computeCropBox($image->getImageWidth(), $image->getImageHeight(), $targetRatio);

        if (
            $box['width'] === $image->getImageWidth()
            && $box['height'] === $image->getImageHeight()
        ) {
            return;
        }

        $image->cropImage($box['width'], $box['height'], $box['x'], $box['y']);
        $image->setImagePage(0, 0, 0, 0);
    }

    private function writeImagickJpeg(Imagick $image, string $absolutePath): void
    {
        $quality = self::JPEG_QUALITY_START;

        while (true) {
            $image->setImageCompressionQuality($quality);
            $blob = $image->getImageBlob();
            if (
                strlen($blob) <= self::MAX_FILE_BYTES
                || $quality <= self::JPEG_QUALITY_FLOOR
            ) {
                if (file_put_contents($absolutePath, $blob) === false) {
                    throw new RuntimeException(sprintf('Cannot write image: %s', $absolutePath));
                }

                return;
            }

            $quality -= self::JPEG_QUALITY_STEP;
        }
    }

    /**
     * @return array{0: NormalizedImage, 1: float}
     */
    private function normalizeWithGd(
        string $sourcePath,
        string $publicDirectory,
        ?float $carouselRatio,
    ): array {
        $image = $this->loadGdImage($sourcePath);
        $image = $this->applyGdExifOrientation($image, $sourcePath);

        $width = imagesx($image);
        $height = imagesy($image);
        if ($width < 1 || $height < 1) {
            imagedestroy($image);
            throw new ImageUnsupportedException('Image has invalid dimensions');
        }

        $cropRatio = $this->resolveCropRatio($width / $height, $carouselRatio);
        $image = $this->cropGdToRatio($image, $cropRatio);
        $image = $this->resizeGd($image);

        [$publicName, $absolutePath] = $this->destinationPaths($publicDirectory);
        $this->writeGdJpeg($image, $absolutePath);

        $finalWidth = imagesx($image);
        $finalHeight = imagesy($image);
        imagedestroy($image);

        return [
            new NormalizedImage(
                $publicName,
                $absolutePath,
                $finalWidth,
                $finalHeight,
                $finalWidth / $finalHeight,
            ),
            $cropRatio,
        ];
    }

    private function loadGdImage(string $sourcePath): GdImage
    {
        $mime = $this->detectMime($sourcePath);

        if (in_array($mime, ['image/heic', 'image/heif'], true)) {
            throw new ImageUnsupportedException('HEIC/HEIF requires Imagick');
        }

        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($sourcePath),
            'image/png' => @imagecreatefrompng($sourcePath),
            'image/webp' => function_exists('imagecreatefromwebp')
                ? @imagecreatefromwebp($sourcePath)
                : false,
            default => false,
        };

        if ($image === false) {
            throw new ImageUnsupportedException(sprintf('Unsupported or unreadable image: %s', $sourcePath));
        }

        if (!imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        // Re-encoding strips EXIF/GPS and other metadata. GD has no reliable sRGB transform.
        imagealphablending($image, true);
        imagesavealpha($image, false);

        return $image;
    }

    private function detectMime(string $sourcePath): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = finfo_file($finfo, $sourcePath);
                finfo_close($finfo);
                if (is_string($mime) && $mime !== '') {
                    return strtolower($mime);
                }
            }
        }

        $info = @getimagesize($sourcePath);
        if (is_array($info) && isset($info['mime']) && is_string($info['mime'])) {
            return strtolower($info['mime']);
        }

        $extension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));

        return match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'heic', 'heif' => 'image/heic',
            default => 'application/octet-stream',
        };
    }

    private function applyGdExifOrientation(GdImage $image, string $sourcePath): GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($sourcePath);
        if ($exif === false || !isset($exif['Orientation'])) {
            return $image;
        }

        $orientation = (int) $exif['Orientation'];

        return match ($orientation) {
            2 => $this->gdFlip($image, IMG_FLIP_HORIZONTAL),
            3 => $this->gdRotate($image, 180),
            4 => $this->gdFlip($image, IMG_FLIP_VERTICAL),
            5 => $this->gdRotate($this->gdFlip($image, IMG_FLIP_HORIZONTAL), 90),
            6 => $this->gdRotate($image, -90),
            7 => $this->gdRotate($this->gdFlip($image, IMG_FLIP_HORIZONTAL), -90),
            8 => $this->gdRotate($image, 90),
            default => $image,
        };
    }

    private function gdFlip(GdImage $image, int $mode): GdImage
    {
        if (!imageflip($image, $mode)) {
            imagedestroy($image);
            throw new RuntimeException('Failed to flip image');
        }

        return $image;
    }

    private function gdRotate(GdImage $image, float $degrees): GdImage
    {
        $rotated = imagerotate($image, $degrees, 0);
        imagedestroy($image);

        if ($rotated === false) {
            throw new RuntimeException('Failed to rotate image');
        }

        return $rotated;
    }

    private function cropGdToRatio(GdImage $image, float $targetRatio): GdImage
    {
        $box = $this->computeCropBox(imagesx($image), imagesy($image), $targetRatio);

        if ($box['width'] === imagesx($image) && $box['height'] === imagesy($image)) {
            return $image;
        }

        $cropped = imagecreatetruecolor($box['width'], $box['height']);
        if ($cropped === false) {
            imagedestroy($image);
            throw new RuntimeException('Failed to allocate cropped image');
        }

        imagecopy(
            $cropped,
            $image,
            0,
            0,
            $box['x'],
            $box['y'],
            $box['width'],
            $box['height'],
        );
        imagedestroy($image);

        return $cropped;
    }

    private function resizeGd(GdImage $image): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        [$outputWidth, $outputHeight] = $this->computeOutputSize($width, $height);

        if ($outputWidth === $width && $outputHeight === $height) {
            return $image;
        }

        $resized = imagecreatetruecolor($outputWidth, $outputHeight);
        if ($resized === false) {
            imagedestroy($image);
            throw new RuntimeException('Failed to allocate resized image');
        }

        imagecopyresampled(
            $resized,
            $image,
            0,
            0,
            0,
            0,
            $outputWidth,
            $outputHeight,
            $width,
            $height,
        );
        imagedestroy($image);

        return $resized;
    }

    private function writeGdJpeg(GdImage $image, string $absolutePath): void
    {
        $quality = self::JPEG_QUALITY_START;

        while (true) {
            ob_start();
            $ok = imagejpeg($image, null, $quality);
            $blob = ob_get_clean();

            if ($ok === false || !is_string($blob)) {
                throw new RuntimeException(sprintf('Cannot encode JPEG: %s', $absolutePath));
            }

            if (
                strlen($blob) <= self::MAX_FILE_BYTES
                || $quality <= self::JPEG_QUALITY_FLOOR
            ) {
                if (file_put_contents($absolutePath, $blob) === false) {
                    throw new RuntimeException(sprintf('Cannot write image: %s', $absolutePath));
                }

                return;
            }

            $quality -= self::JPEG_QUALITY_STEP;
        }
    }
}

final class ImageUnsupportedException extends RuntimeException
{
}
