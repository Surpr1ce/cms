<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Factory\ArticleFactory;
use App\Factory\CategoryFactory;
use App\Factory\MediaFactory;
use App\Factory\PageFactory;
use App\Factory\TagFactory;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

use function sprintf;
use function str_repeat;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * What a reader can actually do on the site.
 *
 * Every assertion here is about something that was missing rather than wrong.
 * The site answered on every address for fifteen features and still gave a
 * reader nowhere to go at the end of an article, no way to find a section, no
 * picture in any listing, and no idea where they were.
 *
 * One rule governs all of it and is asserted its own test: **nothing suggested
 * to a reader may be something they cannot open.** A "read next" that offers a
 * draft is the same disclosure feature 002 spent itself preventing, arriving
 * through a new door.
 */
final class ReaderExperienceTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    // ------------------------------------------------------------- listings

    /**
     * Every article has a lead image and no listing showed one. A site about
     * publishing pictures and words was showing only the words.
     */
    public function testAListingShowsTheLeadImageAtTheSizeItDisplaysIt(): void
    {
        $image = MediaFactory::createOne([
            'filename' => str_repeat('a', 32).'.jpg',
            'altText' => 'Something to see',
        ]);
        $article = ArticleFactory::new()->published()->create(['slug' => 'an-article']);

        $article->setFeaturedImage($image);
        $this->flush();

        $crawler = $this->client->request('GET', '/');

        self::assertSame(
            '/media/thumbnail/'.str_repeat('a', 32).'.jpg',
            $crawler->filter('main article img')->attr('src'),
            'A listing sent something other than the thumbnail.',
        );
    }

    public function testAnArticleWithNoLeadImageStillRendersInAListing(): void
    {
        ArticleFactory::new()->published()->create(['title' => 'No picture here']);

        $crawler = $this->client->request('GET', '/');

        self::assertStringContainsString('No picture here', $crawler->filter('main')->text());
        self::assertCount(0, $crawler->filter('main article img'));
    }

    public function testAListingSaysHowLongEachArticleTakesToRead(): void
    {
        ArticleFactory::new()->published()->create(['content' => '<p>'.str_repeat('word ', 400).'</p>']);

        $crawler = $this->client->request('GET', '/');

        self::assertStringContainsString('min read', $crawler->filter('main article')->text());
    }

    // ------------------------------------------------------------ finding

    /**
     * A reader could reach a section only by noticing the small link under an
     * article's title. The site's own structure was invisible from the site.
     */
    public function testTheSectionsAreReachableFromEveryPage(): void
    {
        CategoryFactory::createOne(['name' => 'Long Reads', 'slug' => 'long-reads']);
        ArticleFactory::new()->published()->create(['slug' => 'an-article']);

        foreach (['/', '/articles/an-article', '/search?q=nothing'] as $address) {
            $crawler = $this->client->request('GET', $address);

            self::assertGreaterThan(
                0,
                $crawler->filter('header a[href="/sections/long-reads"]')->count(),
                sprintf('%s does not offer the sections.', $address),
            );
        }
    }

    public function testTheFooterOffersTheFeedAndTheWayIn(): void
    {
        $crawler = $this->client->request('GET', '/');

        self::assertGreaterThan(0, $crawler->filter('footer a[href="/feed.xml"]')->count());
        self::assertGreaterThan(0, $crawler->filter('footer a[href="/login"]')->count());
    }

    /**
     * Somebody arriving from a search engine lands three levels in with no idea
     * what the site is.
     */
    public function testEveryContentPageSaysWhereTheReaderIs(): void
    {
        $section = CategoryFactory::createOne(['name' => 'News', 'slug' => 'news']);
        ArticleFactory::new()->published()->create(['slug' => 'an-article', 'category' => $section]);
        PageFactory::new()->published()->create(['slug' => 'a-page']);
        TagFactory::createOne(['slug' => 'a-label']);

        foreach (['/articles/an-article', '/a-page', '/sections/news', '/topics/a-label', '/search?q=x'] as $address) {
            $crawler = $this->client->request('GET', $address);

            self::assertGreaterThan(
                0,
                $crawler->filter('nav[aria-label="Breadcrumb"]')->count(),
                sprintf('%s does not say where the reader is.', $address),
            );
        }
    }

    /**
     * The breadcrumb names the section, which is the only place above the fold
     * that does.
     */
    public function testAnArticlesTrailNamesItsSection(): void
    {
        $section = CategoryFactory::createOne(['name' => 'Long Reads', 'slug' => 'long-reads']);
        ArticleFactory::new()->published()->create(['slug' => 'an-article', 'category' => $section]);

        $crawler = $this->client->request('GET', '/articles/an-article');
        $trail = $crawler->filter('nav[aria-label="Breadcrumb"]');

        self::assertStringContainsString('Long Reads', $trail->text());
        self::assertGreaterThan(0, $trail->filter('a[href="/sections/long-reads"]')->count());
    }

    // ---------------------------------------------------------- read next

    public function testAnArticleOffersMoreLikeItself(): void
    {
        $section = CategoryFactory::createOne(['slug' => 'news']);

        ArticleFactory::new()->published()->create([
            'slug' => 'the-one-being-read',
            'category' => $section,
        ]);
        ArticleFactory::new()->published()->create([
            'title' => 'Also in this section',
            'category' => $section,
        ]);

        $crawler = $this->client->request('GET', '/articles/the-one-being-read');

        self::assertStringContainsString(
            'Also in this section',
            $crawler->filter('nav[aria-label="Read next"]')->text(),
        );
    }

    public function testAnArticleOffersTheOnesEitherSideOfItByDate(): void
    {
        $this->publishedAt('older', '-2 weeks');
        $this->publishedAt('the-middle-one', '-1 week');
        $this->publishedAt('newer', '-1 day');

        $crawler = $this->client->request('GET', '/articles/the-middle-one');
        $readNext = $crawler->filter('nav[aria-label="Read next"]');

        self::assertGreaterThan(0, $readNext->filter('a[href="/articles/older"]')->count());
        self::assertGreaterThan(0, $readNext->filter('a[href="/articles/newer"]')->count());
    }

    /**
     * The assertion this file exists for.
     *
     * A "read next" built from a query that returns anything is a way to
     * discover unpublished work through a door feature 002 never had to guard.
     */
    public function testNothingUnpublishedIsEverSuggested(): void
    {
        $section = CategoryFactory::createOne(['slug' => 'news']);
        $label = TagFactory::createOne(['slug' => 'a-label']);

        $article = ArticleFactory::new()->published()->create([
            'slug' => 'the-one-being-read',
            'category' => $section,
        ]);
        $article->addTag($label);

        // A draft and an archived article that share everything with it.
        $draft = ArticleFactory::createOne([
            'title' => 'A confidential draft',
            'category' => $section,
        ]);
        $draft->addTag($label);

        $archived = ArticleFactory::new()->publishedThenArchived()->create([
            'title' => 'An archived article',
            'category' => $section,
        ]);
        $archived->addTag($label);

        $this->flush();

        $body = $this->client->request('GET', '/articles/the-one-being-read')->text();

        self::assertStringNotContainsString('A confidential draft', $body);
        self::assertStringNotContainsString('An archived article', $body);
    }

    /**
     * An article related to nothing must offer nothing, rather than falling back
     * to whatever is most recent — a recommendation dressed up as a
     * relationship.
     */
    public function testAnArticleRelatedToNothingSuggestsNothing(): void
    {
        ArticleFactory::new()->published()->create([
            'slug' => 'entirely-alone',
            'category' => null,
        ]);
        ArticleFactory::new()->published()->create(['title' => 'Unrelated to it']);

        $crawler = $this->client->request('GET', '/articles/entirely-alone');

        // The "older / newer" half may still be there — that is the rest of the
        // site in order, not a claim about relatedness. What must be absent is
        // the claim.
        self::assertStringNotContainsString('More like this', $crawler->filter('main')->text());
        self::assertStringNotContainsString('Unrelated to it', $crawler->filter('main')->text());
    }

    // -------------------------------------------------------- and the rest

    public function testSearchSaysHowManyItFound(): void
    {
        ArticleFactory::new()->published()->create(['title' => 'A hippopotamus story']);

        $crawler = $this->client->request('GET', '/search?q=hippopotamus');

        self::assertStringContainsString('1 result', $crawler->filter('main')->text());
    }

    /**
     * Somebody using a keyboard would otherwise tab through the whole header on
     * every page before reaching what they came for.
     */
    public function testEveryPageOffersAWayPastTheHeader(): void
    {
        ArticleFactory::new()->published()->create(['slug' => 'an-article']);

        foreach (['/', '/articles/an-article'] as $address) {
            $crawler = $this->client->request('GET', $address);

            self::assertGreaterThan(
                0,
                $crawler->filter('a[href="#main"]')->count(),
                sprintf('%s has no skip link.', $address),
            );
            self::assertGreaterThan(0, $crawler->filter('#main')->count());
        }
    }

    private function publishedAt(string $slug, string $when): void
    {
        $article = ArticleFactory::createOne(['slug' => $slug, 'content' => '<p>Long enough.</p>']);
        $article->publish(new DateTimeImmutable($when));

        $this->flush();
    }

    private function flush(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $entityManager->flush();
    }
}
