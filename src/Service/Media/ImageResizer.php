<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Exception\ImageCannotBeResized;

use function function_exists;

use GdImage;

use function getimagesize;
use function imageavif;
use function imagecreatefromavif;
use function imagecreatefromgif;
use function imagecreatefromjpeg;
use function imagecreatefrompng;
use function imagecreatefromwebp;
use function imagegif;
use function imagejpeg;
use function imagepng;
use function imagescale;
use function imagewebp;

use const IMG_BICUBIC;

use function max;
use function min;
use function round;

/**
 * Makes a smaller copy of an image.
 *
 * GD rather than Imagick: it is compiled into the PHP this project runs on and
 * supports every type the upload allow-list accepts. Asking somebody to install
 * an extension to run a CMS is a heavier request than using what is already
 * there, and nothing here needs what Imagick does better.
 *
 * **Nothing is cropped and nothing is enlarged.** An image fits within a square
 * of the requested size, which means the proportions decide which side is the
 * long one; an image already smaller than that comes back untouched. A CMS that
 * crops has made an editorial decision on somebody's behalf, and it will
 * eventually cut a face in half.
 *
 * The pixel budget below is the only reason this class can be trusted with a
 * file somebody uploaded. A decoded image costs roughly four bytes a pixel
 * whatever it weighs on disk, so a modestly sized file can be a decompression
 * bomb — the eight-megabyte upload limit says nothing at all about how much
 * memory decoding it will want.
 */
final readonly class ImageResizer
{
    /**
     * Fifty megapixels: comfortably past any photograph an editor will upload,
     * and roughly two hundred megabytes decoded, which is a refusal rather than
     * a process that dies mid-request.
     */
    public const int MAXIMUM_PIXELS = 50_000_000;

    /**
     * @throws ImageCannotBeResized
     */
    public function resize(string $sourcePath, string $destinationPath, string $mimeType, int $longestSide): void
    {
        $dimensions = @getimagesize($sourcePath);

        if (false === $dimensions) {
            throw ImageCannotBeResized::becauseItCouldNotBeRead($sourcePath);
        }

        [$width, $height] = $dimensions;

        if ($width * $height > self::MAXIMUM_PIXELS) {
            throw ImageCannotBeResized::becauseItIsTooLarge($width * $height, self::MAXIMUM_PIXELS);
        }

        $image = $this->read($sourcePath, $mimeType);

        // Never upwards. An enlarged image is a blurrier copy of the original at
        // a larger file size, which is the opposite of the point.
        $scale = min(1.0, $longestSide / max($width, $height));
        $target = 1.0 === $scale ? $image : $this->scale($image, (int) round($width * $scale));

        $this->write($target, $destinationPath, $mimeType);
    }

    /**
     * @throws ImageCannotBeResized
     */
    private function read(string $path, string $mimeType): GdImage
    {
        $image = match ($mimeType) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/gif' => @imagecreatefromgif($path),
            'image/webp' => @imagecreatefromwebp($path),
            'image/avif' => function_exists('imagecreatefromavif') ? @imagecreatefromavif($path) : false,
            default => throw ImageCannotBeResized::becauseTheTypeHasNoImage($mimeType),
        };

        if (false === $image) {
            throw ImageCannotBeResized::becauseItCouldNotBeRead($path);
        }

        return $image;
    }

    /**
     * @throws ImageCannotBeResized
     */
    private function scale(GdImage $image, int $width): GdImage
    {
        $scaled = imagescale($image, $width, -1, IMG_BICUBIC);

        if (false === $scaled) {
            throw ImageCannotBeResized::becauseScalingFailed();
        }

        return $scaled;
    }

    /**
     * @throws ImageCannotBeResized
     */
    private function write(GdImage $image, string $path, string $mimeType): void
    {
        // The format is preserved. Converting everything to WebP is a real
        // improvement and a separate decision with its own compatibility
        // question; this class is about size.
        $written = match ($mimeType) {
            'image/jpeg' => imagejpeg($image, $path, 82),
            'image/png' => imagepng($image, $path, 6),
            'image/gif' => imagegif($image, $path),
            'image/webp' => imagewebp($image, $path, 82),
            'image/avif' => function_exists('imageavif') && imageavif($image, $path, 60),
            default => throw ImageCannotBeResized::becauseTheTypeHasNoImage($mimeType),
        };

        if (false === $written) {
            throw ImageCannotBeResized::becauseItCouldNotBeWritten($path);
        }
    }
}
