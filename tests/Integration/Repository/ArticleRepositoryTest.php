<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Article;
use App\Factory\ArticleFactory;
use App\Factory\UserFactory;
use App\Repository\ArticleRepository;
use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The published scope, which is the reason this repository exists.
 *
 * FR-031 says no caller should have to reimplement "visible". These tests are
 * what makes that claim checkable: if the scope ever stops being applied to one
 * of the methods, one of them goes red.
 */
final class ArticleRepositoryTest extends KernelTestCase
{
    use Factories;

    private ArticleRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();

        $repository = self::getContainer()->get(ArticleRepository::class);
        self::assertInstanceOf(ArticleRepository::class, $repository);

        $this->repository = $repository;
    }

    public function testItFindsAnArticleBySlugWhateverItsStatus(): void
    {
        ArticleFactory::createOne(['slug' => 'a-draft']);

        self::assertInstanceOf(Article::class, $this->repository->findOneBySlug('a-draft'));
    }

    public function testItReturnsNullForAnUnknownSlug(): void
    {
        self::assertNull($this->repository->findOneBySlug('never-written'));
    }

    /**
     * The distinction the whole scope exists for: the administration area may
     * reach a draft by its address, a public route may not.
     */
    public function testADraftIsReachableBySlugButNotAsPublishedContent(): void
    {
        ArticleFactory::createOne(['slug' => 'a-draft']);

        self::assertNotNull($this->repository->findOneBySlug('a-draft'));
        self::assertNull($this->repository->findOnePublishedBySlug('a-draft'));
    }

    public function testPublishedContentIsReachableBothWays(): void
    {
        ArticleFactory::new()->published()->create(['slug' => 'a-published-article']);

        self::assertNotNull($this->repository->findOneBySlug('a-published-article'));
        self::assertNotNull($this->repository->findOnePublishedBySlug('a-published-article'));
    }

    public function testArchivedContentIsNotReachableAsPublishedContent(): void
    {
        ArticleFactory::new()->publishedThenArchived()->create(['slug' => 'an-archived-article']);

        self::assertNull($this->repository->findOnePublishedBySlug('an-archived-article'));
    }

    /**
     * SC-003: content that has never been published never appears in a result
     * set restricted to published content.
     */
    public function testTheListingExcludesDraftsAndArchivedContent(): void
    {
        ArticleFactory::createMany(3);
        ArticleFactory::new()->publishedThenArchived()->many(2)->create();
        ArticleFactory::new()->published()->many(4)->create();

        $listed = $this->repository->findPublished();

        self::assertCount(4, $listed);

        foreach ($listed as $article) {
            self::assertTrue($article->isPublished());
        }
    }

    public function testTheListingIsNewestFirst(): void
    {
        $older = ArticleFactory::createOne(['slug' => 'older', 'content' => 'Body.']);
        $newer = ArticleFactory::createOne(['slug' => 'newer', 'content' => 'Body.']);

        $older->publish(new DateTimeImmutable('2026-01-01 10:00:00'));
        $newer->publish(new DateTimeImmutable('2026-06-01 10:00:00'));
        $this->flush();

        $listed = $this->repository->findPublished();

        self::assertSame('newer', $listed[0]->getSlug());
        self::assertSame('older', $listed[1]->getSlug());
    }

    /**
     * Without the identifier as a tiebreak, two articles published in the same
     * second swap places between requests and pagination repeats or skips a row.
     */
    public function testOrderingIsTotalWhenTwoArticlesSharePublicationTime(): void
    {
        $moment = new DateTimeImmutable('2026-06-01 10:00:00');

        foreach (['first', 'second', 'third'] as $slug) {
            $article = ArticleFactory::createOne(['slug' => $slug, 'content' => 'Body.']);
            $article->publish($moment);
        }

        $this->flush();

        self::assertSame(
            array_map(static fn (Article $a): string => $a->getSlug(), $this->repository->findPublished()),
            array_map(static fn (Article $a): string => $a->getSlug(), $this->repository->findPublished()),
        );
    }

    public function testTheListingPaginates(): void
    {
        ArticleFactory::new()->published()->many(5)->create();

        self::assertCount(2, $this->repository->findPublished(limit: 2));
        self::assertCount(3, $this->repository->findPublished(limit: 10, offset: 2));
    }

    public function testItCountsOnlyPublishedArticles(): void
    {
        ArticleFactory::createMany(2);
        ArticleFactory::new()->published()->many(3)->create();
        ArticleFactory::new()->publishedThenArchived()->many(1)->create();

        self::assertSame(3, $this->repository->countPublished());
    }

    /**
     * Ownership for the purposes of FR-028: every status counts, because
     * archiving is not a release of ownership.
     */
    public function testItCountsEveryArticleAnAccountAuthoredWhateverItsStatus(): void
    {
        $author = UserFactory::createOne();
        ArticleFactory::createMany(2, ['author' => $author]);
        ArticleFactory::new()->published()->many(1)->create(['author' => $author]);
        ArticleFactory::new()->publishedThenArchived()->many(1)->create(['author' => $author]);
        ArticleFactory::createMany(3);

        self::assertSame(4, $this->repository->countByAuthor($author));
    }

    public function testItReportsWhetherASlugIsTaken(): void
    {
        ArticleFactory::createOne(['slug' => 'taken']);

        self::assertTrue($this->repository->existsWithSlug('taken'));
        self::assertFalse($this->repository->existsWithSlug('free'));
    }

    private function flush(): void
    {
        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);

        $registry->getManager()->flush();
    }
}
