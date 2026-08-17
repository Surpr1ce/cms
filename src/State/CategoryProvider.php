<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\CategoryResource;
use App\Entity\Category;
use App\Repository\CategoryRepository;

use function is_string;

/**
 * @implements ProviderInterface<CategoryResource>
 */
final readonly class CategoryProvider implements ProviderInterface
{
    public function __construct(private CategoryRepository $categories)
    {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     *
     * @return list<CategoryResource>|CategoryResource|null
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array|CategoryResource|null
    {
        if ($operation instanceof CollectionOperationInterface) {
            // Every section, including ones with nothing published in them. A
            // section is a structure rather than content, and its name discloses
            // nothing an article title would.
            return array_map(CategoryResource::from(...), $this->categories->findAllOrdered());
        }

        $slug = $uriVariables['slug'] ?? null;

        if (!is_string($slug)) {
            return null;
        }

        $category = $this->categories->findOneBySlug($slug);

        return !$category instanceof Category ? null : CategoryResource::from($category);
    }
}
