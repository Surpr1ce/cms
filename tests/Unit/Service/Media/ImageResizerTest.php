<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Media;

use App\Exception\ImageCannotBeResized;
use App\Service\Media\ImageResizer;

use const DIRECTORY_SEPARATOR;

use function is_string;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function sprintf;

/**
 * Making a smaller copy of an image.
 *
 * Three rules, and the third is a security control rather than a nicety:
 *
 * **Nothing is enlarged**, because an enlarged image is a blurrier copy at a
 * larger file size.
 *
 * **Nothing is cropped**, so the proportions decide which side is the long one.
 * A CMS that crops has made an editorial decision on somebody's behalf and will
 * eventually cut a face in half.
 *
 * **An image past the pixel budget is refused before it is decoded.** A decoded
 * image costs roughly four bytes a pixel whatever it weighs on disk, so a file
 * comfortably inside the eight-megabyte upload limit can still ask for two
 * hundred megabytes of memory. {@see testAnImageTooLargeToDecodeIsRefusedBeforeItIsDecoded}
 * is the assertion that matters, and it is written with a file that *claims* an
 * enormous size in its header — which is exactly the shape a decompression bomb
 * takes, and the reason the check reads the header rather than the file.
 */
final class ImageResizerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cms-resizer-'.bin2hex(random_bytes(6));
        mkdir($directory);

        $this->directory = $directory;
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->directory.DIRECTORY_SEPARATOR.'*') as $path) {
            if (is_string($path)) {
                unlink($path);
            }
        }

        rmdir($this->directory);
    }

    public function testALandscapeImageIsReducedToTheRequestedLongestSide(): void
    {
        $source = $this->drawPng(1000, 500);
        $destination = $this->path('out.png');

        new ImageResizer()->resize($source, $destination, 'image/png', 400);

        self::assertSame([400, 200], $this->sizeOf($destination));
    }

    /**
     * The long side governs whichever it is, which is what "fits within a square"
     * means and what stops a portrait being squashed.
     */
    public function testAPortraitImageIsReducedByItsHeight(): void
    {
        $source = $this->drawPng(500, 1000);
        $destination = $this->path('out.png');

        new ImageResizer()->resize($source, $destination, 'image/png', 400);

        self::assertSame([200, 400], $this->sizeOf($destination));
    }

    public function testAnImageAlreadySmallerIsNotEnlarged(): void
    {
        $source = $this->drawPng(120, 80);
        $destination = $this->path('out.png');

        new ImageResizer()->resize($source, $destination, 'image/png', 400);

        self::assertSame([120, 80], $this->sizeOf($destination));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function typeProvider(): iterable
    {
        yield 'png' => ['image/png', 'png'];
        yield 'jpeg' => ['image/jpeg', 'jpg'];
        yield 'gif' => ['image/gif', 'gif'];
        yield 'webp' => ['image/webp', 'webp'];
    }

    /**
     * The format is preserved. Files are served with the type the record claims
     * and `nosniff`, so a derived copy in a different format is a copy the
     * browser refuses to render.
     */
    #[DataProvider('typeProvider')]
    public function testTheFormatIsPreserved(string $mimeType, string $extension): void
    {
        $source = $this->draw(600, 400, $mimeType, 'source.'.$extension);
        $destination = $this->path('out.'.$extension);

        new ImageResizer()->resize($source, $destination, $mimeType, 300);

        $size = getimagesize($destination);

        self::assertIsArray($size);
        self::assertSame($mimeType, $size['mime']);
        self::assertSame(300, $size[0]);
    }

    public function testAnImageTooLargeToDecodeIsRefusedBeforeItIsDecoded(): void
    {
        // A header claiming eighty megapixels, with no pixel data behind it. That
        // is what a decompression bomb looks like from the outside, and the point
        // of reading the header is that this file is refused in microseconds
        // rather than after two hundred megabytes have been allocated.
        $source = $this->path('enormous.png');
        file_put_contents($source, $this->pngHeaderClaiming(20_000, 4_000));

        $destination = $this->path('out.png');

        $this->expectException(ImageCannotBeResized::class);
        $this->expectExceptionMessageMatches('/past the limit/');

        try {
            new ImageResizer()->resize($source, $destination, 'image/png', 400);
        } finally {
            self::assertFileDoesNotExist($destination);
        }
    }

    public function testAFileThatIsNotAnImageIsRefused(): void
    {
        $source = $this->path('not-an-image.png');
        file_put_contents($source, 'this is not a picture');

        $this->expectException(ImageCannotBeResized::class);
        $this->expectExceptionMessageMatches('/could not be read/');

        new ImageResizer()->resize($source, $this->path('out.png'), 'image/png', 400);
    }

    public function testAMissingFileIsRefused(): void
    {
        $this->expectException(ImageCannotBeResized::class);
        $this->expectExceptionMessageMatches('/could not be read/');

        new ImageResizer()->resize($this->path('nothing-here.png'), $this->path('out.png'), 'image/png', 400);
    }

    /**
     * A type with no picture in it reaches here only through a mistake upstream,
     * and says so rather than producing an empty file.
     */
    public function testATypeWithNoImageInItIsRefused(): void
    {
        $source = $this->drawPng(100, 100);

        $this->expectException(ImageCannotBeResized::class);
        $this->expectExceptionMessageMatches('/not a type this application can resize/');

        new ImageResizer()->resize($source, $this->path('out.pdf'), 'application/pdf', 400);
    }

    // -------------------------------------------------------------- helpers

    private function path(string $name): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.$name;
    }

    /**
     * @param positive-int $width
     * @param positive-int $height
     */
    private function drawPng(int $width, int $height): string
    {
        return $this->draw($width, $height, 'image/png', sprintf('source-%dx%d.png', $width, $height));
    }

    /**
     * @param positive-int $width
     * @param positive-int $height
     */
    private function draw(int $width, int $height, string $mimeType, string $name): string
    {
        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image);

        $colour = imagecolorallocate($image, 10, 120, 200);
        self::assertNotFalse($colour);
        imagefilledrectangle($image, 0, 0, $width, $height, $colour);

        $path = $this->path($name);

        match ($mimeType) {
            'image/jpeg' => imagejpeg($image, $path, 90),
            'image/gif' => imagegif($image, $path),
            'image/webp' => imagewebp($image, $path, 90),
            default => imagepng($image, $path),
        };

        imagedestroy($image);

        return $path;
    }

    /**
     * A PNG signature and an IHDR chunk, and nothing else.
     *
     * `getimagesize()` reads dimensions from the header and does not verify the
     * checksum or the pixel data, which is precisely why the size check can run
     * before anything is decoded.
     */
    private function pngHeaderClaiming(int $width, int $height): string
    {
        $header = pack('C8', 0x89, 0x50, 0x4E, 0x47, 0x0D, 0x0A, 0x1A, 0x0A);

        $ihdr = 'IHDR'
            .pack('N', $width)
            .pack('N', $height)
            .pack('C5', 8, 2, 0, 0, 0);

        return $header.pack('N', 13).$ihdr.pack('N', crc32($ihdr));
    }

    /**
     * @return array{int, int}
     */
    private function sizeOf(string $path): array
    {
        $size = getimagesize($path);

        self::assertIsArray($size);

        return [$size[0], $size[1]];
    }
}
