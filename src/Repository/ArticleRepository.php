<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Article;
use App\Entity\Category;
use App\Entity\ContentStatus;
use App\Entity\Media;
use App\Entity\Tag;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

use function in_array;

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
     * Just the two columns a sitemap entry is made of, newest first.
     *
     * Not a micro-optimisation. The document may hold fifty thousand addresses,
     * and `findPublished()` would hydrate fifty thousand managed articles —
     * every body, every excerpt, each with its original-data snapshot in the
     * identity map — to print a slug and a date. On an unauthenticated route
     * anybody may request as often as they like, that is the difference between
     * a large response and a dead worker. The security pass before the release
     * raised it, correctly, as this feature having *widened* the old ten-thousand
     * cap rather than only having bounded what was unbounded.
     *
     * `getArrayResult()` rather than partial entities: a partial object still
     * enters the identity map and still pretends to be an article.
     *
     * @return list<array{slug: string, updatedAt: DateTimeImmutable}> newest first
     */
    public function findPublishedAddresses(int $limit): array
    {
        /** @var list<array{slug: string, updatedAt: DateTimeImmutable}> $rows */
        $rows = $this->publishedQuery()
            ->select(self::ALIAS.'.slug', self::ALIAS.'.updatedAt')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return $rows;
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
     * A page of published articles with everything a listing renders already
     * loaded.
     *
     * The author and the section are join-fetched, so the number of queries does
     * not grow with the number of articles on the page (SC-007). Labels are not,
     * because a listing does not show them — join-fetching a to-many association
     * alongside a limit is also how a paginated query quietly starts returning
     * the wrong number of rows.
     *
     * @return list<Article> newest first
     */
    public function findPublishedPage(int $limit, int $offset): array
    {
        return array_values(
            $this->publishedQuery()
                ->addSelect('author', 'category')
                ->innerJoin(self::ALIAS.'.author', 'author')
                ->leftJoin(self::ALIAS.'.category', 'category')
                ->setMaxResults($limit)
                ->setFirstResult($offset)
                ->getQuery()
                ->getResult(),
        );
    }

    /**
     * @return list<Article> newest first
     */
    public function findPublishedPageByCategory(Category $category, int $limit, int $offset): array
    {
        return array_values(
            $this->publishedQuery()
                ->addSelect('author', 'category')
                ->innerJoin(self::ALIAS.'.author', 'author')
                ->leftJoin(self::ALIAS.'.category', 'category')
                ->andWhere(self::ALIAS.'.category = :category')
                ->setParameter('category', $category)
                ->setMaxResults($limit)
                ->setFirstResult($offset)
                ->getQuery()
                ->getResult(),
        );
    }

    /**
     * @return list<Article> newest first
     */
    public function findPublishedPageByTag(Tag $tag, int $limit, int $offset): array
    {
        return array_values(
            $this->publishedQuery()
                ->addSelect('author', 'category')
                ->innerJoin(self::ALIAS.'.author', 'author')
                ->leftJoin(self::ALIAS.'.category', 'category')
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
     * Published articles a reader who just finished this one might want next.
     *
     * Same section, or sharing at least one label. Ordered by how much they
     * share, then by recency — an article in the same section *and* carrying two
     * of the same labels comes before one that merely shares a section.
     *
     * Through `publishedQuery()` like everything else here, so a draft cannot be
     * recommended even by accident. A reader arriving at an article they may see
     * must not be handed a link to one they may not.
     *
     * @return list<Article>
     */
    public function findPublishedRelatedTo(Article $article, int $limit = 3): array
    {
        $labelIds = [];

        foreach ($article->getTags() as $tag) {
            $labelIds[] = $tag->getId();
        }

        $section = $article->getCategory();

        // Nothing to be related by. Returning the most recent articles instead
        // would be a recommendation dressed up as a relationship.
        if (!$section instanceof Category && [] === $labelIds) {
            return [];
        }

        // Nothing is join-fetched here, unlike the listing queries. Counting
        // shared labels needs a GROUP BY, and PostgreSQL will not let a query
        // select every column of a joined author and section while grouping by
        // the article — grouping by an article's primary key covers that
        // article's own columns and nothing else. The list this feeds shows a
        // title, a date and a reading time, so there is nothing else to fetch.
        $query = $this->publishedQuery()
            ->leftJoin(self::ALIAS.'.tags', 'tag')
            ->andWhere(self::ALIAS.'.id != :self')
            ->setParameter('self', $article->getId())
            ->groupBy(self::ALIAS.'.id')
            ->setMaxResults($limit);

        $conditions = [];

        if ($section instanceof Category) {
            $conditions[] = self::ALIAS.'.category = :section';
            $query->setParameter('section', $section);
        }

        if ([] !== $labelIds) {
            $conditions[] = 'tag.id IN (:labels)';
            $query->setParameter('labels', $labelIds);
        }

        $query->andWhere($query->expr()->orX(...$conditions));

        // Most shared labels first. `publishedQuery()` has already ordered by
        // date, and this puts relevance ahead of it while keeping the date as
        // the tiebreak.
        $query
            ->addSelect('COUNT(tag.id) AS HIDDEN shared')
            ->orderBy('shared', 'DESC')
            ->addOrderBy(self::ALIAS.'.publishedAt', 'DESC')
            ->addOrderBy(self::ALIAS.'.id', 'DESC');

        return array_values($query->getQuery()->getResult());
    }

    /**
     * The published articles either side of this one by publication date.
     *
     * For the "what next" controls at the foot of an article. Two queries rather
     * than a window function, because two indexed lookups of one row each are
     * cheaper than ranking the table and are the same in every database.
     *
     * @return array{previous: Article|null, next: Article|null} previous is
     *                                                           older, next is newer — the direction a reader means, not the direction
     *                                                           the list is sorted in
     */
    public function findPublishedNeighboursOf(Article $article): array
    {
        return [
            'previous' => $this->neighbour($article, '<', 'DESC'),
            'next' => $this->neighbour($article, '>', 'ASC'),
        ];
    }

    /**
     * The article behind a public address, with its section, labels and lead
     * image loaded.
     *
     * A separate method from findOnePublishedBySlug() because a page rendering
     * one article wants everything, where a caller checking existence does not.
     */
    public function findOnePublishedBySlugWithRelations(string $slug): ?Article
    {
        $result = $this->publishedQuery()
            ->addSelect('author', 'category', 'tags', 'featuredImage')
            ->innerJoin(self::ALIAS.'.author', 'author')
            ->leftJoin(self::ALIAS.'.category', 'category')
            ->leftJoin(self::ALIAS.'.tags', 'tags')
            ->leftJoin(self::ALIAS.'.featuredImage', 'featuredImage')
            ->andWhere(self::ALIAS.'.slug = :slug')
            ->setParameter('slug', $slug)
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof Article ? $result : null;
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
     * A page of the articles a particular person may see, newest first.
     *
     * **This is `ArticleVoter::canView()` written as a query, and the two must
     * not drift.** The administration list used to load the whole table and ask
     * the voter about every row, which was correct and unbounded; a listing
     * cannot be cut into pages while it is filtered afterwards, because twenty
     * fetched rows would show as six.
     *
     * The rule, from the voter: anybody may see published work, because that is
     * what published means. Anything else is visible to the editorial roles, and
     * to its own author — but only while that author still holds `ROLE_AUTHOR`,
     * since an account whose role was revoked still owns everything it wrote and
     * must not keep the permissions that came with it.
     *
     * `ArticleVisibilityMatchesTheVoterTest` runs this and the voter over the
     * same articles for every combination of roles and ownership and asserts the
     * two answers are identical. That test is the reason this duplication is
     * safe; without it, the query would quietly become the real rule.
     *
     * The author and the section are fetched with the article because the screen
     * shows both on every row, and a lazy association there is a query per row.
     *
     * @return list<Article> newest first
     */
    public function findPageForViewer(User $viewer, int $limit, int $offset): array
    {
        $query = $this->createQueryBuilder(self::ALIAS)
            ->addSelect('author', 'category')
            ->innerJoin(self::ALIAS.'.author', 'author')
            ->leftJoin(self::ALIAS.'.category', 'category')
            ->orderBy(self::ALIAS.'.createdAt', 'DESC')
            ->addOrderBy(self::ALIAS.'.id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        $roles = $viewer->getRoles();
        $isEditorial = in_array(User::ROLE_EDITOR, $roles, true)
            || in_array(User::ROLE_ADMIN, $roles, true);

        if (!$isEditorial) {
            if (in_array(User::ROLE_AUTHOR, $roles, true)) {
                $query
                    ->andWhere($query->expr()->orX(
                        self::ALIAS.'.status = :published',
                        self::ALIAS.'.author = :viewer',
                    ))
                    ->setParameter('viewer', $viewer);
            } else {
                // Published work and nothing else — including their own drafts,
                // if they wrote any before their role was taken away. Ownership
                // without the author role grants nothing, which is what
                // ArticleVoter::isOwningAuthor says and the reason it says it.
                $query->andWhere(self::ALIAS.'.status = :published');
            }

            $query->setParameter('published', ContentStatus::Published);
        }

        return array_values($query->getQuery()->getResult());
    }

    private function neighbour(Article $article, string $comparison, string $direction): ?Article
    {
        $publishedAt = $article->getPublishedAt();

        if (!$publishedAt instanceof DateTimeImmutable) {
            return null;
        }

        $result = $this->publishedQuery()
            ->andWhere(self::ALIAS.'.publishedAt '.$comparison.' :at')
            ->setParameter('at', $publishedAt)
            ->orderBy(self::ALIAS.'.publishedAt', $direction)
            ->addOrderBy(self::ALIAS.'.id', $direction)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof Article ? $result : null;
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
