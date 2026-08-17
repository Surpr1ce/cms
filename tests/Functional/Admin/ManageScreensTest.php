<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Article;
use App\Entity\Category;
use App\Entity\User;
use App\Factory\ArticleFactory;
use App\Factory\CategoryFactory;
use App\Factory\TagFactory;
use App\Factory\UserFactory;
use App\Repository\ArticleRepository;
use App\Repository\CategoryRepository;
use App\Repository\TagRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;

use function sprintf;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The generic-CRUD screens, and the rules a generic tool must not bypass.
 *
 * The risk here is different from the earlier features. It is not that something
 * leaks — it is that a scaffolded screen quietly changes a behaviour the domain
 * holds. So the deletion tests assert **what survived**, not that a particular
 * service was called: a test checking for a service call would pass while the
 * articles were destroyed.
 */
final class ManageScreensTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    // --- permissions on every screen ---

    /**
     * @return iterable<string, array{string}>
     */
    public static function taxonomyAddressProvider(): iterable
    {
        yield 'the manage dashboard' => ['/admin/manage'];
        yield 'the section list' => ['/admin/manage/category'];
        yield 'the new section form' => ['/admin/manage/category/new'];
        yield 'the label list' => ['/admin/manage/tag'];
        yield 'the new label form' => ['/admin/manage/tag/new'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function accountAddressProvider(): iterable
    {
        yield 'the account list' => ['/admin/manage/user'];
        yield 'the new account form' => ['/admin/manage/user/new'];
    }

    #[DataProvider('taxonomyAddressProvider')]
    #[DataProvider('accountAddressProvider')]
    public function testEveryScreenIsClosedToSomebodyNotSignedIn(string $path): void
    {
        $this->client->request('GET', $path);

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    #[DataProvider('taxonomyAddressProvider')]
    public function testAnAuthorCannotReachATaxonomyScreen(string $path): void
    {
        $this->signIn([User::ROLE_AUTHOR]);

        $this->client->request('GET', $path);

        self::assertFalse(
            $this->client->getResponse()->isSuccessful(),
            sprintf('An author reached %s.', $path),
        );
    }

    /**
     * FR-014. Running the site and deciding who may run it are different
     * authorities, and this is where that line is drawn in the interface.
     */
    #[DataProvider('accountAddressProvider')]
    public function testAnEditorCannotReachAnAccountScreen(string $path): void
    {
        $this->signIn([User::ROLE_EDITOR]);

        $this->client->request('GET', $path);

        self::assertFalse(
            $this->client->getResponse()->isSuccessful(),
            sprintf('An editor reached %s.', $path),
        );
    }

    #[DataProvider('taxonomyAddressProvider')]
    public function testAnEditorReachesEveryTaxonomyScreen(string $path): void
    {
        $this->signIn([User::ROLE_EDITOR]);

        $this->client->request('GET', $path);

        self::assertResponseIsSuccessful(sprintf('An editor could not reach %s.', $path));
    }

    #[DataProvider('accountAddressProvider')]
    public function testAnAdministratorReachesEveryAccountScreen(string $path): void
    {
        $this->signIn([User::ROLE_ADMIN]);

        $this->client->request('GET', $path);

        self::assertResponseIsSuccessful(sprintf('An administrator could not reach %s.', $path));
    }

    public function testAnEditorIsNotOfferedTheAccountsLink(): void
    {
        $this->signIn([User::ROLE_EDITOR]);

        $this->client->request('GET', '/admin/manage');

        self::assertStringNotContainsString(
            '/admin/manage/user',
            (string) $this->client->getResponse()->getContent(),
        );
    }

    // --- sections ---

    /**
     * SC-001, and the hole this feature exists to close: before it, the section
     * picker on the article screen was a list nobody could add to.
     */
    public function testAnEditorCreatesASectionWhichThenAppearsOnTheArticleScreen(): void
    {
        $this->signIn([User::ROLE_EDITOR]);

        $crawler = $this->client->request('GET', '/admin/manage/category/new');
        $this->client->submit($crawler->selectButton('saveAndReturn')->form([
            'Category[name]' => 'Long Reads',
        ]));

        $section = $this->onlySection();
        self::assertSame('Long Reads', $section->getName());
        self::assertSame('long-reads', $section->getSlug());

        $crawler = $this->client->request('GET', '/admin/articles/new');
        self::assertStringContainsString('Long Reads', $crawler->filter('#article_category')->html());
    }

    public function testASecondSectionWithTheSameNameGetsADistinctAddress(): void
    {
        CategoryFactory::createOne(['name' => 'News', 'slug' => 'news']);
        $this->signIn([User::ROLE_EDITOR]);

        $crawler = $this->client->request('GET', '/admin/manage/category/new');
        $this->client->submit($crawler->selectButton('saveAndReturn')->form(['Category[name]' => 'News']));

        $slugs = array_map(
            static fn (Category $category): string => $category->getSlug(),
            $this->sections()->findAll(),
        );

        self::assertContains('news-2', $slugs);
    }

    /**
     * FR-003. The address appears in a public URL, so renaming a section does
     * not move it — the same reasoning that freezes an article's address.
     */
    public function testRenamingASectionDoesNotMoveItsAddress(): void
    {
        $section = CategoryFactory::createOne(['name' => 'News', 'slug' => 'news']);
        $this->signIn([User::ROLE_EDITOR]);

        $crawler = $this->client->request('GET', '/admin/manage/category/'.$section->getId().'/edit');
        $this->client->submit($crawler->selectButton('saveAndReturn')->form([
            'Category[name]' => 'Current Affairs',
        ]));

        $reloaded = $this->reloadSection($section);

        self::assertSame('Current Affairs', $reloaded->getName());
        self::assertSame('news', $reloaded->getSlug());
    }

    public function testNoSectionFormOffersToEditTheAddress(): void
    {
        $this->signIn([User::ROLE_EDITOR]);

        $crawler = $this->client->request('GET', '/admin/manage/category/new');

        self::assertCount(0, $crawler->filter('input[name="Category[slug]"]'));
    }

    /**
     * FR-004, through the generic screen. The scaffolded delete would leave the
     * articles alone — the constraint does that — but would make the subsections
     * top-level rather than moving them up to their grandparent.
     */
    public function testDeletingASectionKeepsItsArticlesAndMovesItsSubsectionsUp(): void
    {
        $grandparent = CategoryFactory::createOne(['slug' => 'root']);
        $doomed = CategoryFactory::new()->childOf($grandparent)->create(['slug' => 'middle']);
        CategoryFactory::new()->childOf($doomed)->create(['slug' => 'leaf']);
        ArticleFactory::createMany(2, ['category' => $doomed]);

        $this->signIn([User::ROLE_EDITOR]);
        $this->deleteThrough('/admin/manage/category', $doomed->getId());

        $sections = $this->sections();
        self::assertNull($sections->findOneBySlug('middle'));

        $leaf = $sections->findOneBySlug('leaf');
        self::assertInstanceOf(Category::class, $leaf);
        self::assertSame('root', $leaf->getParent()?->getSlug(), 'The subsection did not move up.');

        $articles = $this->articles();
        self::assertCount(2, $articles);
        foreach ($articles as $article) {
            self::assertNull($article->getCategory());
        }
    }

    /**
     * The batch route is disabled, and the override is what makes the rule hold
     * even so — EasyAdmin funnels both deletes through deleteEntity().
     */
    public function testTheBatchDeleteRouteIsNotOffered(): void
    {
        CategoryFactory::createMany(2);
        $this->signIn([User::ROLE_EDITOR]);

        $crawler = $this->client->request('GET', '/admin/manage/category');

        self::assertCount(0, $crawler->filter('input[name="batchActionName"]'));
    }

    // --- labels ---

    public function testAnEditorCreatesALabel(): void
    {
        $this->signIn([User::ROLE_EDITOR]);

        $crawler = $this->client->request('GET', '/admin/manage/tag/new');
        $this->client->submit($crawler->selectButton('saveAndReturn')->form(['Tag[name]' => 'Long Form']));

        $tags = $this->tags()->findAll();

        self::assertCount(1, $tags);
        self::assertSame('long-form', $tags[0]->getSlug());
    }

    public function testDeletingALabelKeepsItsArticles(): void
    {
        $tag = TagFactory::createOne(['slug' => 'php']);
        foreach (ArticleFactory::createMany(3) as $article) {
            $article->addTag($tag);
        }

        $this->flush();

        $this->signIn([User::ROLE_EDITOR]);
        $this->deleteThrough('/admin/manage/tag', $tag->getId());

        self::assertCount(0, $this->tags()->findAll());
        self::assertCount(3, $this->articles());
    }

    // --- accounts ---

    /**
     * SC-002: a second person can be given access entirely through the browser.
     */
    public function testAnAdministratorCreatesAnAccountThatCanSignIn(): void
    {
        $this->signIn([User::ROLE_ADMIN]);

        $crawler = $this->client->request('GET', '/admin/manage/user/new');
        $form = $crawler->selectButton('saveAndReturn')->form([
            'User[email]' => 'newcomer@example.com',
            'User[displayName]' => 'A Newcomer',
            'User[plainPassword]' => 'a-long-enough-password',
        ]);

        // The roles are added to the submitted values rather than set on the
        // form. Expanded multiple-choice renders one checkbox per role, all
        // sharing the name `User[roles][]`, so the crawler exposes them as a
        // collection that neither accepts an array nor types cleanly — posting
        // the values directly is what a browser sends anyway.
        $values = $form->getPhpValues();

        $account = $values['User'] ?? [];
        self::assertIsArray($account);
        $account['roles'] = [User::ROLE_EDITOR];
        $values['User'] = $account;

        $this->client->request($form->getMethod(), $form->getUri(), $values);

        $created = $this->accounts()->findOneByEmail('newcomer@example.com');
        self::assertInstanceOf(User::class, $created);
        self::assertContains(User::ROLE_EDITOR, $created->getRoles());

        // Sign out, and in again as the new account.
        $this->client->request('POST', '/logout');
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Sign in')->form([
            '_username' => 'newcomer@example.com',
            '_password' => 'a-long-enough-password',
        ]));
        $this->client->request('GET', '/admin');

        self::assertResponseIsSuccessful('The account created through the browser could not sign in.');
    }

    /**
     * FR-008 and SC-004. A form field mapped to the entity would have displayed
     * the stored hash on the edit screen.
     */
    public function testNoAccountScreenDisplaysAPasswordOrAHash(): void
    {
        $account = UserFactory::new()->editor()->withPassword()->create(['email' => 'someone@example.com']);
        $hash = $account->getPassword();

        $this->signIn([User::ROLE_ADMIN]);

        foreach ([
            '/admin/manage/user',
            '/admin/manage/user/'.$account->getId().'/edit',
        ] as $path) {
            $this->client->request('GET', $path);
            $body = (string) $this->client->getResponse()->getContent();

            self::assertStringNotContainsString($hash, $body, sprintf('%s displayed the hash.', $path));
            self::assertStringNotContainsString('$2y$', $body, sprintf('%s displayed a hash.', $path));
        }
    }

    /**
     * FR-009. A form that demanded a password to save a display name would train
     * people to retype one, and a retyped password is a weaker password.
     */
    public function testEditingWithoutAPasswordLeavesTheExistingOneWorking(): void
    {
        $account = UserFactory::new()->editor()->withPassword()->create(['email' => 'someone@example.com']);
        $originalHash = $account->getPassword();

        $this->signIn([User::ROLE_ADMIN]);

        $crawler = $this->client->request('GET', '/admin/manage/user/'.$account->getId().'/edit');
        $this->client->submit($crawler->selectButton('saveAndReturn')->form([
            'User[displayName]' => 'A Renamed Person',
            'User[plainPassword]' => '',
        ]));

        $reloaded = $this->accounts()->findOneByEmail('someone@example.com');
        self::assertInstanceOf(User::class, $reloaded);
        self::assertSame('A Renamed Person', $reloaded->getDisplayName());
        self::assertSame($originalHash, $reloaded->getPassword(), 'The password changed when it should not have.');
    }

    /**
     * FR-010: the sentence, not the foreign-key name a scaffolded delete would
     * have produced.
     */
    public function testAnAccountThatOwnsContentCannotBeDeleted(): void
    {
        $author = UserFactory::new()->author()->create(['email' => 'author@example.com']);
        ArticleFactory::createMany(2, ['author' => $author]);

        $this->signIn([User::ROLE_ADMIN]);
        $this->deleteThrough('/admin/manage/user', $author->getId());

        self::assertNotNull($this->accounts()->findOneByEmail('author@example.com'));
        self::assertCount(2, $this->articles());
    }

    public function testAnAccountThatOwnsNothingIsDeleted(): void
    {
        UserFactory::new()->author()->create(['email' => 'nobody@example.com']);

        $this->signIn([User::ROLE_ADMIN]);
        $account = $this->accounts()->findOneByEmail('nobody@example.com');
        self::assertInstanceOf(User::class, $account);

        $this->deleteThrough('/admin/manage/user', $account->getId());

        self::assertNull($this->accounts()->findOneByEmail('nobody@example.com'));
    }

    /**
     * FR-011. One administrator on a fresh installation removing themselves
     * leaves a site nobody can administer.
     */
    public function testAnAdministratorCannotDeleteTheirOwnAccount(): void
    {
        $administrator = $this->signIn([User::ROLE_ADMIN]);

        $this->deleteThrough('/admin/manage/user', $administrator->getId());

        self::assertNotNull($this->accounts()->findOneByEmail('person@example.com'));
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

    /**
     * Deletes through the screen, using the token EasyAdmin renders for it.
     *
     * The token lives in a hidden confirmation form on the index page — EasyAdmin
     * posts it there after a modal, rather than rendering a form per row. Reading
     * it from the page rather than asking the container for one keeps this
     * honest: the test uses the token the application actually issued.
     */
    private function deleteThrough(string $indexPath, ?int $id): void
    {
        self::assertNotNull($id);

        $crawler = $this->client->request('GET', $indexPath);
        $token = (string) $crawler->filter('form#action-confirmation-form input[name="token"]')->attr('value');

        self::assertNotSame('', $token, 'No delete token was rendered on '.$indexPath);

        $this->client->request('POST', $indexPath.'/'.$id.'/delete', ['token' => $token]);
    }

    private function onlySection(): Category
    {
        $all = $this->sections()->findAll();
        self::assertCount(1, $all);

        return $all[0];
    }

    private function reloadSection(Category $section): Category
    {
        $reloaded = $this->sections()->find($section->getId());
        self::assertInstanceOf(Category::class, $reloaded);

        return $reloaded;
    }

    private function sections(): CategoryRepository
    {
        $this->clear();

        $repository = self::getContainer()->get(CategoryRepository::class);
        self::assertInstanceOf(CategoryRepository::class, $repository);

        return $repository;
    }

    private function tags(): TagRepository
    {
        $this->clear();

        $repository = self::getContainer()->get(TagRepository::class);
        self::assertInstanceOf(TagRepository::class, $repository);

        return $repository;
    }

    private function accounts(): UserRepository
    {
        $this->clear();

        $repository = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);

        return $repository;
    }

    /**
     * @return list<Article>
     */
    private function articles(): array
    {
        $this->clear();

        $repository = self::getContainer()->get(ArticleRepository::class);
        self::assertInstanceOf(ArticleRepository::class, $repository);

        return array_values($repository->findAll());
    }

    private function clear(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->clear();
    }

    private function flush(): void
    {
        self::getContainer()->get('doctrine')->getManager()->flush();
    }
}
