<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Article;
use App\Entity\ContentStatus;
use App\Entity\Tag;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Tag>
 */
final class TagRepository extends ServiceEntityRepository implements SluggedRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tag::class);
    }

    public function existsWithSlug(string $slug): bool
    {
        return null !== $this->findOneBy(['slug' => $slug]);
    }

    public function findOneBySlug(string $slug): ?Tag
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /**
     * Labels carried by at least one *published* article.
     *
     * A tag cloud built from every label in the table advertises drafts and
     * archived content by name and leads readers to pages they cannot see. The
     * published scope therefore reaches into this query too.
     *
     * @return list<Tag>
     */
    public function findInUse(): array
    {
        return array_values(
            $this->createQueryBuilder('tag')
                ->distinct()
                ->innerJoin(Article::class, 'article', Join::WITH, 'tag MEMBER OF article.tags')
                ->andWhere('article.status = :status')
                ->setParameter('status', ContentStatus::Published)
                ->orderBy('tag.name', 'ASC')
                ->getQuery()
                ->getResult(),
        );
    }

    /**
     * @return list<Tag>
     */
    public function findAllOrdered(): array
    {
        return array_values($this->findBy([], ['name' => 'ASC']));
    }
}
