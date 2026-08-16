<?php

declare(strict_types=1);

namespace App\Service\Slug;

use App\Repository\SluggedRepository;

/**
 * Turns a title into an address that is free within its own kind of content.
 *
 * The repository is a per-call argument rather than a constructor dependency,
 * which is what makes uniqueness *per kind* fall out with no special-casing: an
 * article and a page may both be "hello-world" because they are asked
 * separately. See
 * docs/adr/0006-generate-slugs-in-a-service-and-freeze-them-at-publication.md.
 *
 * This resolves collisions it can see. Two requests generating the same slug at
 * the same instant see nothing of each other, and the unique index catches that
 * — a genuine conflict for the caller to retry, not a case to swallow here.
 */
final readonly class UniqueSlugGenerator
{
    public function __construct(private SlugGenerator $generator)
    {
    }

    public function generate(string $title, SluggedRepository $repository): string
    {
        $base = $this->generator->generate($title);

        if (!$repository->existsWithSlug($base)) {
            return $base;
        }

        // A counter rather than a random suffix: "hello-world-2" reads like a
        // second article on the same subject, "hello-world-a8f3" reads like a
        // mistake.
        $suffix = 2;

        while ($repository->existsWithSlug($base.'-'.$suffix)) {
            ++$suffix;
        }

        return $base.'-'.$suffix;
    }
}
