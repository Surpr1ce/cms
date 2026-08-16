<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Page;
use App\Exception\SlugIsFrozen;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * FR-012, the half the entity can enforce on its own: once readers have been
 * able to link to something, its address stops moving.
 *
 * The other half — regenerating a draft's address when its title changes —
 * needs the database to know what is free, so it lives in UniqueSlugGenerator
 * and cannot be enforced here. That gap is recorded in ADR 0006 rather than
 * papered over.
 */
final class SlugFreezeTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedSlugProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'uppercase' => ['Hello-World'];
        yield 'spaces' => ['hello world'];
        yield 'leading hyphen' => ['-hello'];
        yield 'trailing hyphen' => ['hello-'];
        yield 'doubled hyphen' => ['hello--world'];
        yield 'punctuation' => ['hello_world'];
        yield 'a slash, which would change the route' => ['hello/world'];
        yield 'accented' => ['žltý-kôň'];
    }

    public function testADraftAddressCanBeChangedFreely(): void
    {
        $content = $this->draft();

        $content->assignSlug('a-better-address');

        self::assertSame('a-better-address', $content->getSlug());
    }

    public function testTheAddressIsFrozenOncePublished(): void
    {
        $content = $this->draft();
        $content->publish(new DateTimeImmutable('2026-05-01 10:00:00'));

        $this->expectException(SlugIsFrozen::class);

        $content->assignSlug('a-better-address');
    }

    /**
     * Unpublishing does not thaw it. What matters is that readers were once able
     * to link to it, not what the status happens to be now.
     */
    public function testTheAddressStaysFrozenAfterUnpublishing(): void
    {
        $content = $this->draft();
        $content->publish(new DateTimeImmutable('2026-05-01 10:00:00'));
        $content->unpublish();

        $this->expectException(SlugIsFrozen::class);

        $content->assignSlug('a-better-address');
    }

    public function testTheAddressStaysFrozenAfterArchiving(): void
    {
        $content = $this->draft();
        $content->publish(new DateTimeImmutable('2026-05-01 10:00:00'));
        $content->archive();

        $this->expectException(SlugIsFrozen::class);

        $content->assignSlug('a-better-address');
    }

    /**
     * Content archived straight from draft was never published, so nobody can
     * have linked to it and there is nothing to protect.
     */
    public function testAnAddressNeverPublishedIsNotFrozenByArchiving(): void
    {
        $content = $this->draft();
        $content->archive();

        $content->assignSlug('a-better-address');

        self::assertSame('a-better-address', $content->getSlug());
    }

    public function testTheRefusalCarriesBothAddresses(): void
    {
        $content = $this->draft();
        $content->publish(new DateTimeImmutable('2026-05-01 10:00:00'));

        try {
            $content->assignSlug('a-better-address');
            self::fail('Changing a published address should have been refused.');
        } catch (SlugIsFrozen $slugIsFrozen) {
            self::assertSame('about-us', $slugIsFrozen->currentSlug());
            self::assertSame('a-better-address', $slugIsFrozen->attemptedSlug());
        }
    }

    public function testARefusedChangeLeavesTheAddressAlone(): void
    {
        $content = $this->draft();
        $content->publish(new DateTimeImmutable('2026-05-01 10:00:00'));

        try {
            $content->assignSlug('a-better-address');
        } catch (SlugIsFrozen) {
            // Expected; the assertion below is the point of the test.
        }

        self::assertSame('about-us', $content->getSlug());
    }

    /**
     * FR-009 held at the entity boundary as well as in the service, so content
     * cannot carry an address that would not survive being put in a URL.
     */
    #[DataProvider('malformedSlugProvider')]
    public function testAMalformedAddressIsRefused(string $slug): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->draft()->assignSlug($slug);
    }

    #[DataProvider('malformedSlugProvider')]
    public function testContentCannotBeConstructedWithAMalformedAddress(string $slug): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Page('About us', $slug, new DateTimeImmutable('2026-04-01 09:00:00'));
    }

    private function draft(): Page
    {
        $page = new Page('About us', 'about-us', new DateTimeImmutable('2026-04-01 09:00:00'));
        $page->setContent('Who we are.');

        return $page;
    }
}
