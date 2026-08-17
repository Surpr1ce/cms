<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Sitemap;

use App\Service\Sitemap\SitemapBudget;

use function array_map;
use function count;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

use function range;

/**
 * The sitemap's ceiling, tested where fifty thousand rows cost nothing.
 *
 * SC-001 says no route loads an unbounded number of rows, and the sitemap was the
 * last one that did — the sections and labels had no limit at all. Proving the
 * ceiling through HTTP would mean creating fifty thousand articles, so the
 * arithmetic lives in its own class and is tested with lists of strings.
 *
 * What matters here is not that a number is stored. It is that the four lists
 * spend from *one* budget, that a list is never asked for more than is left, and
 * that the truncation happens at the end of the document rather than wherever a
 * repository happened to have a default limit.
 */
final class SitemapBudgetTest extends TestCase
{
    /**
     * The protocol's number, pinned so that changing it is a decision somebody
     * makes rather than a value somebody tunes. A site that needs more addresses
     * than this needs a sitemap index, which is a different document.
     */
    public function testTheCeilingIsTheOneTheSitemapProtocolSets(): void
    {
        // Asserted through a fresh budget rather than against the constant
        // itself: comparing a constant to its own value proves nothing, and what
        // a caller is given is what a new budget has to spend.
        self::assertSame(50_000, new SitemapBudget()->remaining());
    }

    public function testALimitedListIsAskedForNoMoreThanIsLeft(): void
    {
        $budget = new SitemapBudget(10);
        $asked = [];

        $budget->take(function (int $limit) use (&$asked): array {
            $asked[] = $limit;

            return $this->rows(4);
        });

        $budget->take(function (int $limit) use (&$asked): array {
            $asked[] = $limit;

            return $this->rows(3);
        });

        self::assertSame([10, 6], $asked);
        self::assertSame(3, $budget->remaining());
    }

    /**
     * The property the whole class exists for: four lists, one ceiling. Four
     * limits of ten would let forty addresses through.
     */
    public function testFourListsShareOneCeiling(): void
    {
        $budget = new SitemapBudget(10);

        $written = 0;

        foreach ([8, 8, 8, 8] as $available) {
            $written += count($budget->take(fn (int $limit): array => $this->rows(min($limit, $available))));
        }

        self::assertSame(10, $written);
        self::assertTrue($budget->isExhausted());
    }

    /**
     * An exhausted budget asks for nothing rather than for zero rows. A query that
     * can only come back empty is still a query, and there are three of them after
     * the first list on a site large enough to reach the ceiling.
     */
    public function testAnExhaustedBudgetDoesNotFetchAtAll(): void
    {
        $budget = new SitemapBudget(2);
        $budget->take(fn (int $limit): array => $this->rows(2));

        $called = false;

        $found = $budget->take(function (int $limit) use (&$called): array {
            $called = true;

            return $this->rows(1);
        });

        self::assertFalse($called, 'A list was fetched with nothing left to spend.');
        self::assertSame([], $found);
    }

    /**
     * The home page is written by the template whether or not anything else
     * exists, so it is spent rather than counted afterwards — otherwise a full
     * document holds one address more than the protocol allows.
     */
    public function testAddressesTheTemplateWritesItselfAreSpentUpFront(): void
    {
        $budget = new SitemapBudget(5);
        $budget->reserve(1);

        self::assertSame(4, $budget->remaining());
    }

    public function testTheRemainderNeverGoesBelowNothing(): void
    {
        $budget = new SitemapBudget(3);
        $budget->reserve(10);

        self::assertSame(0, $budget->remaining());
        self::assertTrue($budget->isExhausted());
    }

    public function testABudgetOfNothingIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SitemapBudget(0);
    }

    public function testSpendingBackwardsIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SitemapBudget(3)->reserve(-1);
    }

    /**
     * @return list<string>
     */
    private function rows(int $count): array
    {
        if ($count < 1) {
            return [];
        }

        return array_map(static fn (int $index): string => 'row-'.$index, range(1, $count));
    }
}
