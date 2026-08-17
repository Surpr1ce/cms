<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ContentStatus;
use App\Entity\Media;
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
     * One page of pages, whatever their status, for the administration screen.
     *
     * In menu order and then by title, which is the order the screen has always
     * shown, with the identifier as the tiebreak — two pages sharing an order and
     * a title would otherwise swap between requests and pagination would repeat
     * or skip one.
     *
     * Unlike the article list this needs no viewer: `PageVoter` grants nothing to
     * an author and everything to the editorial roles, so anybody who may open
     * this screen at all may see every row on it.
     *
     * The parent is fetched with the page because the screen shows it in a column,
     * and a lazy association there is one query per row — the N+1 SC-003 exists to
     * keep out. A left join: a top-level page has no parent, and an inner one
     * would silently drop every page that is its own top level.
     *
     * @return list<Page>
     */
    public function findPage(int $limit, int $offset): array
    {
        return array_values(
            $this->createQueryBuilder('page')
                ->addSelect('parent')
                ->leftJoin('page.parent', 'parent')
                ->orderBy('page.menuOrder', 'ASC')
                ->addOrderBy('page.title', 'ASC')
                ->addOrderBy('page.id', 'ASC')
                ->setMaxResults($limit)
                ->setFirstResult($offset)
                ->getQuery()
                ->getResult(),
        );
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

    /**
     * Every published page, ordered so a menu can be assembled from one query.
     *
     * The whole set rather than one level at a time: a site's menu is a handful
     * of rows, and fetching it level by level is a query per level for no gain.
     * The parent is join-fetched so grouping by parent needs no further query.
     *
     * @return list<Page>
     */
    public function findPublishedForMenu(): array
    {
        return array_values(
            $this->createQueryBuilder(self::ALIAS)
                ->addSelect('parent')
                ->leftJoin(self::ALIAS.'.parent', 'parent')
                ->andWhere(self::ALIAS.'.status = :status')
                ->setParameter('status', ContentStatus::Published)
                ->orderBy(self::ALIAS.'.menuOrder', 'ASC')
                ->addOrderBy(self::ALIAS.'.title', 'ASC')
                ->getQuery()
                ->getResult(),
        );
    }

    /**
     * One published page with its lead image and parent chain available.
     */
    public function findOnePublishedBySlugWithRelations(string $slug): ?Page
    {
        $result = $this->publishedQuery()
            ->addSelect('featuredImage', 'parent')
            ->leftJoin(self::ALIAS.'.featuredImage', 'featuredImage')
            ->leftJoin(self::ALIAS.'.parent', 'parent')
            ->andWhere(self::ALIAS.'.slug = :slug')
            ->setParameter('slug', $slug)
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof Page ? $result : null;
    }

    /**
     * Pages using a file as their lead image, in any status — what MediaDeleter
     * has to detach before the file goes.
     *
     * @return list<Page>
     */
    public function findByFeaturedImage(Media $media): array
    {
        return array_values($this->findBy(['featuredImage' => $media]));
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
