<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Factory\ArticleFactory;
use App\Factory\MediaFactory;
use App\Factory\PageFactory;
use Doctrine\ORM\EntityManagerInterface;

use function sprintf;
use function str_repeat;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * What a shared link looks like before anybody clicks it.
 *
 * The tags are built in the base layout from the title and description a
 * template already declares, rather than passed down by each controller. That
 * choice is what this file checks: it walks every kind of public page, including
 * the ones nobody would think to add tags to, and expects the metadata to be
 * there because it could not have been forgotten.
 */
final class PreviewMetadataTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    /**
     * FR-012, on every kind of page there is.
     */
    public function testEveryPublicPageCarriesAPreview(): void
    {
        ArticleFactory::new()->published()->create(['slug' => 'an-article']);
        PageFactory::new()->published()->create(['slug' => 'a-page']);

        foreach (['/', '/articles/an-article', '/a-page'] as $address) {
            $crawler = $this->client->request('GET', $address);

            foreach (['og:title', 'og:description', 'og:url', 'og:site_name'] as $property) {
                self::assertNotSame(
                    '',
                    $this->property($crawler, $property),
                    sprintf('%s carries no %s.', $address, $property),
                );
            }
        }
    }

    public function testAnArticlesPreviewNamesTheArticle(): void
    {
        ArticleFactory::new()->published()->create([
            'slug' => 'an-article',
            'title' => 'A Distinctive Headline',
            'excerpt' => 'A short summary of what it says.',
        ]);

        $crawler = $this->client->request('GET', '/articles/an-article');

        self::assertStringContainsString('A Distinctive Headline', $this->property($crawler, 'og:title'));
        self::assertSame('A short summary of what it says.', $this->property($crawler, 'og:description'));
        self::assertSame('http://localhost/articles/an-article', $this->property($crawler, 'og:url'));
        self::assertSame('article', $this->property($crawler, 'og:type'));
    }

    /**
     * FR-013. Absolute, because whatever renders the preview is not this site.
     */
    public function testAnArticleWithALeadImageNamesItAbsolutely(): void
    {
        // A stored filename is generated, and the media route only accepts that
        // shape — see feature 005. A friendly name here would produce a routing
        // error rather than the missing tag this test is about.
        $image = MediaFactory::createOne([
            'filename' => str_repeat('a', 32).'.jpg',
            'altText' => 'Something to see',
        ]);
        $article = ArticleFactory::new()->published()->create(['slug' => 'an-article']);

        $article->setFeaturedImage($image);
        $this->flush();

        $crawler = $this->client->request('GET', '/articles/an-article');

        self::assertSame(
            'http://localhost/media/'.str_repeat('a', 32).'.jpg',
            $this->property($crawler, 'og:image'),
        );
        self::assertSame('summary_large_image', $this->named($crawler, 'twitter:card'));
    }

    /**
     * FR-013 from the other side. An image tag pointing at nothing renders as a
     * broken preview, which is worse than a preview with no image in it.
     */
    public function testAnArticleWithoutALeadImageNamesNoImageAtAll(): void
    {
        ArticleFactory::new()->published()->create(['slug' => 'an-article']);

        $crawler = $this->client->request('GET', '/articles/an-article');

        self::assertCount(0, $crawler->filter('meta[property="og:image"]'));
        self::assertSame('summary', $this->named($crawler, 'twitter:card'));
    }

    /**
     * FR-014. A description is an attribute value; markup in it is displayed
     * literally at best, and a newline makes it invalid.
     */
    public function testADescriptionBuiltFromABodyIsPlainAndShort(): void
    {
        ArticleFactory::new()->published()->create([
            'slug' => 'an-article',
            'excerpt' => null,
            'content' => '<p>A <strong>bold</strong> claim.</p>'."\n".'<p>'.str_repeat('More words. ', 100).'</p>',
        ]);

        $crawler = $this->client->request('GET', '/articles/an-article');
        $description = $this->property($crawler, 'og:description');

        self::assertStringStartsWith('A bold claim.', $description);
        self::assertStringNotContainsString('<', $description);
        self::assertStringNotContainsString("\n", $description);
        self::assertLessThan(200, mb_strlen($description));
    }

    /**
     * FR-015.
     */
    public function testEveryPageDeclaresItsCanonicalAddress(): void
    {
        ArticleFactory::new()->published()->create(['slug' => 'an-article']);

        foreach (['/' => 'http://localhost/', '/articles/an-article' => 'http://localhost/articles/an-article'] as $address => $expected) {
            $crawler = $this->client->request('GET', $address);

            self::assertSame($expected, $crawler->filter('link[rel="canonical"]')->attr('href'));
        }
    }

    /**
     * A decorated address is the same page, and saying so is what stops a
     * search engine treating each variation as its own.
     */
    public function testTrackingParametersDoNotChangeTheCanonicalAddress(): void
    {
        ArticleFactory::new()->published()->create(['slug' => 'an-article']);

        $crawler = $this->client->request('GET', '/articles/an-article?utm_source=a-newsletter&ref=somewhere');

        self::assertSame(
            'http://localhost/articles/an-article',
            $crawler->filter('link[rel="canonical"]')->attr('href'),
        );
    }

    /**
     * Page two of a listing is genuinely a different page. Declaring it
     * canonical to page one asks a search engine to forget everything past the
     * first twenty articles.
     */
    public function testPageTwoOfAListingIsCanonicalToItself(): void
    {
        ArticleFactory::new()->published()->many(25)->create();

        $crawler = $this->client->request('GET', '/?page=2');

        self::assertSame('http://localhost/?page=2', $crawler->filter('link[rel="canonical"]')->attr('href'));
    }

    private function property(Crawler $crawler, string $property): string
    {
        return (string) $crawler->filter('meta[property="'.$property.'"]')->attr('content');
    }

    private function named(Crawler $crawler, string $name): string
    {
        return (string) $crawler->filter('meta[name="'.$name.'"]')->attr('content');
    }

    private function flush(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->flush();
    }
}
