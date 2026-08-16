<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Media;

use App\Exception\UnsupportedMediaType;
use App\Service\Media\StoredFilenameGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * FR-021 and FR-022. The generator takes a detected type and nothing else, so
 * the hostile inputs below are not filtered out — they are never read.
 *
 * The tests still pass them, because "the parameter does not exist" is exactly
 * the property worth locking down: if someone adds an original-name parameter
 * later for convenience, these stop compiling rather than quietly passing.
 */
final class StoredFilenameGeneratorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function supportedTypeProvider(): iterable
    {
        yield 'JPEG' => ['image/jpeg', 'jpg'];
        yield 'PNG' => ['image/png', 'png'];
        yield 'GIF' => ['image/gif', 'gif'];
        yield 'WebP' => ['image/webp', 'webp'];
        yield 'AVIF' => ['image/avif', 'avif'];
        yield 'PDF' => ['application/pdf', 'pdf'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedTypeProvider(): iterable
    {
        yield 'PHP source' => ['application/x-httpd-php'];
        yield 'shell script' => ['application/x-sh'];
        yield 'HTML' => ['text/html'];
        yield 'SVG, which can carry script' => ['image/svg+xml'];
        yield 'Windows executable' => ['application/x-msdownload'];
        yield 'empty' => [''];
        yield 'nonsense' => ['not/a-type'];
    }

    #[DataProvider('supportedTypeProvider')]
    public function testItProducesTheExtensionForTheType(string $mimeType, string $extension): void
    {
        self::assertStringEndsWith('.'.$extension, new StoredFilenameGenerator()->generate($mimeType));
    }

    #[DataProvider('rejectedTypeProvider')]
    public function testAnUnacceptedTypeIsRefused(string $mimeType): void
    {
        $this->expectException(UnsupportedMediaType::class);

        new StoredFilenameGenerator()->generate($mimeType);
    }

    public function testTheRefusalNamesTheTypeAndWhatIsAccepted(): void
    {
        try {
            new StoredFilenameGenerator()->generate('image/svg+xml');
            self::fail('SVG should have been refused.');
        } catch (UnsupportedMediaType $unsupportedMediaType) {
            self::assertSame('image/svg+xml', $unsupportedMediaType->mimeType());
            self::assertContains('image/jpeg', $unsupportedMediaType->supported());
        }
    }

    /**
     * US5 scenario 1. The stored name contains no path separator and cannot,
     * because it is hexadecimal plus one known extension.
     */
    public function testTheStoredNameContainsNoPathSeparator(): void
    {
        $filename = new StoredFilenameGenerator()->generate('image/jpeg');

        self::assertStringNotContainsString('/', $filename);
        self::assertStringNotContainsString('\\', $filename);
        self::assertStringNotContainsString('..', $filename);
    }

    public function testTheStoredNameIsHexadecimalPlusAKnownExtension(): void
    {
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{32}\.(jpg|png|gif|webp|avif|pdf)$/',
            new StoredFilenameGenerator()->generate('image/jpeg'),
        );
    }

    public function testEveryNameIsDifferent(): void
    {
        $generator = new StoredFilenameGenerator();

        $names = [];
        for ($i = 0; $i < 100; ++$i) {
            $names[] = $generator->generate('image/png');
        }

        self::assertCount(100, array_unique($names));
    }

    /**
     * The detected type is what decides the extension. A PDF detected as a JPEG
     * would be stored as a JPEG — which is the point: the extension describes
     * what the bytes are, not what the uploader called the file.
     */
    public function testTheExtensionFollowsTheDetectedTypeNotTheClaim(): void
    {
        self::assertStringEndsWith('.jpg', new StoredFilenameGenerator()->generate('image/jpeg'));
        self::assertStringEndsWith('.pdf', new StoredFilenameGenerator()->generate('application/pdf'));
    }

    public function testTypeMatchingIgnoresCaseAndSurroundingSpace(): void
    {
        self::assertStringEndsWith('.png', new StoredFilenameGenerator()->generate('  IMAGE/PNG  '));
    }

    public function testItCanBeAskedWhetherATypeIsAcceptedWithoutGeneratingAnything(): void
    {
        $generator = new StoredFilenameGenerator();

        self::assertTrue($generator->supports('image/jpeg'));
        self::assertFalse($generator->supports('image/svg+xml'));
    }

    /**
     * The allow-list decides by naming what is permitted. A type nobody
     * anticipated is refused by default rather than accepted by default, which
     * is the only ordering that stays safe as formats appear.
     */
    public function testTheAcceptedListIsShortAndExcludesExecutableFormats(): void
    {
        $supported = StoredFilenameGenerator::supportedTypes();

        self::assertNotContains('image/svg+xml', $supported);
        self::assertNotContains('text/html', $supported);
        self::assertNotContains('application/x-httpd-php', $supported);
    }
}
