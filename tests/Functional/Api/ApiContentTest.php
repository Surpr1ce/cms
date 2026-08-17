<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Factory\ArticleFactory;
use App\Factory\CategoryFactory;
use App\Factory\MediaFactory;
use App\Factory\PageFactory;
use App\Factory\TagFactory;
use App\Factory\UserFactory;
use DateTimeImmutable;

use const JSON_THROW_ON_ERROR;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * What a consumer actually receives — user stories 1 and 3.
 *
 * The visibility and read-only rules have their own files, because those are the
 * two that must not be got wrong. This one is about whether the API is useful:
 * enough in a listing to render one, enough in an item to render the article,
 * and the taxonomy needed to move between them.
 */
final class ApiContentTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testTheCollectionCarriesEnoughToRenderAListing(): void
    {
        ArticleFactory::new()->published()->create([
            'slug' => 'an-article',
            'title' => 'A Considered Title',
            'excerpt' => 'A short summary.',
            'author' => UserFactory::createOne(['displayName' => 'Erin Editor']),
        ]);

        $article = $this->json('/api/articles')[0];

        self::assertSame('an-article', $article['slug']);
        self::assertSame('A Considered Title', $article['title']);
        self::assertSame('A short summary.', $article['summary']);
        self::assertSame('Erin Editor', $article['author']);
        self::assertNotNull($article['publishedAt']);
    }

    public function testAnItemCarriesTheBodySectionAndLabels(): void
    {
        $article = ArticleFactory::new()->published()->create([
            'slug' => 'an-article',
            'content' => '<p>The body of the article.</p>',
            'category' => CategoryFactory::createOne(['slug' => 'news', 'name' => 'News']),
        ]);
        $article->addTag(TagFactory::createOne(['slug' => 'php', 'name' => 'PHP']));
        $article->addTag(TagFactory::createOne(['slug' => 'symfony', 'name' => 'Symfony']));
        $this->flush();

        $body = $this->jsonItem('/api/articles/an-article');

        self::assertSame('<p>The body of the article.</p>', $body['body']);
        self::assertSame('news', $body['section']);
        self::assertEqualsCanonicalizing(['php', 'symfony'], $body['tags']);
    }

    public function testAnArticleWithNoSectionOrLabelsIsStillComplete(): void
    {
        ArticleFactory::new()->published()->create(['slug' => 'bare', 'category' => null]);

        $body = $this->jsonItem('/api/articles/bare');

        self::assertNull($body['section']);
        self::assertSame([], $body['tags']);
    }

    /**
     * FR-005: the address at which the image can actually be fetched, not an
     * internal filename a consumer would have to guess how to use.
     */
    public function testALeadImageIsReportedAsAFetchableAddressWithItsAlternativeText(): void
    {
        $media = MediaFactory::createOne(['altText' => 'A cat asleep on a keyboard.']);
        ArticleFactory::new()->published()->create(['slug' => 'illustrated', 'featuredImage' => $media]);

        $body = $this->jsonItem('/api/articles/illustrated');

        self::assertSame('/media/'.$media->getFilename(), $body['imageUrl']);
        self::assertSame('A cat asleep on a keyboard.', $body['imageAlt']);
    }

    public function testTheCollectionIsNewestFirst(): void
    {
        $older = ArticleFactory::createOne(['slug' => 'older', 'content' => 'Body.']);
        $newer = ArticleFactory::createOne(['slug' => 'newer', 'content' => 'Body.']);
        $older->publish(new DateTimeImmutable('2026-01-01 10:00:00'));
        $newer->publish(new DateTimeImmutable('2026-06-01 10:00:00'));
        $this->flush();

        self::assertSame(['newer', 'older'], array_column($this->json('/api/articles'), 'slug'));
    }

    public function testTheCollectionPaginates(): void
    {
        ArticleFactory::new()->published()->many(25)->create();

        self::assertCount(20, $this->json('/api/articles'));
        self::assertCount(5, $this->json('/api/articles?page=2'));
    }

    public function testAPageBeyondTheEndIsEmptyRatherThanAnError(): void
    {
        ArticleFactory::new()->published()->many(3)->create();

        self::assertSame([], $this->json('/api/articles?page=99'));
    }

    public function testAPageCarriesItsPlacementAndNoAuthor(): void
    {
        $parent = PageFactory::new()->published()->create(['slug' => 'about-us', 'title' => 'About us']);
        PageFactory::new()->published()->childOf($parent)->create([
            'slug' => 'our-team',
            'title' => 'Our team',
            'menuOrder' => 20,
        ]);

        $body = $this->jsonItem('/api/pages/our-team');

        self::assertSame('about-us', $body['parent']);
        self::assertSame(20, $body['menuOrder']);
        self::assertArrayNotHasKey('author', $body);
    }

    public function testSectionsAreListedWithTheirDetails(): void
    {
        CategoryFactory::createOne([
            'slug' => 'news',
            'name' => 'News',
            'description' => 'What has been happening.',
        ]);

        $section = $this->json('/api/sections')[0];

        self::assertSame('news', $section['slug']);
        self::assertSame('News', $section['name']);
        self::assertSame('What has been happening.', $section['description']);
    }

    public function testASectionThatDoesNotExistIsNotFound(): void
    {
        $this->client->request('GET', '/api/sections/never-created', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(404);
    }

    public function testTheEntryPointDescribesWhatCanBeRead(): void
    {
        $this->client->request('GET', '/api', server: ['HTTP_ACCEPT' => 'application/ld+json']);

        self::assertResponseIsSuccessful();

        $body = (string) $this->client->getResponse()->getContent();

        foreach (['article', 'page', 'section', 'tag'] as $resource) {
            self::assertStringContainsStringIgnoringCase($resource, $body);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function json(string $path): array
    {
        $this->client->request('GET', $path, server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();

        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var list<array<string, mixed>> $decoded */
        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonItem(string $path): array
    {
        $this->client->request('GET', $path, server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();

        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function flush(): void
    {
        self::getContainer()->get('doctrine')->getManager()->flush();
    }
}
