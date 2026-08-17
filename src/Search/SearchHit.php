<?php

declare(strict_types=1);

namespace App\Search;

use DateTimeImmutable;

/**
 * One result.
 *
 * A read model, not an entity — the same reasoning as feature 006's
 * `src/ApiResource/`. A result list needs a title, an address, a date and a line
 * of text; loading two hundred articles with their authors, sections and labels
 * to show that is work nobody asked for, and hydrating entities would put the
 * whole of a draft's body in memory on the way to deciding not to show it.
 *
 * A field not written here cannot be shown, which is the same structural
 * guarantee the API read models give.
 */
final readonly class SearchHit
{
    public function __construct(
        /**
         * `article` or `page`. Kept as a plain string because it comes out of a
         * `UNION` as a literal, and because a reader is told which of the two
         * they are looking at.
         */
        public string $kind,
        public string $title,
        public string $slug,
        public string $summary,
        public ?DateTimeImmutable $publishedAt,
        public float $rank,
    ) {
    }

    public function isArticle(): bool
    {
        return 'article' === $this->kind;
    }
}
