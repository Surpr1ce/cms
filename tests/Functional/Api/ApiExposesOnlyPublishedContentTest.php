<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Factory\ArticleFactory;
use App\Factory\CategoryFactory;
use App\Factory\PageFactory;
use App\Factory\TagFactory;
use App\Factory\UserFactory;

use const JSON_THROW_ON_ERROR;

use function sprintf;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Test\Factories;

/**
 * The same rule the website keeps, checked through the second delivery
 * mechanism.
 *
 * A second way of reading content is a second chance to get this wrong, and it
 * is worse here than on the website: an API is read by programs, and a program
 * does not notice that something looks like it should not be there.
 *
 * The test that matters most is the last one. It asks the API and the website
 * what is published and compares the answers — the assertion
 * docs/adr/0002-twig-monolith-with-read-only-api.md exists to make true, and
 * which nothing has checked until now.
 */
final class ApiExposesOnlyPublishedContentTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    /**
     * Debug off, for the same reason feature 002's visibility tests run that way.
     *
     * With debug on, API Platform includes a full stack trace in an error
     * response — file paths, class names, the lot. That is development tooling
     * and no consumer ever sees it, so asserting against it would test the wrong
     * thing: two 404s would differ because their traces differ, and the real
     * question would go unasked.
     *
     * It also means this class asserts something worth having: that a 404 from
     * the API in its production configuration discloses nothing.
     */
    protected function setUp(): void
    {
        $this->client = self::createClient(['debug' => false]);
    }

    public function testTheArticleCollectionOmitsDrafts(): void
    {
        ArticleFactory::createMany(3, ['title' => 'A hidden draft']);
        ArticleFactory::new()->published()->many(2)->create();

        $articles = $this->json('/api/articles');

        self::assertCount(2, $articles);
        self::assertStringNotContainsString('A hidden draft', $this->body());
    }

    public function testTheArticleCollectionOmitsArchivedContent(): void
    {
        ArticleFactory::new()->publishedThenArchived()->many(2)->create(['title' => 'Retired']);
        ArticleFactory::new()->published()->create();

        self::assertCount(1, $this->json('/api/articles'));
        self::assertStringNotContainsString('Retired', $this->body());
    }

    public function testADraftArticleIsNotFoundAtItsOwnAddress(): void
    {
        ArticleFactory::createOne(['slug' => 'a-draft']);

        $this->client->request('GET', '/api/articles/a-draft', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAnArchivedArticleIsNotFoundAtItsOwnAddress(): void
    {
        ArticleFactory::new()->publishedThenArchived()->create(['slug' => 'retired']);

        $this->client->request('GET', '/api/articles/retired', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testADraftPageIsNotFoundAtItsOwnAddress(): void
    {
        PageFactory::createOne(['slug' => 'a-draft-page']);

        $this->client->request('GET', '/api/pages/a-draft-page', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testThePageCollectionOmitsDrafts(): void
    {
        PageFactory::createMany(2, ['title' => 'A hidden page']);
        PageFactory::new()->published()->many(3)->create();

        self::assertCount(3, $this->json('/api/pages'));
        self::assertStringNotContainsString('A hidden page', $this->body());
    }

    /**
     * FR-008. If these ever differ, the API has grown a branch that knows why
     * content was not shown.
     */
    public function testADraftAndAnAddressThatNeverExistedAreIndistinguishable(): void
    {
        ArticleFactory::createOne(['slug' => 'a-draft']);

        $this->client->request('GET', '/api/articles/a-draft', server: ['HTTP_ACCEPT' => 'application/json']);
        $draft = [$this->client->getResponse()->getStatusCode(), $this->body()];

        $this->client->request('GET', '/api/articles/never-written', server: ['HTTP_ACCEPT' => 'application/json']);
        $missing = [$this->client->getResponse()->getStatusCode(), $this->body()];

        self::assertSame($missing, $draft);
    }

    /**
     * A label exists to say what an article is about, so listing every label in
     * the table would name the subjects of unfinished drafts.
     */
    public function testTheLabelCollectionNamesOnlyLabelsOnPublishedArticles(): void
    {
        $used = TagFactory::createOne(['slug' => 'used', 'name' => 'Used']);
        $onADraft = TagFactory::createOne(['slug' => 'on-a-draft', 'name' => 'SecretProject']);

        ArticleFactory::new()->published()->create()->addTag($used);
        ArticleFactory::createOne()->addTag($onADraft);
        $this->flush();

        $slugs = array_column($this->json('/api/tags'), 'slug');

        self::assertContains('used', $slugs);
        self::assertNotContains('on-a-draft', $slugs);
        self::assertStringNotContainsString('SecretProject', $this->body());
    }

    /**
     * The same rule at a single label's own address, which is where it was
     * missing.
     *
     * The collection has always called findInUse(); the item endpoint called
     * findOneBySlug() and answered for any label in the table. Only a name and a
     * slug, but TagResource's own description says a label here is one carried by
     * at least one published article — so the item address contradicted the type
     * it claimed to be, and this file did not cover it. Found by a review.
     */
    public function testALabelCarriedOnlyByADraftIsNotFoundAtItsOwnAddress(): void
    {
        $onADraft = TagFactory::createOne(['slug' => 'on-a-draft', 'name' => 'SecretProject']);
        $used = TagFactory::createOne(['slug' => 'used', 'name' => 'Used']);

        ArticleFactory::createOne()->addTag($onADraft);
        ArticleFactory::new()->published()->create()->addTag($used);
        $this->flush();

        $this->client->request('GET', '/api/tags/on-a-draft');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        // The published one still answers, so this is a rule rather than the
        // endpoint being broken.
        $this->client->request('GET', '/api/tags/used');
        self::assertResponseIsSuccessful();
    }

    /**
     * FR-013 and SC-003. An author is a display name; everything else about the
     * person is nobody's business.
     */
    public function testNoResponseCarriesAnEmailAddressARoleOrAHash(): void
    {
        $author = UserFactory::new()->author()->withPassword()->create([
            'email' => 'private@example.com',
            'displayName' => 'A Writer',
        ]);
        ArticleFactory::new()->published()->create(['author' => $author, 'slug' => 'an-article']);
        PageFactory::new()->published()->create(['slug' => 'a-page']);
        CategoryFactory::createOne(['slug' => 'news']);

        foreach (['/api/articles', '/api/articles/an-article', '/api/pages', '/api/pages/a-page', '/api/sections', '/api/tags'] as $path) {
            $this->client->request('GET', $path, server: ['HTTP_ACCEPT' => 'application/json']);
            $body = $this->body();

            foreach (['private@example.com', 'ROLE_', '$2y$', 'password'] as $leak) {
                self::assertStringNotContainsString(
                    $leak,
                    $body,
                    sprintf('%s disclosed "%s".', $path, $leak),
                );
            }
        }
    }

    /**
     * The other half: an author is not hidden, only reduced to a display name.
     *
     * Only the article addresses carry one. Pages, sections and labels have no
     * author at all — the first version of this test looked for the name on
     * every endpoint and was wrong about the model rather than about the code.
     */
    public function testAnAuthorAppearsAsADisplayNameAndNothingElse(): void
    {
        $author = UserFactory::new()->author()->withPassword()->create([
            'email' => 'private@example.com',
            'displayName' => 'A Writer',
        ]);
        ArticleFactory::new()->published()->create(['author' => $author, 'slug' => 'an-article']);

        foreach (['/api/articles', '/api/articles/an-article'] as $path) {
            $this->client->request('GET', $path, server: ['HTTP_ACCEPT' => 'application/json']);

            self::assertStringContainsString('A Writer', $this->body(), sprintf('%s lost the display name.', $path));
            self::assertStringNotContainsString('private@example.com', $this->body());
        }
    }

    /**
     * SC-004, and the reason this feature is interesting.
     *
     * Two delivery mechanisms, one question. If they ever disagree, the claim
     * the whole architecture rests on has stopped holding — and it would have
     * stopped holding silently, which is why this is a test rather than a
     * comment.
     */
    public function testTheApiAndTheWebsiteAgreeAboutWhatIsPublished(): void
    {
        ArticleFactory::createMany(4);
        ArticleFactory::new()->publishedThenArchived()->many(3)->create();
        ArticleFactory::new()->published()->many(5)->create();

        $fromApi = array_column($this->json('/api/articles'), 'slug');
        sort($fromApi);

        $crawler = $this->client->request('GET', '/');

        // each() with a typed closure rather than extract(), which hands back
        // mixed and cannot be narrowed without a cast the quality gate refuses.
        $fromWebsite = $crawler->filter('article h2 a')->each(
            static fn (Crawler $link): string => str_replace('/articles/', '', (string) $link->attr('href')),
        );
        sort($fromWebsite);

        self::assertSame($fromWebsite, $fromApi);
    }

    public function testTheApiAndTheWebsiteAgreeThatADraftIsUnreachable(): void
    {
        ArticleFactory::createOne(['slug' => 'a-draft']);

        $this->client->request('GET', '/articles/a-draft');
        $website = $this->client->getResponse()->getStatusCode();

        $this->client->request('GET', '/api/articles/a-draft', server: ['HTTP_ACCEPT' => 'application/json']);
        $api = $this->client->getResponse()->getStatusCode();

        self::assertSame(Response::HTTP_NOT_FOUND, $website);
        self::assertSame(Response::HTTP_NOT_FOUND, $api);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function json(string $path): array
    {
        $this->client->request('GET', $path, server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();

        $decoded = json_decode($this->body(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var list<array<string, mixed>> $decoded */
        return $decoded;
    }

    private function body(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }

    private function flush(): void
    {
        self::getContainer()->get('doctrine')->getManager()->flush();
    }
}
