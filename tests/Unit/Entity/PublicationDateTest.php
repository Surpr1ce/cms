<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Page;
use App\Exception\InvalidStatusTransition;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

use function sprintf;

/**
 * SC-005, the single most important assertion in this feature: a publication
 * date, once set, is identical after any number of unpublish and publish cycles.
 *
 * If it were not, an article taken down for an hour would reappear at the top of
 * every listing and feed, and the reason would be invisible from the outside.
 * This is exact and instant to test only because the entity is handed the time
 * rather than reading a clock itself.
 */
final class PublicationDateTest extends TestCase
{
    public function testANewDraftHasNoPublicationDate(): void
    {
        self::assertNull($this->content()->getPublishedAt());
    }

    public function testThePublicationDateIsTheMomentPassedIn(): void
    {
        $content = $this->content();
        $moment = new DateTimeImmutable('2026-05-01 10:00:00');

        $content->publish($moment);

        self::assertEquals($moment, $content->getPublishedAt());
    }

    public function testUnpublishingLeavesThePublicationDateInPlace(): void
    {
        $content = $this->content();
        $first = new DateTimeImmutable('2026-05-01 10:00:00');

        $content->publish($first);
        $content->unpublish();

        self::assertEquals($first, $content->getPublishedAt());
    }

    public function testRepublishingDoesNotMoveThePublicationDate(): void
    {
        $content = $this->content();
        $first = new DateTimeImmutable('2026-05-01 10:00:00');

        $content->publish($first);
        $content->unpublish();
        $content->publish(new DateTimeImmutable('2026-09-30 23:59:59'));

        self::assertEquals($first, $content->getPublishedAt());
    }

    public function testThePublicationDateSurvivesManyCycles(): void
    {
        $content = $this->content();
        $first = new DateTimeImmutable('2026-05-01 10:00:00');

        $content->publish($first);

        for ($cycle = 1; $cycle <= 10; ++$cycle) {
            $content->unpublish();
            $content->publish(new DateTimeImmutable(sprintf('2026-06-%02d 12:00:00', $cycle)));
        }

        self::assertEquals($first, $content->getPublishedAt());
    }

    public function testArchivingLeavesThePublicationDateInPlace(): void
    {
        $content = $this->content();
        $first = new DateTimeImmutable('2026-05-01 10:00:00');

        $content->publish($first);
        $content->archive();

        self::assertEquals($first, $content->getPublishedAt());
    }

    public function testRestoringLeavesThePublicationDateInPlace(): void
    {
        $content = $this->content();
        $first = new DateTimeImmutable('2026-05-01 10:00:00');

        $content->publish($first);
        $content->archive();
        $content->restore();

        self::assertEquals($first, $content->getPublishedAt());
    }

    /**
     * FR-006, the other half: content archived straight from draft was never
     * published, so it has no date to show.
     */
    public function testContentArchivedWithoutBeingPublishedHasNoPublicationDate(): void
    {
        $content = $this->content();
        $content->archive();

        self::assertNull($content->getPublishedAt());
    }

    public function testPublishedContentAlwaysHasAPublicationDate(): void
    {
        $content = $this->content();
        $content->publish(new DateTimeImmutable('2026-05-01 10:00:00'));

        self::assertTrue($content->isPublished());
        self::assertNotNull($content->getPublishedAt());
    }

    /**
     * A refused publication must not stamp a date on the way out.
     */
    public function testARefusedPublicationLeavesNoDateBehind(): void
    {
        $content = $this->content();
        $content->archive();
        $content->restore();
        $content->archive();

        try {
            $content->publish(new DateTimeImmutable('2026-05-01 10:00:00'));
        } catch (InvalidStatusTransition) {
            // Expected; the assertion below is the point of the test.
        }

        self::assertNull($content->getPublishedAt());
    }

    private function content(): Page
    {
        $page = new Page('About us', 'about-us', new DateTimeImmutable('2026-04-01 09:00:00'));
        $page->setContent('Who we are.');

        return $page;
    }
}
