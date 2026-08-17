<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Entity\Slug;
use App\Entity\Tag;
use App\State\TagProvider;

/**
 * A label.
 *
 * The collection returns only labels carried by at least one published article —
 * TagRepository::findInUse(), the same method the website uses. A list of every
 * label in the table would name the subjects of unfinished drafts.
 */
#[ApiResource(
    shortName: 'Tag',
    description: 'A label carried by at least one published article.',
    operations: [
        new GetCollection(uriTemplate: '/tags', paginationEnabled: false),
        new Get(
            uriTemplate: '/tags/{slug}',
            uriVariables: ['slug'],
            requirements: ['slug' => Slug::ROUTE_PATTERN],
        ),
    ],
    provider: TagProvider::class,
)]
final class TagResource
{
    public function __construct(
        public string $slug,
        public string $name,
    ) {
    }

    public static function from(Tag $tag): self
    {
        return new self(slug: $tag->getSlug(), name: $tag->getName());
    }
}
