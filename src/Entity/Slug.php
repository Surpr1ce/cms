<?php

declare(strict_types=1);

namespace App\Entity;

use InvalidArgumentException;

use function sprintf;

/**
 * What a usable address looks like, in one place.
 *
 * Four entities carry an address and each stores it in its own column with its
 * own length, but the *shape* is one rule, and one rule belongs in one place.
 * The same expression is asserted directly in SlugGenerator's tests, so the
 * service that produces addresses and the entities that accept them cannot
 * drift apart.
 *
 * Not an entity and not mapped — a plain holder for the rule. It is in this
 * namespace because it is part of the domain vocabulary, not because Doctrine
 * has anything to do with it.
 */
final class Slug
{
    /**
     * FR-009 of specs/001-core-content-model: lowercase letters, digits and
     * single hyphens, with no hyphen at either end, and never empty.
     */
    public const string PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /**
     * The same rule without the delimiters, for a route requirement.
     *
     * Derived rather than written twice: a route that accepted a shape the
     * entity refuses would advertise addresses no content can ever have, and two
     * copies of a regular expression drift the way two copies of anything do.
     */
    public const string ROUTE_PATTERN = '[a-z0-9]+(?:-[a-z0-9]+)*';

    private function __construct()
    {
    }

    /**
     * @throws InvalidArgumentException on anything that would not survive being
     *                                  put in a URL
     */
    public static function assertWellFormed(string $slug): string
    {
        if (1 !== preg_match(self::PATTERN, $slug)) {
            throw new InvalidArgumentException(sprintf('"%s" is not a usable address.', $slug));
        }

        return $slug;
    }
}
