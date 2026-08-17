<?php

declare(strict_types=1);

namespace App\Tests\Unit\DataFixtures;

use App\DataFixtures\PlaceholderImage;

use const IMAGETYPE_GIF;
use const IMAGETYPE_JPEG;
use const IMAGETYPE_PNG;
use const IMAGETYPE_WEBP;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The picture a development installation shows where a real upload would be.
 *
 * Worth testing rather than trusting, because both of its previous versions were
 * wrong in ways nobody noticed for a while, and both are recorded in the class:
 *
 * **It wrote a PNG for every record, including ones catalogued as JPEG.** Files
 * are served with the recorded type and `X-Content-Type-Options: nosniff`, so a
 * browser told "this is a JPEG" and handed a PNG refuses to render it rather than
 * working it out. That is the header doing its job, and the fixtures were the
 * first thing it caught. {@see testTheBytesAreOfTheTypeThatWasAskedFor} is that
 * bug, written down.
 *
 * **It was a one-by-one pixel.** Fine while nothing resized anything; useless
 * from feature 012, when the site began asking for a thumbnail, a medium and a
 * large. A development site that looks broken teaches people to ignore it looking
 * broken.
 */
final class PlaceholderImageTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int}>
     */
    public static function typeProvider(): iterable
    {
        yield 'png' => ['image/png', IMAGETYPE_PNG];
        yield 'jpeg' => ['image/jpeg', IMAGETYPE_JPEG];
        yield 'gif' => ['image/gif', IMAGETYPE_GIF];
        yield 'webp' => ['image/webp', IMAGETYPE_WEBP];
    }

    #[DataProvider('typeProvider')]
    public function testTheBytesAreOfTheTypeThatWasAskedFor(string $mimeType, int $expected): void
    {
        $size = getimagesizefromstring(new PlaceholderImage()->draw('seed', $mimeType));

        self::assertIsArray($size);
        self::assertSame($expected, $size[2]);
    }

    /**
     * Large enough that every derived size is a genuine reduction. If this ever
     * drops below the largest size the site asks for, thumbnails start being
     * enlargements.
     */
    /**
     * @return iterable<string, array{string}>
     */
    public static function mimeTypeProvider(): iterable
    {
        foreach (self::typeProvider() as $name => [$mimeType]) {
            yield $name => [$mimeType];
        }
    }

    #[DataProvider('mimeTypeProvider')]
    public function testItIsBigEnoughToBeReducedRatherThanEnlarged(string $mimeType): void
    {
        $size = getimagesizefromstring(new PlaceholderImage()->draw('seed', $mimeType));

        self::assertIsArray($size);
        self::assertSame(1200, $size[0]);
        self::assertSame(800, $size[1]);
    }

    /**
     * An unknown type falls back to JPEG rather than failing. In practice only
     * image/jpeg reaches that branch — a record with no image at all is handled
     * before this class is asked.
     */
    public function testAnUnfamiliarTypeStillProducesAnImage(): void
    {
        $size = getimagesizefromstring(new PlaceholderImage()->draw('seed', 'image/tiff'));

        self::assertIsArray($size);
        self::assertSame(IMAGETYPE_JPEG, $size[2]);
    }

    /**
     * The same seed is the same picture on every load, which is what keeps a
     * fixture reload from changing every image on the development site for no
     * reason.
     */
    public function testTheSameSeedDrawsTheSamePicture(): void
    {
        $image = new PlaceholderImage();

        self::assertSame($image->draw('abc', 'image/png'), $image->draw('abc', 'image/png'));
    }

    /**
     * And two files do not look alike, so a page of six placeholders reads as six
     * pictures rather than as one repeated — which is what makes a missing image
     * visible at a glance.
     */
    public function testTwoSeedsDrawDifferentPictures(): void
    {
        $image = new PlaceholderImage();

        self::assertNotSame($image->draw('abc', 'image/png'), $image->draw('xyz', 'image/png'));
    }
}
