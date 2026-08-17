<?php

declare(strict_types=1);

namespace App\Service\Pagination;

use function array_slice;
use function count;

use const FILTER_VALIDATE_INT;

use InvalidArgumentException;

use function is_scalar;

/**
 * Turns a page number a reader supplied into an offset and a limit.
 *
 * Pure arithmetic with no request in sight, so the interesting cases — a
 * negative page, a page beyond the end, something that is not a number at all —
 * are unit-testable rather than reachable only through HTTP.
 *
 * An out-of-range page is clamped rather than refused (FR-022). A reader who
 * edits a URL by hand gets the first page, not an error page: nothing is broken,
 * they just asked for something that is not there.
 */
final readonly class Paginator
{
    public const int DEFAULT_PER_PAGE = 20;

    /**
     * The highest page anybody is given, whatever they asked for.
     *
     * Two reasons, and the first is not a nicety. `offsetFor()` multiplies, and a
     * page number near PHP_INT_MAX overflows to a float, which under
     * `declare(strict_types=1)` makes an `int` return type throw — so
     * `?page=9223372036854775807` was a 500 on the front page, every listing, the
     * search and the audit log. Found by a review, not by the suite: the test for
     * an "absurdly large" page stopped at 999999 and never reached the
     * multiplication.
     *
     * The second is cheaper to state. An accepted page of 999999 asks PostgreSQL
     * to rank twenty million rows and discard all of them, on a route nobody has
     * to sign in for. Ten thousand pages is two hundred thousand articles, which
     * is far past anything this CMS is for and still cheap to refuse.
     */
    public const int MAXIMUM_PAGE = 10_000;

    public function __construct(private int $perPage = self::DEFAULT_PER_PAGE)
    {
        if ($this->perPage < 1) {
            throw new InvalidArgumentException('A page has to hold at least one item.');
        }
    }

    /**
     * Normalises whatever arrived in the query string.
     *
     * Accepts the raw value rather than an int, because that is what a request
     * carries and because "abc" has to end up as page 1 somewhere. Doing it here
     * means no controller repeats the rule and no controller forgets it.
     */
    public static function pageNumberFrom(mixed $raw): int
    {
        if (!is_scalar($raw)) {
            return 1;
        }

        $page = filter_var((string) $raw, FILTER_VALIDATE_INT);

        if (false === $page || $page < 1) {
            return 1;
        }

        return min($page, self::MAXIMUM_PAGE);
    }

    public function perPage(): int
    {
        return $this->perPage;
    }

    public function offsetFor(int $pageNumber): int
    {
        return ($pageNumber - 1) * $this->perPage;
    }

    /**
     * How many rows to fetch for a page: one more than will be shown.
     *
     * That extra row is what answers "is there a next page" without a second
     * COUNT query — see SC-007. It is dropped again in {@see paginate()}.
     */
    public function fetchLimitFor(): int
    {
        return $this->perPage + 1;
    }

    /**
     * @template T
     *
     * @param list<T> $fetched result of a query using fetchLimitFor()
     *
     * @return ResultPage<T>
     */
    public function paginate(array $fetched, int $pageNumber): ResultPage
    {
        $hasNext = count($fetched) > $this->perPage;

        return new ResultPage(
            items: array_slice($fetched, 0, $this->perPage),
            number: $pageNumber,
            hasNext: $hasNext,
        );
    }
}
