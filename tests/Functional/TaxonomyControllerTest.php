<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Factory\ArticleFactory;
use App\Factory\CategoryFactory;
use App\Factory\TagFactory;
use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Test\Factories;

/**
 * Section and label listings. They are one test class because they answer the
 * same reader question — "more like this" — and differ only in what "like this"
 * means.
 */
final class TaxonomyControllerTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testAnAnonymousReaderCanOpenASectionListing(): void
    {
        CategoryFactory::createOne(['slug' => 'news']);

        $this->client->request('GET', '/sections/news');

        self::assertResponseIsSuccessful();
    }

    public function testAnAnonymousReaderCanOpenALabelListing(): void
    {
        TagFactory::createOne(['slug' => 'php']);

        $this->client->request('GET', '/topics/php');

        self::assertResponseIsSuccessful();
    }

    public function testASectionShowsItsNameAndDescription(): void
    {
        CategoryFactory::createOne([
            'slug' => 'news',
            'name' => 'News',
            'description' => 'What has been happening.',
        ]);

        $crawler = $this->client->request('GET', '/sections/news');

        self::assertSame('News', $crawler->filter('h1')->text());
        self::assertStringContainsString('What has been happening.', $crawler->filter('main')->text());
    }

    public function testASectionWithNoDescriptionStillRenders(): void
    {
        CategoryFactory::createOne(['slug' => 'news', 'description' => null]);

        $this->client->request('GET', '/sections/news');

        self::assertResponseIsSuccessful();
    }

    public function testASectionListsItsPublishedArticlesNewestFirst(): void
    {
        $section = CategoryFactory::createOne(['slug' => 'news']);

        $older = ArticleFactory::createOne(['slug' => 'older', 'category' => $section, 'content' => 'Body.']);
        $newer = ArticleFactory::createOne(['slug' => 'newer', 'category' => $section, 'content' => 'Body.']);
        $older->publish(new DateTimeImmutable('2026-01-01 10:00:00'));
        $newer->publish(new DateTimeImmutable('2026-06-01 10:00:00'));
        $this->flush();

        $crawler = $this->client->request('GET', '/sections/news');
        $links = $crawler->filter('article h2 a')->extract(['href']);

        self::assertSame(['/articles/newer', '/articles/older'], $links);
    }

    public function testASectionDoesNotListArticlesFromAnotherSection(): void
    {
        $section = CategoryFactory::createOne(['slug' => 'news']);
        ArticleFactory::new()->published()->many(2)->create(['category' => $section]);
        ArticleFactory::new()->published()->many(3)->create();

        $crawler = $this->client->request('GET', '/sections/news');

        self::assertCount(2, $crawler->filter('article'));
    }

    /**
     * US3 scenario 5: subsections are shown so a reader can go deeper.
     */
    public function testASectionShowsItsSubsections(): void
    {
        $parent = CategoryFactory::createOne(['slug' => 'news']);
        CategoryFactory::new()->childOf($parent)->create(['slug' => 'releases', 'name' => 'Releases']);

        $crawler = $this->client->request('GET', '/sections/news');

        // Scoped to main: the site navigation links to every section and
        // subsection too, which is correct and is a different assertion.
        self::assertCount(1, $crawler->filter('main a[href="/sections/releases"]'));
    }

    /**
     * FR-015. The section exists; it just has nothing a reader may see.
     */
    public function testAnEmptySectionRendersAsEmptyRatherThanNotFound(): void
    {
        CategoryFactory::createOne(['slug' => 'news']);

        $crawler = $this->client->request('GET', '/sections/news');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Nothing has been published', $crawler->filter('main')->text());
    }

    public function testAnEmptyLabelRendersAsEmptyRatherThanNotFound(): void
    {
        TagFactory::createOne(['slug' => 'php']);

        $crawler = $this->client->request('GET', '/topics/php');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Nothing published', $crawler->filter('main')->text());
    }

    public function testALabelListsThePublishedArticlesCarryingIt(): void
    {
        $tag = TagFactory::createOne(['slug' => 'php', 'name' => 'PHP']);

        foreach (ArticleFactory::new()->published()->many(2)->create() as $article) {
            $article->addTag($tag);
        }

        ArticleFactory::new()->published()->create();
        $this->flush();

        $crawler = $this->client->request('GET', '/topics/php');

        self::assertSame('PHP', $crawler->filter('h1')->text());
        self::assertCount(2, $crawler->filter('article'));
    }

    public function testASectionThatDoesNotExistIsNotFound(): void
    {
        $this->client->request('GET', '/sections/never-created');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testALabelThatDoesNotExistIsNotFound(): void
    {
        $this->client->request('GET', '/topics/never-created');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testASectionListingPaginates(): void
    {
        $section = CategoryFactory::createOne(['slug' => 'news']);
        ArticleFactory::new()->published()->many(21)->create(['category' => $section]);

        $crawler = $this->client->request('GET', '/sections/news');
        self::assertCount(20, $crawler->filter('article'));
        self::assertSame('/sections/news?page=2', $crawler->filter('a[rel="next"]')->attr('href'));

        $crawler = $this->client->request('GET', '/sections/news?page=2');
        self::assertCount(1, $crawler->filter('article'));
    }

    public function testALabelListingPaginates(): void
    {
        $tag = TagFactory::createOne(['slug' => 'php']);
        foreach (ArticleFactory::new()->published()->many(21)->create() as $article) {
            $article->addTag($tag);
        }

        $this->flush();

        $crawler = $this->client->request('GET', '/topics/php');
        self::assertCount(20, $crawler->filter('article'));
        self::assertSame('/topics/php?page=2', $crawler->filter('a[rel="next"]')->attr('href'));
    }

    private function flush(): void
    {
        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);

        $registry->getManager()->flush();
    }
}
