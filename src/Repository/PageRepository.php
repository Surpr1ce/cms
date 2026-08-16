<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ContentStatus;
use App\Entity\Page;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Page>
 */
final class PageRepository extends ServiceEntityRepository implements SluggedRepository
{
    private const string ALIAS = 'page';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Page::class);
    }

    public function existsWithSlug(string $slug): bool
    {
        return null !== $this->findOneBy(['slug' => $slug]);
    }

    public function findOneBySlug(string $slug): ?Page
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    public function findOnePublishedBySlug(string $slug): ?Page
    {
        $result = $this->publishedQuery()
            ->andWhere(self::ALIAS.'.slug = :slug')
            ->setParameter('slug', $slug)
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof Page ? $result : null;
    }

    /**
     * @return list<Page> newest first
     */
    public function findPublished(int $limit = 50, int $offset = 0): array
    {
        return array_values(
            $this->publishedQuery()
                ->setMaxResults($limit)
                ->setFirstResult($offset)
                ->getQuery()
                ->getResult(),
        );
    }

    public function countPublished(): int
    {
        return $this->count(['status' => ContentStatus::Published]);
    }

    /**
     * The menu, one level at a time. Passing null asks for the top level, which
     * saves every caller a special case for the root.
     *
     * Ordered by the explicit position, with the address as a tiebreak so two
     * pages sharing a position do not swap places between requests.
     *
     * @return list<Page>
     */
    public function findPublishedChildrenOf(?Page $parent): array
    {
        $query = $this->createQueryBuilder(self::ALIAS)
            ->andWhere(self::ALIAS.'.status = :status')
            ->setParameter('status', ContentStatus::Published)
            ->orderBy(self::ALIAS.'.menuOrder', 'ASC')
            ->addOrderBy(self::ALIAS.'.slug', 'ASC');

        $query = !$parent instanceof Page
            ? $query->andWhere(self::ALIAS.'.parent IS NULL')
            : $query->andWhere(self::ALIAS.'.parent = :parent')->setParameter('parent', $parent);

        return array_values($query->getQuery()->getResult());
    }

    public function countChildrenOf(Page $parent): int
    {
        return $this->count(['parent' => $parent]);
    }

    /**
     * The definition of "published" for pages, identical to the article one and
     * declared separately only because the two live in different tables. If a
     * third kind of content ever appears, this is the duplication to consolidate.
     */
    private function publishedQuery(): QueryBuilder
    {
        return $this->createQueryBuilder(self::ALIAS)
            ->andWhere(self::ALIAS.'.status = :status')
            ->setParameter('status', ContentStatus::Published)
            ->orderBy(self::ALIAS.'.publishedAt', 'DESC')
            ->addOrderBy(self::ALIAS.'.id', 'DESC');
    }
}
