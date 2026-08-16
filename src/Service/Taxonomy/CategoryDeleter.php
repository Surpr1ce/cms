<?php

declare(strict_types=1);

namespace App\Service\Taxonomy;

use App\Entity\Category;
use App\Repository\ArticleRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Removes a section without destroying anything it grouped.
 *
 * FR-016 has two halves and only one of them can be a database constraint:
 *
 * - Articles become uncategorised. `ON DELETE SET NULL` already guarantees that
 *   in the table, but an Article already loaded in Doctrine's identity map would
 *   keep pointing at a row that no longer exists. Clearing the association here
 *   is not redundant with the constraint; it is what stops that stale reference
 *   becoming a defect at some distance from its cause.
 * - Child sections move up to their grandparent. No constraint can express
 *   that, so this is the only place it can happen. The constraint's fallback —
 *   making them top-level — is coherent but not what the specification asks for.
 */
final readonly class CategoryDeleter
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ArticleRepository $articles,
    ) {
    }

    public function delete(Category $category): void
    {
        foreach ($this->articles->findByCategory($category) as $article) {
            $article->setCategory(null);
        }

        $grandparent = $category->getParent();

        foreach ($category->getChildren() as $child) {
            $child->setParent($grandparent);
        }

        $this->entityManager->remove($category);
        $this->entityManager->flush();
    }
}
