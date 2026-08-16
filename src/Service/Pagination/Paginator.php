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

        return false === $page || $page < 1 ? 1 : $page;
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
