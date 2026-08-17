<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Article;
use App\Entity\ContentStatus;
use App\Entity\User;
use App\Factory\ArticleFactory;
use App\Factory\CategoryFactory;
use App\Factory\UserFactory;
use App\Repository\ArticleRepository;
use Doctrine\ORM\EntityManagerInterface;

use function sprintf;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Writing an article and getting it in front of a reader, through the screens.
 *
 * SC-005 is the one that matters here: a complete article can be written,
 * saved, published and read on the public site without leaving the browser.
 */
final class ArticleAdministrationTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testAnAuthorCreatesADraftAttributedToThem(): void
    {
        $author = $this->signIn([User::ROLE_AUTHOR]);

        $this->createArticle('A first article', '<p>Something to say.</p>');

        $article = $this->onlyArticle();

        self::assertSame('A first article', $article->getTitle());
        self::assertSame(ContentStatus::Draft, $article->getStatus());
        self::assertSame($author->getId(), $article->getAuthor()->getId());
        self::assertNull($article->getPublishedAt());
    }

    /**
     * FR-013, through the screen this time.
     */
    public function testANewArticleIsGivenAnAddressFromItsTitle(): void
    {
        $this->signIn([User::ROLE_AUTHOR]);

        $this->createArticle('Hello, World!', '<p>Body.</p>');

        self::assertSame('hello-world', $this->onlyArticle()->getSlug());
    }

    public function testASecondArticleWithTheSameTitleGetsADistinctAddress(): void
    {
        ArticleFactory::createOne(['slug' => 'hello-world']);
        $this->signIn([User::ROLE_AUTHOR]);

        $this->createArticle('Hello, World!', '<p>Body.</p>');

        $slugs = array_map(
            static fn (Article $article): string => $article->getSlug(),
            $this->repository()->findAll(),
        );

        self::assertContains('hello-world-2', $slugs);
    }

    /**
     * The gap feature 001 recorded and could not close: the entity can freeze an
     * address but cannot generate one, because uniqueness needs the database.
     * The administration layer is the single entry point that record was waiting
     * for, and this is the test that proves it arrived.
     */
    public function testRenamingADraftMovesItsAddress(): void
    {
        $author = $this->signIn([User::ROLE_AUTHOR]);
        $article = ArticleFactory::createOne([
            'author' => $author,
            'title' => 'A first attempt',
            'slug' => 'a-first-attempt',
            'content' => 'Body.',
        ]);

        $this->editArticle($article, 'A much better title');

        self::assertSame('a-much-better-title', $this->reload($article)->getSlug());
    }

    /**
     * FR-014, the other half. Once readers can have linked to it, the address
     * stops following the title.
     */
    public function testRenamingAPublishedArticleLeavesItsAddressAlone(): void
    {
        $this->signIn([User::ROLE_EDITOR]);
        $article = ArticleFactory::new()->published()->create([
            'title' => 'The original title',
            'slug' => 'the-original-title',
            'content' => 'Body.',
        ]);

        $this->editArticle($article, 'A completely different title');

        $reloaded = $this->reload($article);

        self::assertSame('the-original-title', $reloaded->getSlug());
        self::assertSame('A completely different title', $reloaded->getTitle());
    }

    public function testSavingTheSameTitleTwiceDoesNotKeepSuffixingTheAddress(): void
    {
        $author = $this->signIn([User::ROLE_AUTHOR]);
        $article = ArticleFactory::createOne([
            'author' => $author,
            'title' => 'A steady title',
            'slug' => 'a-steady-title',
            'content' => 'Body.',
        ]);

        $this->editArticle($article, 'A steady title');
        $this->editArticle($article, 'A steady title');

        self::assertSame('a-steady-title', $this->reload($article)->getSlug());
    }

    public function testAnArticleWithNoTitleIsRefusedAndStoresNothing(): void
    {
        $this->signIn([User::ROLE_AUTHOR]);

        $crawler = $this->client->request('GET', '/admin/articles/new');
        $this->client->submit($crawler->selectButton('Create')->form([
            'article[title]' => '',
            'article[content]' => '<p>Body.</p>',
        ]));

        self::assertFalse($this->client->getResponse()->isRedirection());
        self::assertCount(0, $this->repository()->findAll());
    }

    /**
     * SC-005, end to end.
     */
    public function testAnEditorWritesPublishesAndAReaderCanReadIt(): void
    {
        $this->signIn([User::ROLE_EDITOR]);

        $this->createArticle('Something worth reading', '<h2>A heading</h2><p>And a paragraph.</p>');
        $article = $this->onlyArticle();

        $crawler = $this->client->request('GET', '/admin/articles/'.$article->getId().'/edit');
        $this->client->submit($crawler->selectButton('Publish')->form());

        $reloaded = $this->reload($article);
        self::assertTrue($reloaded->isPublished());
        self::assertNotNull($reloaded->getPublishedAt());

        $this->client->request('GET', '/articles/'.$reloaded->getSlug());

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Something worth reading', $body);
        self::assertStringContainsString('And a paragraph.', $body);
    }

    public function testUnpublishingTakesItOffThePublicSiteWithoutMovingItsDate(): void
    {
        $this->signIn([User::ROLE_EDITOR]);
        $article = ArticleFactory::new()->published()->create(['slug' => 'visible', 'content' => 'Body.']);
        $publishedAt = $article->getPublishedAt();

        $crawler = $this->client->request('GET', '/admin/articles/'.$article->getId().'/edit');
        $this->client->submit($crawler->selectButton('Unpublish')->form());

        $reloaded = $this->reload($article);
        self::assertFalse($reloaded->isPublished());

        // Compared to the second, because that is the precision the column
        // holds — data-model.md specifies TIMESTAMP(0). The object in memory
        // carries microseconds the database never stored, so comparing the two
        // directly would fail on a difference that does not exist in the data.
        self::assertNotNull($publishedAt);
        self::assertNotNull($reloaded->getPublishedAt());
        self::assertSame(
            $publishedAt->format('Y-m-d H:i:s'),
            $reloaded->getPublishedAt()->format('Y-m-d H:i:s'),
        );

        $this->client->request('GET', '/articles/visible');
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * FR-019: a refusal explains itself and changes nothing.
     */
    public function testPublishingAnArticleWithNoBodyIsRefusedWithAReason(): void
    {
        $this->signIn([User::ROLE_EDITOR]);
        $article = ArticleFactory::createOne(['content' => '']);

        $crawler = $this->client->request('GET', '/admin/articles/'.$article->getId().'/edit');
        $this->client->submit($crawler->selectButton('Publish')->form());
        $crawler = $this->client->followRedirect();

        self::assertStringContainsString('empty body', $crawler->filter('[role="alert"]')->text());
        self::assertFalse($this->reload($article)->isPublished());
    }

    public function testArchivingAndRestoringReturnsItToADraft(): void
    {
        $this->signIn([User::ROLE_EDITOR]);
        $article = ArticleFactory::new()->published()->create(['content' => 'Body.']);

        $crawler = $this->client->request('GET', '/admin/articles/'.$article->getId().'/edit');
        $this->client->submit($crawler->selectButton('Archive')->form());

        self::assertSame(ContentStatus::Archived, $this->reload($article)->getStatus());

        $crawler = $this->client->request('GET', '/admin/articles/'.$article->getId().'/edit');
        $this->client->submit($crawler->selectButton('Restore')->form());

        self::assertSame(ContentStatus::Draft, $this->reload($article)->getStatus());
    }

    public function testAnAuthorDeletesTheirOwnDraft(): void
    {
        $author = $this->signIn([User::ROLE_AUTHOR]);
        $article = ArticleFactory::createOne(['author' => $author]);

        $crawler = $this->client->request('GET', '/admin/articles/'.$article->getId().'/edit');
        $this->client->submit($crawler->selectButton('Delete this article')->form());

        self::assertResponseRedirects('/admin/articles');
        self::assertCount(0, $this->repository()->findAll());
    }

    public function testASectionCanBeAssignedFromTheForm(): void
    {
        $this->signIn([User::ROLE_EDITOR]);
        $section = CategoryFactory::createOne(['name' => 'News', 'slug' => 'news']);

        $crawler = $this->client->request('GET', '/admin/articles/new');
        $this->client->submit($crawler->selectButton('Create')->form([
            'article[title]' => 'A filed article',
            'article[content]' => '<p>Body.</p>',
            'article[category]' => (string) $section->getId(),
        ]));

        self::assertSame('News', $this->onlyArticle()->getCategory()?->getName());
    }

    /**
     * @param list<string> $roles
     */
    private function signIn(array $roles): User
    {
        $user = UserFactory::new()->withPassword()->create([
            'email' => 'person@example.com',
            'roles' => $roles,
        ]);

        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Sign in')->form([
            '_username' => 'person@example.com',
            '_password' => UserFactory::DEVELOPMENT_PASSWORD,
        ]));
        $this->client->followRedirect();

        return $user;
    }

    private function createArticle(string $title, string $body): void
    {
        $crawler = $this->client->request('GET', '/admin/articles/new');

        $this->client->submit($crawler->selectButton('Create')->form([
            'article[title]' => $title,
            'article[content]' => $body,
        ]));

        self::assertTrue(
            $this->client->getResponse()->isRedirection(),
            sprintf('Creating "%s" failed with status %d.', $title, $this->client->getResponse()->getStatusCode()),
        );
    }

    private function editArticle(Article $article, string $title): void
    {
        $crawler = $this->client->request('GET', '/admin/articles/'.$article->getId().'/edit');

        $this->client->submit($crawler->selectButton('Save')->form([
            'article[title]' => $title,
        ]));

        self::assertTrue(
            $this->client->getResponse()->isRedirection(),
            sprintf('Saving "%s" failed with status %d.', $title, $this->client->getResponse()->getStatusCode()),
        );
    }

    private function onlyArticle(): Article
    {
        $all = $this->repository()->findAll();
        self::assertCount(1, $all);

        return $all[0];
    }

    private function reload(Article $article): Article
    {
        $reloaded = $this->repository()->find($article->getId());
        self::assertInstanceOf(Article::class, $reloaded);

        return $reloaded;
    }

    private function repository(): ArticleRepository
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->clear();

        $repository = self::getContainer()->get(ArticleRepository::class);
        self::assertInstanceOf(ArticleRepository::class, $repository);

        return $repository;
    }
}
