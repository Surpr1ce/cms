<?php

declare(strict_types=1);

namespace App\Service\Sitemap;

use function count;

use InvalidArgumentException;

/**
 * How many addresses one sitemap document has left to give.
 *
 * A sitemap holds fifty thousand addresses — the protocol's number, not this
 * application's — and a document is assembled from four lists: articles, pages,
 * sections and labels. A limit on each of the four would be four times the
 * limit, so what is bounded is the document, and the four lists spend from one
 * budget in the order they are written.
 *
 * It lives here rather than in the controller because it is arithmetic with a
 * rule in it — a list is asked only for what is left, and never for a negative
 * number — and arithmetic is worth being able to test without a request, a
 * database or fifty thousand rows.
 *
 * Not a service: it holds what has been spent, so each response makes its own.
 * A shared instance would run out on the second request of a long-lived worker.
 */
final class SitemapBudget
{
    /**
     * The limit the sitemap protocol sets on one document.
     *
     * A site with more addresses than this needs a sitemap *index* pointing at
     * several documents, which feature 019 records as out of scope. So this is a
     * ceiling with a known consequence rather than a number chosen to be safe:
     * past it, addresses are left out, and the fix is the index and not a larger
     * number here.
     */
    public const int MAXIMUM_ADDRESSES = 50_000;

    private int $remaining;

    public function __construct(int $total = self::MAXIMUM_ADDRESSES)
    {
        if ($total < 1) {
            throw new InvalidArgumentException('A sitemap holds at least the home page.');
        }

        $this->remaining = $total;
    }

    public function remaining(): int
    {
        return $this->remaining;
    }

    public function isExhausted(): bool
    {
        return 0 === $this->remaining;
    }

    /**
     * Spends addresses this document writes without asking a repository for them
     * — the home page, which the template emits unconditionally.
     */
    public function reserve(int $addresses): void
    {
        if ($addresses < 0) {
            throw new InvalidArgumentException('An address cannot be un-spent.');
        }

        $this->remaining = max(0, $this->remaining - $addresses);
    }

    /**
     * Fetches one list within what is left, and spends what came back.
     *
     * The limit is handed to the caller rather than applied afterwards, so a list
     * past the ceiling is never loaded — slicing after fetching would bound the
     * document and leave the query unbounded, which is the half that costs
     * memory. An exhausted budget asks for nothing at all rather than for zero
     * rows, because a query that can only return nothing is still a query.
     *
     * @template T
     *
     * @param callable(int): list<T> $fetch given the number of addresses left
     *
     * @return list<T>
     */
    public function take(callable $fetch): array
    {
        if ($this->isExhausted()) {
            return [];
        }

        $found = $fetch($this->remaining);

        $this->reserve(count($found));

        return $found;
    }
}
