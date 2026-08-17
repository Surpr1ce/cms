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

    /**
     * Finds a label whatever carries it. The administration screens need this;
     * public routes must use findOneInUseBySlug() instead.
     */
    public function findOneBySlug(string $slug): ?Tag
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /**
     * The public counterpart: a label only exists here if a published article
     * carries it.
     *
     * findInUse() already keeps the tag cloud and the sitemap from naming the
     * subjects of unfinished drafts, but the address of a single label answered
     * for any label in the table — so /topics/redundancy-consultation confirmed
     * that somebody is drafting about it, which is the whole thing the published
     * scope exists to prevent. An audit found the JSON endpoint doing it; the
     * website was doing the same.
     *
     * setMaxResults(1) rather than DISTINCT: several published articles may carry
     * the label, and all that is being asked is whether at least one does.
     */
    public function findOneInUseBySlug(string $slug): ?Tag
    {
        $tag = $this->createQueryBuilder('tag')
            ->innerJoin(Article::class, 'article', Join::ON, 'tag MEMBER OF article.tags')
            ->andWhere('tag.slug = :slug')
            ->andWhere('article.status = :status')
            ->setParameter('slug', $slug)
            ->setParameter('status', ContentStatus::Published)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $tag instanceof Tag ? $tag : null;
    }

    /**
     * Labels carried by at least one *published* article.
     *
     * A tag cloud built from every label in the table advertises drafts and
     * archived content by name and leads readers to pages they cannot see. The
     * published scope therefore reaches into this query too.
     *
     * The optional limit is for the sitemap, which has a fixed number of
     * addresses to spend and spends whatever the articles and pages left over
     * here. A tag cloud asks for all of them.
     *
     * @return list<Tag>
     */
    public function findInUse(?int $limit = null): array
    {
        return array_values(
            $this->createQueryBuilder('tag')
                ->distinct()
                ->innerJoin(Article::class, 'article', Join::ON, 'tag MEMBER OF article.tags')
                ->andWhere('article.status = :status')
                ->setParameter('status', ContentStatus::Published)
                ->orderBy('tag.name', 'ASC')
                ->setMaxResults($limit)
                ->getQuery()
                ->getResult(),
        );
    }

    /**
     * One page of labels for the administration screen.
     *
     * Ordered by name and then by identifier: without the tiebreak, two labels
     * with the same name would swap places between requests and pagination would
     * silently repeat or skip one — the same reasoning as the published listings.
     *
     * @return list<Tag>
     */
    public function findPage(int $limit, int $offset): array
    {
        return array_values(
            $this->createQueryBuilder('tag')
                ->orderBy('tag.name', 'ASC')
                ->addOrderBy('tag.id', 'ASC')
                ->setMaxResults($limit)
                ->setFirstResult($offset)
                ->getQuery()
                ->getResult(),
        );
    }
}
