<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Pagination;

use App\Service\Pagination\Paginator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * FR-026: the pagination boundaries, each with a test.
 *
 * Arithmetic tested as arithmetic. Reaching these cases through HTTP would mean
 * four functional tests per boundary — one per listing — for something that has
 * nothing to do with HTTP.
 */
final class PaginatorTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, int}>
     */
    public static function pageNumberProvider(): iterable
    {
        yield 'absent' => [null, 1];
        yield 'empty string' => ['', 1];
        yield 'not a number' => ['abc', 1];
        yield 'zero' => ['0', 1];
        yield 'negative' => ['-3', 1];
        yield 'a float' => ['2.5', 1];
        yield 'an array' => [['2'], 1];
        // Leading zeroes fall back to page 1 rather than meaning 7. The
        // validator refuses them because "007" is ambiguous between decimal and
        // octal, and no link this site generates ever has that shape — so the
        // safe reading wins over the guessed one. Written down because the first
        // version of this test assumed 7 and was wrong about the code, not the
        // other way round.
        yield 'leading zeroes' => ['007', 1];
        yield 'one' => ['1', 1];
        yield 'a real page' => ['4', 4];
        yield 'an int already' => [9, 9];
        yield 'absurdly large' => ['999999', 999999];
        yield 'SQL-looking' => ['1; DROP TABLE article', 1];
    }

    #[DataProvider('pageNumberProvider')]
    public function testItNormalisesWhateverArrivedInTheQueryString(mixed $raw, int $expected): void
    {
        self::assertSame($expected, Paginator::pageNumberFrom($raw));
    }

    public function testTheFirstPageStartsAtTheBeginning(): void
    {
        self::assertSame(0, new Paginator(20)->offsetFor(1));
    }

    public function testEachPageStartsWhereTheLastEnded(): void
    {
        $paginator = new Paginator(20);

        self::assertSame(20, $paginator->offsetFor(2));
        self::assertSame(80, $paginator->offsetFor(5));
    }

    /**
     * The extra row is what answers "is there a next page" without a second
     * COUNT query — SC-007.
     */
    public function testItFetchesOneMoreRowThanItShows(): void
    {
        self::assertSame(21, new Paginator(20)->fetchLimitFor());
    }

    public function testAFullPageWithMoreBehindItHasANextPage(): void
    {
        $page = new Paginator(3)->paginate(['a', 'b', 'c', 'd'], 1);

        self::assertSame(['a', 'b', 'c'], $page->items);
        self::assertTrue($page->hasNext);
    }

    public function testTheExtraRowIsNeverShownToTheReader(): void
    {
        self::assertCount(3, new Paginator(3)->paginate(['a', 'b', 'c', 'd'], 1)->items);
    }

    public function testAFullPageWithNothingBehindItIsTheLastPage(): void
    {
        $page = new Paginator(3)->paginate(['a', 'b', 'c'], 1);

        self::assertSame(['a', 'b', 'c'], $page->items);
        self::assertFalse($page->hasNext);
    }

    public function testAPartialPageIsTheLastPage(): void
    {
        self::assertFalse(new Paginator(20)->paginate(['a', 'b'], 1)->hasNext);
    }

    public function testTheFirstPageHasNoPreviousPage(): void
    {
        self::assertFalse(new Paginator(20)->paginate(['a'], 1)->hasPrevious());
    }

    public function testAnyLaterPageHasAPreviousPage(): void
    {
        self::assertTrue(new Paginator(20)->paginate(['a'], 2)->hasPrevious());
    }

    public function testAPageBeyondTheEndIsEmptyAndOffersAWayBack(): void
    {
        $page = new Paginator(20)->paginate([], 99);

        self::assertTrue($page->isEmpty());
        self::assertFalse($page->hasNext);
        self::assertTrue($page->hasPrevious());
        self::assertSame(98, $page->previousNumber());
    }

    public function testTheNavigationNumbersAreTheNeighbouringPages(): void
    {
        $page = new Paginator(20)->paginate(['a'], 5);

        self::assertSame(4, $page->previousNumber());
        self::assertSame(6, $page->nextNumber());
    }

    public function testThePreviousOfTheFirstPageIsStillTheFirstPage(): void
    {
        self::assertSame(1, new Paginator(20)->paginate(['a'], 1)->previousNumber());
    }

    public function testAPageHasToHoldSomething(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Paginator(0);
    }

    public function testANegativePageSizeIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Paginator(-5);
    }
}
