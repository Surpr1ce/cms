<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Article;
use App\Entity\Media;
use App\Entity\Page;
use App\Entity\PublishableContent;
use App\Entity\User;
use App\Exception\MediaMissingAltText;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * FR-023: a file with no alternative text cannot be put in front of a reader.
 *
 * The rule sits at the point of use rather than on Media, so cataloguing an
 * upload never fails on a field the uploader has not reached yet.
 */
final class FeaturedImageTest extends TestCase
{
    /**
     * @return iterable<string, array{callable(): PublishableContent}>
     */
    public static function contentProvider(): iterable
    {
        yield 'article' => [self::article(...)];
        yield 'page' => [self::page(...)];
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function unusableAltTextProvider(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'spaces' => ['   '];
        yield 'a tab' => ["\t"];
    }

    /**
     * @param callable(): PublishableContent $make
     */
    #[DataProvider('contentProvider')]
    public function testNewContentHasNoLeadImage(callable $make): void
    {
        self::assertNull($make()->getFeaturedImage());
    }

    /**
     * @param callable(): PublishableContent $make
     */
    #[DataProvider('contentProvider')]
    public function testAFileWithAlternativeTextCanBeUsed(callable $make): void
    {
        $content = $make();
        $media = $this->media('A cat asleep on a keyboard.');

        $content->setFeaturedImage($media);

        self::assertSame($media, $content->getFeaturedImage());
    }

    /**
     * @param callable(): PublishableContent $make
     */
    #[DataProvider('contentProvider')]
    public function testAFileWithoutAlternativeTextIsRefused(callable $make): void
    {
        $content = $make();

        $this->expectException(MediaMissingAltText::class);

        $content->setFeaturedImage($this->media(null));
    }

    /**
     * Whitespace is not a description. An image labelled " " is as unreadable to
     * a screen reader as one labelled nothing.
     */
    #[DataProvider('unusableAltTextProvider')]
    public function testWhitespaceDoesNotCountAsAlternativeText(?string $altText): void
    {
        $content = self::article();

        $this->expectException(MediaMissingAltText::class);

        $content->setFeaturedImage($this->media($altText));
    }

    /**
     * @param callable(): PublishableContent $make
     */
    #[DataProvider('contentProvider')]
    public function testDetachingTheLeadImageAlwaysSucceeds(callable $make): void
    {
        $content = $make();
        $content->setFeaturedImage($this->media('A description.'));

        $content->setFeaturedImage(null);

        self::assertNull($content->getFeaturedImage());
    }

    public function testARefusedAttachmentLeavesThePreviousImageInPlace(): void
    {
        $content = self::article();
        $described = $this->media('A description.');
        $content->setFeaturedImage($described);

        try {
            $content->setFeaturedImage($this->media(null));
        } catch (MediaMissingAltText) {
            // Expected; the assertion below is the point of the test.
        }

        self::assertSame($described, $content->getFeaturedImage());
    }

    public function testTheRefusalNamesTheStoredFile(): void
    {
        $media = $this->media(null);

        try {
            self::article()->setFeaturedImage($media);
            self::fail('A file with no alternative text should have been refused.');
        } catch (MediaMissingAltText $mediaMissingAltText) {
            self::assertSame($media->getFilename(), $mediaMissingAltText->filename());
        }
    }

    /**
     * Cataloguing must not fail on the missing field; only using it must.
     */
    public function testAFileCanBeCataloguedWithoutAlternativeText(): void
    {
        self::assertFalse($this->media(null)->hasAltText());
    }

    public function testAlternativeTextCanBeAddedAfterwards(): void
    {
        $media = $this->media(null);
        $media->setAltText('A description added later.');

        self::assertTrue($media->hasAltText());

        $content = self::article();
        $content->setFeaturedImage($media);

        self::assertSame($media, $content->getFeaturedImage());
    }

    /**
     * The name a client supplied is kept for display and never becomes the name
     * the file is stored under.
     */
    public function testTheSuppliedNameIsKeptOnlyAsDisplayText(): void
    {
        $media = new Media(
            'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6.jpg',
            '../../evil.php',
            'image/jpeg',
            2048,
            self::author(),
            new DateTimeImmutable('2026-05-01 10:00:00'),
        );

        self::assertSame('../../evil.php', $media->getOriginalName());
        self::assertSame('a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6.jpg', $media->getFilename());
        self::assertStringNotContainsString('evil', $media->getFilename());

        // That Media has no setFilename() is not asserted here. PHPStan proves it
        // statically at level max — an added setter would fail the quality gate
        // long before a test could — and it rejected the runtime check as always
        // false, which it is. A guarantee the type system holds does not need a
        // second, weaker one in a test.
    }

    private static function article(): Article
    {
        return new Article('An article', 'an-article', self::author(), new DateTimeImmutable('2026-04-01 09:00:00'));
    }

    private static function page(): Page
    {
        return new Page('A page', 'a-page', new DateTimeImmutable('2026-04-01 09:00:00'));
    }

    private static function author(): User
    {
        return new User('author@example.com', 'An Author', new DateTimeImmutable('2026-01-01 00:00:00'));
    }

    private function media(?string $altText): Media
    {
        $media = new Media(
            'f1e2d3c4b5a697887766554433221100.jpg',
            'holiday.jpg',
            'image/jpeg',
            123_456,
            self::author(),
            new DateTimeImmutable('2026-05-01 10:00:00'),
        );

        $media->setAltText($altText);

        return $media;
    }
}
