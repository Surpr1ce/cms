<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Article;
use App\Entity\User;
use App\Factory\ArticleFactory;
use App\Factory\PageFactory;
use App\Factory\UserFactory;
use PHPUnit\Framework\Attributes\DataProvider;

use function sprintf;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Test\Factories;

/**
 * Constitution principle IV, applied to every administration address: the
 * anonymous case and the insufficient-permission case, for all of them.
 *
 * Every refusal here is proven by **submitting the address directly**, never by
 * checking that a button is missing. SC-004 says so explicitly, and the reason
 * is that a hidden control is not a permission — it is a suggestion. An author
 * who types a URL is the whole threat model.
 */
final class AdminPermissionsTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function administrationAddressProvider(): iterable
    {
        yield 'the dashboard' => ['GET', '/admin'];
        yield 'the article list' => ['GET', '/admin/articles'];
        yield 'the new article form' => ['GET', '/admin/articles/new'];
        yield 'the page list' => ['GET', '/admin/pages'];
        yield 'the new page form' => ['GET', '/admin/pages/new'];
    }

    #[DataProvider('administrationAddressProvider')]
    public function testEveryAddressIsClosedToSomebodyNotSignedIn(string $method, string $path): void
    {
        $this->client->request($method, $path);

        self::assertResponseRedirects();
        self::assertStringContainsString(
            '/login',
            (string) $this->client->getResponse()->headers->get('Location'),
            sprintf('%s did not send an anonymous visitor to sign in.', $path),
        );
    }

    #[DataProvider('administrationAddressProvider')]
    public function testNoAddressLeaksItsContentToSomebodyNotSignedIn(string $method, string $path): void
    {
        $this->client->request($method, $path);

        $body = (string) $this->client->getResponse()->getContent();

        foreach (['New article', 'New page', 'Sign out'] as $leak) {
            self::assertStringNotContainsString($leak, $body, sprintf('%s leaked "%s".', $path, $leak));
        }
    }

    // --- an author against somebody else's work ---

    public function testAnAuthorCannotOpenSomebodyElsesDraft(): void
    {
        $article = ArticleFactory::createOne(['author' => UserFactory::new()->author()->create()]);
        $this->signInAs([User::ROLE_AUTHOR]);

        $this->client->request('GET', '/admin/articles/'.$article->getId().'/edit');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    /**
     * FR-016 through a screen: once readers have seen it, it is not the
     * author's alone.
     */
    public function testAnAuthorCannotOpenTheirOwnPublishedArticle(): void
    {
        $author = $this->signInAs([User::ROLE_AUTHOR]);
        $article = ArticleFactory::new()->published()->create(['author' => $author]);

        $this->client->request('GET', '/admin/articles/'.$article->getId().'/edit');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testAnAuthorCanOpenTheirOwnDraft(): void
    {
        $author = $this->signInAs([User::ROLE_AUTHOR]);
        $article = ArticleFactory::createOne(['author' => $author]);

        $this->client->request('GET', '/admin/articles/'.$article->getId().'/edit');

        self::assertResponseIsSuccessful();
    }

    /**
     * The submission, not the button. An author who never sees a publish control
     * can still type the address.
     */
    public function testAnAuthorCannotPublishEvenTheirOwnDraftBySubmittingDirectly(): void
    {
        $author = $this->signInAs([User::ROLE_AUTHOR]);
        $article = ArticleFactory::createOne(['author' => $author, 'content' => 'A body.']);

        $this->client->request('POST', '/admin/articles/'.$article->getId().'/publish', ['_token' => 'anything']);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertFalse($this->reload($article)->isPublished());
    }

    public function testAnAuthorCannotDeleteSomebodyElsesArticleBySubmittingDirectly(): void
    {
        $article = ArticleFactory::createOne(['author' => UserFactory::new()->author()->create()]);
        $this->signInAs([User::ROLE_AUTHOR]);

        $this->client->request('POST', '/admin/articles/'.$article->getId().'/delete', ['_token' => 'anything']);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    /**
     * FR-022 and US4 scenario 7: pages are editorial, and an author has no claim
     * on any of them.
     */
    public function testAnAuthorCannotReachAnyPageScreen(): void
    {
        $this->signInAs([User::ROLE_AUTHOR]);
        $page = PageFactory::createOne();

        foreach ([
            ['GET', '/admin/pages'],
            ['GET', '/admin/pages/new'],
            ['GET', '/admin/pages/'.$page->getId().'/edit'],
        ] as [$method, $path]) {
            $this->client->request($method, $path);

            self::assertResponseStatusCodeSame(
                Response::HTTP_FORBIDDEN,
                sprintf('An author reached %s.', $path),
            );
        }
    }

    public function testAnAuthorIsNotOfferedTheLinkToPages(): void
    {
        $this->signInAs([User::ROLE_AUTHOR]);

        $this->client->request('GET', '/admin');

        self::assertStringNotContainsString(
            '/admin/pages',
            (string) $this->client->getResponse()->getContent(),
        );
    }

    // --- an editor ---

    public function testAnEditorCanOpenAnybodysArticleInAnyState(): void
    {
        $somebodyElse = UserFactory::new()->author()->create();
        $draft = ArticleFactory::createOne(['author' => $somebodyElse]);
        $published = ArticleFactory::new()->published()->create(['author' => $somebodyElse]);
        $archived = ArticleFactory::new()->publishedThenArchived()->create(['author' => $somebodyElse]);

        $this->signInAs([User::ROLE_EDITOR]);

        foreach ([$draft, $published, $archived] as $article) {
            $this->client->request('GET', '/admin/articles/'.$article->getId().'/edit');

            self::assertResponseIsSuccessful(
                sprintf('An editor could not open a %s article.', $article->getStatus()->value),
            );
        }
    }

    public function testAnEditorReachesThePageScreens(): void
    {
        $this->signInAs([User::ROLE_EDITOR]);

        foreach (['/admin/pages', '/admin/pages/new'] as $path) {
            $this->client->request('GET', $path);

            self::assertResponseIsSuccessful(sprintf('An editor could not reach %s.', $path));
        }
    }

    public function testAnAdministratorReachesEverythingAnEditorDoes(): void
    {
        $this->signInAs([User::ROLE_ADMIN]);

        foreach (['/admin', '/admin/articles', '/admin/articles/new', '/admin/pages', '/admin/pages/new'] as $path) {
            $this->client->request('GET', $path);

            self::assertResponseIsSuccessful(sprintf('An administrator could not reach %s.', $path));
        }
    }

    // --- the listing shows only what the viewer may see ---

    /**
     * FR-008. An author sees their own work and what is already public, and
     * nothing else — somebody else's unfinished draft is not theirs to read.
     */
    public function testAnAuthorsListingHidesOtherPeoplesDrafts(): void
    {
        $somebodyElse = UserFactory::new()->author()->create();
        ArticleFactory::createOne(['author' => $somebodyElse, 'title' => 'Somebody elses draft']);
        ArticleFactory::new()->published()->create(['author' => $somebodyElse, 'title' => 'Already public']);

        $author = $this->signInAs([User::ROLE_AUTHOR]);
        ArticleFactory::createOne(['author' => $author, 'title' => 'My own draft']);

        $this->client->request('GET', '/admin/articles');
        $body = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('My own draft', $body);
        self::assertStringContainsString('Already public', $body);
        self::assertStringNotContainsString('Somebody elses draft', $body);
    }

    public function testAnEditorsListingShowsEverything(): void
    {
        $somebodyElse = UserFactory::new()->author()->create();
        ArticleFactory::createOne(['author' => $somebodyElse, 'title' => 'Somebody elses draft']);

        $this->signInAs([User::ROLE_EDITOR]);

        $this->client->request('GET', '/admin/articles');

        self::assertStringContainsString(
            'Somebody elses draft',
            (string) $this->client->getResponse()->getContent(),
        );
    }

    // --- tokens ---

    /**
     * FR-010: a state change reachable by following a link is a state change
     * another site can cause by embedding an image.
     */
    public function testAStateChangeWithoutTheTokenIsRefused(): void
    {
        $this->signInAs([User::ROLE_EDITOR]);
        $article = ArticleFactory::createOne(['content' => 'A body.']);

        $this->client->request('POST', '/admin/articles/'.$article->getId().'/publish', ['_token' => 'wrong']);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertFalse($this->reload($article)->isPublished());
    }

    public function testADeletionWithoutTheTokenIsRefused(): void
    {
        $this->signInAs([User::ROLE_EDITOR]);
        $article = ArticleFactory::createOne();
        $id = $article->getId();

        $this->client->request('POST', '/admin/articles/'.$id.'/delete', ['_token' => 'wrong']);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertNotNull($this->reloadById($id));
    }

    public function testAStateChangeCannotBeCausedByFollowingALink(): void
    {
        $this->signInAs([User::ROLE_EDITOR]);
        $article = ArticleFactory::createOne(['content' => 'A body.']);

        $this->client->request('GET', '/admin/articles/'.$article->getId().'/publish');

        self::assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    /**
     * @param list<string> $roles
     */
    private function signInAs(array $roles): User
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

    private function reload(Article $article): Article
    {
        $reloaded = $this->reloadById($article->getId());
        self::assertInstanceOf(Article::class, $reloaded);

        return $reloaded;
    }

    private function reloadById(?int $id): ?Article
    {
        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $entityManager->clear();

        return $entityManager->getRepository(Article::class)->find($id);
    }
}
