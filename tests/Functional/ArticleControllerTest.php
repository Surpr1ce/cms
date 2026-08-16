<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Factory\ArticleFactory;
use App\Factory\CategoryFactory;
use App\Factory\MediaFactory;
use App\Factory\TagFactory;
use App\Factory\UserFactory;
use Doctrine\Persistence\ManagerRegistry;

use function sprintf;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Test\Factories;

final class ArticleControllerTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testAnAnonymousReaderCanOpenAPublishedArticle(): void
    {
        ArticleFactory::new()->published()->create(['slug' => 'a-published-article']);

        $this->client->request('GET', '/articles/a-published-article');

        self::assertResponseIsSuccessful();
    }

    public function testItShowsTheTitleDateAuthorAndBody(): void
    {
        ArticleFactory::new()->published()->create([
            'slug' => 'a-published-article',
            'title' => 'A Considered Title',
            'content' => '<p>The body of the article.</p>',
            'author' => UserFactory::createOne(['displayName' => 'Erin Editor']),
        ]);

        $crawler = $this->client->request('GET', '/articles/a-published-article');

        self::assertSame('A Considered Title', $crawler->filter('h1')->text());
        self::assertStringContainsString('The body of the article.', $crawler->filter('.prose')->text());
        self::assertStringContainsString('Erin Editor', $crawler->filter('main')->text());
        self::assertCount(1, $crawler->filter('time'));
    }

    /**
     * FR-024: markup the author wrote renders as markup, not as escaped text.
     * The risk this creates is stated in the specification's Assumptions and is
     * inherited by whichever feature first lets somebody paste markup in.
     */
    public function testTheBodyRendersAsMarkupRatherThanEscapedText(): void
    {
        ArticleFactory::new()->published()->create([
            'slug' => 'a-published-article',
            'content' => '<h2>A heading</h2><p>A paragraph with <strong>emphasis</strong>.</p>',
        ]);

        $crawler = $this->client->request('GET', '/articles/a-published-article');

        self::assertCount(1, $crawler->filter('.prose h2'));
        self::assertCount(1, $crawler->filter('.prose strong'));
    }

    public function testItLinksToItsSection(): void
    {
        ArticleFactory::new()->published()->create([
            'slug' => 'a-published-article',
            'category' => CategoryFactory::createOne(['name' => 'News', 'slug' => 'news']),
        ]);

        $crawler = $this->client->request('GET', '/articles/a-published-article');

        self::assertCount(1, $crawler->filter('a[href="/sections/news"]'));
    }

    public function testItLinksToEachOfItsLabels(): void
    {
        $article = ArticleFactory::new()->published()->create(['slug' => 'a-published-article']);
        $article->addTag(TagFactory::createOne(['name' => 'PHP', 'slug' => 'php']));
        $article->addTag(TagFactory::createOne(['name' => 'Symfony', 'slug' => 'symfony']));
        $this->flush();

        $crawler = $this->client->request('GET', '/articles/a-published-article');

        self::assertCount(1, $crawler->filter('a[href="/topics/php"]'));
        self::assertCount(1, $crawler->filter('a[href="/topics/symfony"]'));
    }

    public function testAnArticleWithNoSectionOrLabelsStillRenders(): void
    {
        ArticleFactory::new()->published()->create(['slug' => 'bare', 'category' => null]);

        $this->client->request('GET', '/articles/bare');

        self::assertResponseIsSuccessful();
    }

    /**
     * FR-009: the alternative text travels with the image, or a reader who
     * cannot see it gets nothing.
     */
    public function testALeadImageIsRenderedWithItsAlternativeText(): void
    {
        ArticleFactory::new()->published()->create([
            'slug' => 'illustrated',
            'featuredImage' => MediaFactory::createOne(['altText' => 'A cat asleep on a keyboard.']),
        ]);

        $crawler = $this->client->request('GET', '/articles/illustrated');

        self::assertSame('A cat asleep on a keyboard.', $crawler->filter('main img')->attr('alt'));
    }

    public function testAnArticleWithNoLeadImageRendersNoImage(): void
    {
        ArticleFactory::new()->published()->create(['slug' => 'plain', 'featuredImage' => null]);

        $crawler = $this->client->request('GET', '/articles/plain');

        self::assertCount(0, $crawler->filter('main img'));
    }

    /**
     * FR-023. The file the catalogue names may not exist on disk — the upload
     * feature does not exist yet — and the article is not the image.
     */
    public function testAnArticleRendersEvenThoughTheImageFileIsNotOnDisk(): void
    {
        ArticleFactory::new()->published()->create([
            'slug' => 'illustrated',
            'title' => 'Still readable',
            'featuredImage' => MediaFactory::createOne(),
        ]);

        $crawler = $this->client->request('GET', '/articles/illustrated');

        self::assertResponseIsSuccessful();
        self::assertSame('Still readable', $crawler->filter('h1')->text());
    }

    public function testAnAddressThatMatchesNothingIsNotFound(): void
    {
        $this->client->request('GET', '/articles/never-written');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /**
     * The router refuses a shape no slug can have, so the controller is never
     * reached with one.
     */
    public function testAnAddressOfAShapeNoSlugCanHaveIsNotFound(): void
    {
        foreach (['Upper-Case', 'has_underscore', '-leading', 'trailing-', 'double--hyphen'] as $malformed) {
            $this->client->request('GET', '/articles/'.$malformed);

            self::assertResponseStatusCodeSame(
                Response::HTTP_NOT_FOUND,
                sprintf('"%s" should not have matched the article route.', $malformed),
            );
        }
    }

    private function flush(): void
    {
        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);

        $registry->getManager()->flush();
    }
}
