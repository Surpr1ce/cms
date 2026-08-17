<?php

declare(strict_types=1);

namespace App\Search;

use function mb_strlen;
use function mb_substr;
use function preg_replace;
use function trim;

/**
 * What a reader typed, made safe to act on.
 *
 * A value object rather than a string passed around, because three separate
 * decisions hang off a query — whether it is worth running, what goes to the
 * database, and what is shown back in the box — and a bare string invites each
 * caller to make them differently.
 *
 * Nothing here escapes anything for SQL. The words reach PostgreSQL as a bound
 * parameter and are turned into a query by `plainto_tsquery`, which treats
 * operators, quotes and punctuation as words rather than as syntax. Escaping
 * would be the wrong repair for a problem that is better not to have.
 */
final readonly class SearchQuery
{
    /**
     * Below this almost everything matches and the answer is useless, so it is
     * not worth the cost of asking.
     */
    public const int MINIMUM_LENGTH = 2;

    /**
     * Above this nothing sensible is being asked. Bounded before the database
     * sees it, because a public unauthenticated endpoint is the cheapest thing
     * on a site to abuse.
     */
    public const int MAXIMUM_LENGTH = 200;

    private function __construct(public string $text)
    {
    }

    public static function from(?string $raw): self
    {
        if (null === $raw) {
            return new self('');
        }

        // Every run of whitespace to one space, and the ends trimmed. A query is
        // words; the shape of the gaps between them carries nothing.
        $text = trim((string) preg_replace('/\s+/u', ' ', $raw));

        if (mb_strlen($text) > self::MAXIMUM_LENGTH) {
            $text = trim(mb_substr($text, 0, self::MAXIMUM_LENGTH));
        }

        return new self($text);
    }

    /**
     * Nothing was typed. Distinct from "typed something too short", because the
     * two deserve different sentences: one is an invitation, the other is a
     * correction.
     */
    public function isEmpty(): bool
    {
        return '' === $this->text;
    }

    public function isTooShort(): bool
    {
        return !$this->isEmpty() && mb_strlen($this->text) < self::MINIMUM_LENGTH;
    }

    /**
     * Whether asking the database is worth doing at all.
     */
    public function isWorthRunning(): bool
    {
        return !$this->isEmpty() && !$this->isTooShort();
    }
}
