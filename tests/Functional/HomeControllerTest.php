<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Factory\ArticleFactory;
use App\Factory\UserFactory;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Doctrine\Persistence\ManagerRegistry;

use function sprintf;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Zenstruck\Foundry\Test\Factories;

final class HomeControllerTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    /**
     * The anonymous-reader case that docs/testing.md requires of every route.
     * Nothing on the public site asks who is reading, so this *is* the case.
     */
    public function testAnAnonymousReaderCanOpenTheFrontPage(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
    }

    public function testItListsPublishedArticles(): void
    {
        ArticleFactory::new()->published()->many(3)->create();

        $crawler = $this->client->request('GET', '/');

        self::assertCount(3, $crawler->filter('article'));
    }

    public function testItShowsTheTitleDateAuthorAndSummaryOfEachArticle(): void
    {
        ArticleFactory::new()->published()->create([
            'title' => 'A Considered Title',
            'excerpt' => 'A short summary of the article.',
            'author' => UserFactory::createOne(['displayName' => 'Erin Editor']),
        ]);

        $crawler = $this->client->request('GET', '/');
        $card = $crawler->filter('article')->first();

        self::assertStringContainsString('A Considered Title', $card->text());
        self::assertStringContainsString('A short summary of the article.', $card->text());
        self::assertStringContainsString('Erin Editor', $card->text());
        self::assertCount(1, $card->filter('time'));
    }

    public function testNewestArticlesComeFirst(): void
    {
        $older = ArticleFactory::createOne(['slug' => 'older', 'content' => 'Body.']);
        $newer = ArticleFactory::createOne(['slug' => 'newer', 'content' => 'Body.']);
        $older->publish(new DateTimeImmutable('2026-01-01 10:00:00'));
        $newer->publish(new DateTimeImmutable('2026-06-01 10:00:00'));
        $this->flush();

        $crawler = $this->client->request('GET', '/');
        $links = $crawler->filter('article h2 a')->extract(['href']);

        self::assertSame('/articles/newer', $links[0]);
        self::assertSame('/articles/older', $links[1]);
    }

    /**
     * US1 scenario 4: the summary is optional, so the markup collapses rather
     * than leaving a gap.
     */
    public function testAnArticleWithNoSummaryStillRendersInTheListing(): void
    {
        ArticleFactory::new()->published()->create(['title' => 'No summary here', 'excerpt' => null]);

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('No summary here', $crawler->filter('article')->text());
    }

    public function testAnEmptySiteSaysSoRatherThanShowingABlankPage(): void
    {
        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Nothing has been published yet.', $crawler->filter('main')->text());
    }

    public function testEachArticleLinksToItsOwnAddress(): void
    {
        ArticleFactory::new()->published()->create(['slug' => 'a-published-article']);

        $crawler = $this->client->request('GET', '/');

        self::assertSame('/articles/a-published-article', $crawler->filter('article h2 a')->attr('href'));
    }

    public function testAFullFirstPageOffersTheNextOne(): void
    {
        ArticleFactory::new()->published()->many(21)->create();

        $crawler = $this->client->request('GET', '/');

        self::assertCount(20, $crawler->filter('article'));
        self::assertCount(1, $crawler->filter('a[rel="next"]'));
        self::assertCount(0, $crawler->filter('a[rel="prev"]'));
    }

    public function testTheLastPageOffersNoFurtherPage(): void
    {
        ArticleFactory::new()->published()->many(21)->create();

        $crawler = $this->client->request('GET', '/?page=2');

        self::assertCount(1, $crawler->filter('article'));
        self::assertCount(0, $crawler->filter('a[rel="next"]'));
        self::assertCount(1, $crawler->filter('a[rel="prev"]'));
    }

    public function testASingleShortPageOffersNoNavigationAtAll(): void
    {
        ArticleFactory::new()->published()->many(3)->create();

        $crawler = $this->client->request('GET', '/');

        self::assertCount(0, $crawler->filter('nav[aria-label="Pagination"]'));
    }

    public function testAPageBeyondTheEndIsEmptyRatherThanAnError(): void
    {
        ArticleFactory::new()->published()->many(3)->create();

        $crawler = $this->client->request('GET', '/?page=99');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('article'));
    }

    /**
     * FR-022. A reader who edits the URL by hand gets the first page, not an
     * error and not a query with a negative offset.
     */
    public function testAnInvalidPageNumberFallsBackToTheFirstPage(): void
    {
        ArticleFactory::new()->published()->many(3)->create();

        foreach (['abc', '0', '-5', '', '1;DROP TABLE article'] as $bad) {
            $crawler = $this->client->request('GET', '/?page='.urlencode($bad));

            self::assertResponseIsSuccessful(sprintf('page=%s should fall back to page 1', $bad));
            self::assertCount(3, $crawler->filter('article'));
        }
    }

    /**
     * SC-007: the query count must not grow with the number of items on a page.
     *
     * The two counts are compared rather than checked against a fixed number,
     * because a fixed number becomes a chore that gets bumped whenever anything
     * changes. What matters is that it does not *grow*.
     */
    public function testAListingIssuesTheSameNumberOfQueriesWhateverItsSize(): void
    {
        ArticleFactory::new()->published()->many(2)->create();

        // A warm-up request first, unmeasured. The very first request of a test
        // process pays for metadata loading and container work that has nothing
        // to do with the listing — the first version of this test compared a
        // cold request against a warm one and reported an N+1 that was not
        // there, in the wrong direction.
        $this->client->request('GET', '/');

        $this->client->enableProfiler();
        $this->client->request('GET', '/');

        $small = $this->queryCount();

        ArticleFactory::new()->published()->many(15)->create();

        $this->client->enableProfiler();
        $this->client->request('GET', '/');

        $large = $this->queryCount();

        self::assertSame(
            $small,
            $large,
            sprintf('2 articles took %d queries, 17 took %d — the listing has an N+1.', $small, $large),
        );
    }

    private function flush(): void
    {
        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);

        $registry->getManager()->flush();
    }

    private function queryCount(): int
    {
        $profile = $this->client->getProfile();
        self::assertInstanceOf(Profile::class, $profile, 'The profiler collected nothing.');

        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);

        return $collector->getQueryCount();
    }
}
