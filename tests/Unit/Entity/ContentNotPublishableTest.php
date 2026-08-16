<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\ContentStatus;
use App\Entity\Page;
use App\Exception\ContentNotPublishable;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * FR-007: content cannot be published without a title and a body.
 *
 * A draft may be as empty as its author likes — that is what a draft is for. The
 * rule applies at the moment it becomes visible to a reader, and not before.
 */
final class ContentNotPublishableTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function blankProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'spaces' => ['   '];
        yield 'a tab' => ["\t"];
        yield 'a newline' => ["\n"];
        yield 'mixed whitespace' => [" \t\n "];
    }

    #[DataProvider('blankProvider')]
    public function testPublishingWithoutABodyIsRefused(string $blank): void
    {
        $content = $this->content();
        $content->setContent($blank);

        $this->expectException(ContentNotPublishable::class);

        $content->publish(new DateTimeImmutable('2026-05-01 10:00:00'));
    }

    #[DataProvider('blankProvider')]
    public function testPublishingWithoutATitleIsRefused(string $blank): void
    {
        $content = $this->content();
        $content->setTitle($blank);

        $this->expectException(ContentNotPublishable::class);

        $content->publish(new DateTimeImmutable('2026-05-01 10:00:00'));
    }

    public function testARefusedPublicationLeavesTheContentADraft(): void
    {
        $content = $this->content();
        $content->setContent('');

        try {
            $content->publish(new DateTimeImmutable('2026-05-01 10:00:00'));
            self::fail('Publishing an empty body should have been refused.');
        } catch (ContentNotPublishable) {
            // Expected; the assertions below are the point of the test.
        }

        self::assertSame(ContentStatus::Draft, $content->getStatus());
        self::assertNull($content->getPublishedAt());
    }

    public function testTheRefusalNamesTheOffendingField(): void
    {
        $content = $this->content();
        $content->setContent('');

        try {
            $content->publish(new DateTimeImmutable('2026-05-01 10:00:00'));
            self::fail('Publishing an empty body should have been refused.');
        } catch (ContentNotPublishable $contentNotPublishable) {
            self::assertSame('content', $contentNotPublishable->field());
        }
    }

    public function testTheTitleIsCheckedBeforeTheBody(): void
    {
        $content = $this->content();
        $content->setTitle('');
        $content->setContent('');

        try {
            $content->publish(new DateTimeImmutable('2026-05-01 10:00:00'));
            self::fail('Publishing should have been refused.');
        } catch (ContentNotPublishable $contentNotPublishable) {
            self::assertSame(
                'title',
                $contentNotPublishable->field(),
                'With both missing, the first thing an author would fix is named.',
            );
        }
    }

    public function testADraftMayBeEmptyForAsLongAsItLikes(): void
    {
        $content = $this->content();
        $content->setTitle('');
        $content->setContent('');

        self::assertSame(ContentStatus::Draft, $content->getStatus());
    }

    /**
     * A body that is only whitespace is not a body, but a body that merely
     * *begins* with whitespace is.
     */
    public function testABodyWithLeadingWhitespaceIsStillABody(): void
    {
        $content = $this->content();
        $content->setContent("\n   Something worth reading.");

        $content->publish(new DateTimeImmutable('2026-05-01 10:00:00'));

        self::assertTrue($content->isPublished());
    }

    private function content(): Page
    {
        $page = new Page('About us', 'about-us', new DateTimeImmutable('2026-04-01 09:00:00'));
        $page->setContent('Who we are.');

        return $page;
    }
}
