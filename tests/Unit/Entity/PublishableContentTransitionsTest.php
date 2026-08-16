<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Article;
use App\Entity\ContentStatus;
use App\Entity\Page;
use App\Entity\PublishableContent;
use App\Entity\User;
use App\Exception\InvalidStatusTransition;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every transition, allowed and forbidden, on both kinds of content.
 *
 * Articles and pages are exercised through the same test methods rather than in
 * two parallel test classes, because the specification requires their behaviour
 * to be identical and two copies of a test drift the same way two copies of a
 * rule do.
 */
final class PublishableContentTransitionsTest extends TestCase
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
     * @param callable(): PublishableContent $make
     */
    #[DataProvider('contentProvider')]
    public function testNewContentIsADraftWithNoPublicationDate(callable $make): void
    {
        $content = $make();

        self::assertSame(ContentStatus::Draft, $content->getStatus());
        self::assertNull($content->getPublishedAt());
        self::assertFalse($content->isPublished());
    }

    /**
     * @param callable(): PublishableContent $make
     */
    #[DataProvider('contentProvider')]
    public function testADraftCanBePublished(callable $make): void
    {
        $content = $this->ready($make());
        $content->publish(self::at('2026-05-01 10:00:00'));

        self::assertSame(ContentStatus::Published, $content->getStatus());
        self::assertTrue($content->isPublished());
    }

    /**
     * @param callable(): PublishableContent $make
     */
    #[DataProvider('contentProvider')]
    public function testPublishedContentCanBeUnpublished(callable $make): void
    {
        $content = $this->ready($make());
        $content->publish(self::at('2026-05-01 10:00:00'));
        $content->unpublish();

        self::assertSame(ContentStatus::Draft, $content->getStatus());
        self::assertFalse($content->isPublished());
    }

    /**
     * @param callable(): PublishableContent $make
     */
    #[DataProvider('contentProvider')]
    public function testADraftCanBeArchivedWithoutEverBeingPublished(callable $make): void
    {
        $content = $this->ready($make());
        $content->archive();

        self::assertSame(ContentStatus::Archived, $content->getStatus());
        self::assertNull($content->getPublishedAt());
    }

    /**
     * @param callable(): PublishableContent $make
     */
    #[DataProvider('contentProvider')]
    public function testPublishedContentCanBeArchived(callable $make): void
    {
        $content = $this->ready($make());
        $content->publish(self::at('2026-05-01 10:00:00'));
        $content->archive();

        self::assertSame(ContentStatus::Archived, $content->getStatus());
        self::assertFalse($content->isPublished());
    }

    /**
     * FR-004: restoring returns content to draft, never straight to published.
     * Bringing something back and making it visible again are two decisions.
     *
     * @param callable(): PublishableContent $make
     */
    #[DataProvider('contentProvider')]
    public function testArchivedContentIsRestoredToDraftAndNotToPublished(callable $make): void
    {
        $content = $this->ready($make());
        $content->publish(self::at('2026-05-01 10:00:00'));
        $content->archive();
        $content->restore();

        self::assertSame(ContentStatus::Draft, $content->getStatus());
        self::assertFalse($content->isPublished());
    }

    /**
     * @param callable(): PublishableContent $make
     */
    #[DataProvider('contentProvider')]
    public function testRestoredContentCanBePublishedAgain(callable $make): void
    {
        $content = $this->ready($make());
        $content->publish(self::at('2026-05-01 10:00:00'));
        $content->archive();
        $content->restore();
        $content->publish(self::at('2026-06-01 10:00:00'));

        self::assertTrue($content->isPublished());
    }

    /**
     * US1 scenario 7: refused as invalid, not silently ignored. A no-op and a
     * success look identical from outside, so the caller could never tell.
     *
     * @param callable(): PublishableContent $make
     */
    #[DataProvider('contentProvider')]
    public function testPublishingAlreadyPublishedContentIsRefused(callable $make): void
    {
        $content = $this->ready($make());
        $content->publish(self::at('2026-05-01 10:00:00'));

        $this->expectException(InvalidStatusTransition::class);

        $content->publish(self::at('2026-05-02 10:00:00'));
    }

    /**
     * @param callable(): PublishableContent $make
     */
    #[DataProvider('contentProvider')]
    public function testUnpublishingADraftIsRefused(callable $make): void
    {
        $content = $this->ready($make());

        $this->expectException(InvalidStatusTransition::class);

        $content->unpublish();
    }

    /**
     * @param callable(): PublishableContent $make
     */
    #[DataProvider('contentProvider')]
    public function testUnpublishingArchivedContentIsRefused(callable $make): void
    {
        $content = $this->ready($make());
        $content->archive();

        $this->expectException(InvalidStatusTransition::class);

        $content->unpublish();
    }

    /**
     * @param callable(): PublishableContent $make
     */
    #[DataProvider('contentProvider')]
    public function testRestoringADraftIsRefused(callable $make): void
    {
        $content = $this->ready($make());

        $this->expectException(InvalidStatusTransition::class);

        $content->restore();
    }

    /**
     * @param callable(): PublishableContent $make
     */
    #[DataProvider('contentProvider')]
    public function testRestoringPublishedContentIsRefused(callable $make): void
    {
        $content = $this->ready($make());
        $content->publish(self::at('2026-05-01 10:00:00'));

        $this->expectException(InvalidStatusTransition::class);

        $content->restore();
    }

    /**
     * @param callable(): PublishableContent $make
     */
    #[DataProvider('contentProvider')]
    public function testArchivingArchivedContentIsRefused(callable $make): void
    {
        $content = $this->ready($make());
        $content->archive();

        $this->expectException(InvalidStatusTransition::class);

        $content->archive();
    }

    /**
     * The exception carries the states rather than only a sentence, so callers
     * and tests never have to read the message to find out what happened.
     *
     * @param callable(): PublishableContent $make
     */
    #[DataProvider('contentProvider')]
    public function testTheRefusalNamesTheCurrentAndAttemptedStatus(callable $make): void
    {
        $content = $this->ready($make());
        $content->archive();

        try {
            $content->publish(self::at('2026-05-01 10:00:00'));
            self::fail('Publishing archived content should have been refused.');
        } catch (InvalidStatusTransition $invalidStatusTransition) {
            self::assertSame(ContentStatus::Archived, $invalidStatusTransition->current());
            self::assertSame(ContentStatus::Published, $invalidStatusTransition->attempted());
        }
    }

    /**
     * A failed transition leaves the content exactly as it was. A rule that
     * refuses but mutates anyway is worse than one that does not refuse at all.
     *
     * @param callable(): PublishableContent $make
     */
    #[DataProvider('contentProvider')]
    public function testARefusedTransitionChangesNothing(callable $make): void
    {
        $content = $this->ready($make());
        $content->publish(self::at('2026-05-01 10:00:00'));

        $publishedAt = $content->getPublishedAt();

        try {
            $content->publish(self::at('2026-07-01 10:00:00'));
        } catch (InvalidStatusTransition) {
            // Expected; the assertions below are the point of the test.
        }

        self::assertSame(ContentStatus::Published, $content->getStatus());
        self::assertEquals($publishedAt, $content->getPublishedAt());
    }

    private static function article(): Article
    {
        return new Article(
            'An article',
            'an-article',
            new User('author@example.com', 'An Author', self::at('2026-01-01 00:00:00')),
            self::at('2026-04-01 09:00:00'),
        );
    }

    private static function page(): Page
    {
        return new Page('A page', 'a-page', self::at('2026-04-01 09:00:00'));
    }

    /**
     * Content with a body, so that publishing is refused for the reason under
     * test rather than for a missing body.
     */
    private function ready(PublishableContent $content): PublishableContent
    {
        $content->setContent('Something worth reading.');

        return $content;
    }

    private static function at(string $moment): DateTimeImmutable
    {
        return new DateTimeImmutable($moment);
    }
}
