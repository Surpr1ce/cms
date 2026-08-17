<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Category;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Category>
 */
final class CategoryRepository extends ServiceEntityRepository implements SluggedRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Category::class);
    }

    public function existsWithSlug(string $slug): bool
    {
        return null !== $this->findOneBy(['slug' => $slug]);
    }

    public function findOneBySlug(string $slug): ?Category
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /**
     * Passing null asks for the top level, which is the natural reading and
     * saves every caller a special case for the root of the tree.
     *
     * @return list<Category>
     */
    public function findChildrenOf(?Category $parent): array
    {
        return array_values($this->findBy(['parent' => $parent], ['name' => 'ASC']));
    }

    /**
     * The limit is optional because a menu builder wants the whole tree — a tree
     * missing its last branches is not one. The sitemap passes what is left of
     * its address budget instead, which is the one caller that has a ceiling to
     * respect.
     *
     * @return list<Category> the whole tree, alphabetically, for a menu builder
     *                        to arrange
     */
    public function findAllOrdered(?int $limit = null): array
    {
        return array_values($this->findBy([], ['name' => 'ASC'], $limit));
    }
}
