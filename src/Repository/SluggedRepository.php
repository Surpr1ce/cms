<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Implemented by every repository whose entity carries an address.
 *
 * One method, deliberately. It is the whole of what UniqueSlugGenerator needs,
 * and keeping it to that is what makes addresses unique *per kind of content*
 * with no special-casing: an article and a page may both be "hello-world",
 * because they are asked separately.
 */
interface SluggedRepository
{
    public function existsWithSlug(string $slug): bool;
}
