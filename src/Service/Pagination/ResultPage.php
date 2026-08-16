<?php

declare(strict_types=1);

namespace App\Service\Pagination;

/**
 * One page of results, and the two facts a template needs to draw navigation.
 *
 * There is no total count and no "page 7 of 23", deliberately. A total needs a
 * second query per listing, which is what SC-007 exists to avoid, and it can
 * disagree with the page itself when something is published between the two
 * queries. Previous and next are always true.
 *
 * @template T
 */
final readonly class ResultPage
{
    /**
     * @param list<T> $items
     */
    public function __construct(
        public array $items,
        public int $number,
        public bool $hasNext,
    ) {
    }

    public function hasPrevious(): bool
    {
        return $this->number > 1;
    }

    public function previousNumber(): int
    {
        return max(1, $this->number - 1);
    }

    public function nextNumber(): int
    {
        return $this->number + 1;
    }

    public function isEmpty(): bool
    {
        return [] === $this->items;
    }
}
