<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Entity\Category;
use App\Entity\Slug;
use App\State\CategoryProvider;

#[ApiResource(
    shortName: 'Section',
    description: 'A section of the site. An article belongs to at most one.',
    operations: [
        new GetCollection(uriTemplate: '/sections', paginationEnabled: false),
        new Get(
            uriTemplate: '/sections/{slug}',
            uriVariables: ['slug'],
            requirements: ['slug' => Slug::ROUTE_PATTERN],
        ),
    ],
    provider: CategoryProvider::class,
)]
final class CategoryResource
{
    public function __construct(
        public string $slug,
        public string $name,
        public ?string $description,
        public ?string $parent,
    ) {
    }

    public static function from(Category $category): self
    {
        return new self(
            slug: $category->getSlug(),
            name: $category->getName(),
            description: $category->getDescription(),
            parent: $category->getParent()?->getSlug(),
        );
    }
}
