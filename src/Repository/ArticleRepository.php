<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Article;
use App\Entity\Category;
use App\Entity\ContentStatus;
use App\Entity\Media;
use App\Entity\Tag;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Article>
 */
final class ArticleRepository extends ServiceEntityRepository implements SluggedRepository
{
    private const string ALIAS = 'article';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Article::class);
    }

    public function existsWithSlug(string $slug): bool
    {
        return null !== $this->findOneBy(['slug' => $slug]);
    }

    /**
     * Finds an article whatever its status. The administration area needs this;
     * public routes must use findOnePublishedBySlug() instead.
     */
    public function findOneBySlug(string $slug): ?Article
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    public function findOnePublishedBySlug(string $slug): ?Article
    {
        $result = $this->publishedQuery()
            ->andWhere(self::ALIAS.'.slug = :slug')
            ->setParameter('slug', $slug)
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof Article ? $result : null;
    }

    /**
     * @return list<Article> newest first
     */
    public function findPublished(int $limit = 20, int $offset = 0): array
    {
        return array_values(
            $this->publishedQuery()
                ->setMaxResults($limit)
                ->setFirstResult($offset)
                ->getQuery()
                ->getResult(),
        );
    }

    /**
     * @return list<Article> published articles in a section, newest first
     */
    public function findPublishedByCategory(Category $category, int $limit = 20, int $offset = 0): array
    {
        return array_values(
            $this->publishedQuery()
                ->andWhere(self::ALIAS.'.category = :category')
                ->setParameter('category', $category)
                ->setMaxResults($limit)
                ->setFirstResult($offset)
                ->getQuery()
                ->getResult(),
        );
    }

    /**
     * @return list<Article> published articles carrying a label, newest first
     */
    public function findPublishedByTag(Tag $tag, int $limit = 20, int $offset = 0): array
    {
        return array_values(
            $this->publishedQuery()
                ->innerJoin(self::ALIAS.'.tags', 'tag')
                ->andWhere('tag = :tag')
                ->setParameter('tag', $tag)
                ->setMaxResults($limit)
                ->setFirstResult($offset)
                ->getQuery()
                ->getResult(),
        );
    }

    /**
     * Every article in a section, in any status — what CategoryDeleter has to
     * detach before the section goes.
     *
     * @return list<Article>
     */
    public function findByCategory(Category $category): array
    {
        return array_values($this->findBy(['category' => $category]));
    }

    /**
     * Articles using a file as their lead image, in any status — what
     * MediaDeleter has to detach before the file goes.
     *
     * @return list<Article>
     */
    public function findByFeaturedImage(Media $media): array
    {
        return array_values($this->findBy(['featuredImage' => $media]));
    }

    public function countPublished(): int
    {
        return $this->count(['status' => ContentStatus::Published]);
    }

    /**
     * Counts every article the account authored, in any status. Archiving is not
     * a release of ownership, so archived articles are included — this is what
     * UserDeleter refuses on.
     */
    public function countByAuthor(User $author): int
    {
        return $this->count(['author' => $author]);
    }

    /**
     * The one place that decides what "published" means for articles.
     *
     * Every public method that claims to return published content routes through
     * here, so no caller has to reimplement the definition and the website and
     * the JSON API cannot disagree about it.
     *
     * Ordering is total: without the identifier as a tiebreak, two articles
     * published in the same second swap places between requests, and pagination
     * silently repeats or skips a row.
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
