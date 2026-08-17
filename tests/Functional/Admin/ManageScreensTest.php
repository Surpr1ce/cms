<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Article;
use App\Entity\Category;
use App\Entity\Tag;
use App\Entity\User;
use App\Factory\ArticleFactory;
use App\Factory\CategoryFactory;
use App\Factory\TagFactory;
use App\Factory\UserFactory;
use App\Repository\CategoryRepository;
use App\Repository\TagRepository;
use App\Repository\UserRepository;
use App\Tests\Functional\SigningOut;
use Doctrine\ORM\EntityManagerInterface;

use function sprintf;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Sections, labels and accounts.
 *
 * These three lived in EasyAdmin until feature 016. What that bundle gave for
 * free — a list, a form, a delete — is written by hand now, so what it *cost*
 * has to be re-earned by tests rather than assumed: the rules that a generic
 * screen was overridden to keep, and the ones a hand-written screen can forget
 * as easily as a generated one.
 *
 * The three that carry the weight:
 *
 * **An address is generated once and then fixed.** No screen offers to edit one,
 * and renaming does not move it — a section's address is in every link a reader
 * has to it.
 *
 * **Deleting a section keeps its articles.** They become uncategorised; the
 * subsections move up. Asserted on what survived, never on which class was
 * called: a test that checked for a service call would pass while the articles
 * were destroyed.
 *
 * **An account's stored hash is never rendered and never assigned from a form.**
 */
final class ManageScreensTest extends WebTestCase
{
    use Factories;
    use SigningOut;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    // ------------------------------------------------------------ sections

    public function testAnEditorCreatesASection(): void
    {
        $this->signIn([User::ROLE_EDITOR]);

        $this->submit('/admin/manage/sections/new', 'Create', [
            'section[name]' => 'Long Reads',
            'section[description]' => 'The ones that take a while.',
        ]);

        $section = $this->onlySection();

        self::assertSame('Long Reads', $section->getName());
        self::assertSame('The ones that take a while.', $section->getDescription());
    }

    /**
     * The address is generated, and no screen offers a field for it.
     */
    public function testASectionsAddressIsGeneratedFromItsName(): void
    {
        $this->signIn([User::ROLE_EDITOR]);

        $crawler = $this->client->request('GET', '/admin/manage/sections/new');
        self::assertCount(0, $crawler->filter('input[name="section[slug]"]'));

        $this->submit('/admin/manage/sections/new', 'Create', ['section[name]' => 'Long Reads']);

        self::assertSame('long-reads', $this->onlySection()->getSlug());
    }

    /**
     * And it stops moving. Every link a reader has to a section is that address.
     */
    public function testRenamingASectionDoesNotMoveItsAddress(): void
    {
        $this->signIn([User::ROLE_EDITOR]);
        $section = CategoryFactory::createOne(['name' => 'News', 'slug' => 'news']);

        $this->submit(
            '/admin/manage/sections/'.$section->getId().'/edit',
            'Save',
            ['section[name]' => 'Bulletins'],
        );

        $reloaded = $this->reloadSection($section->getId());

        self::assertSame('Bulletins', $reloaded->getName());
        self::assertSame('news', $reloaded->getSlug());
    }

    /**
     * The assertion the deletion rule exists for, and it is about what
     * survived.
     */
    public function testDeletingASectionUncategorisesItsArticlesRatherThanDestroyingThem(): void
    {
        $this->signIn([User::ROLE_EDITOR]);

        $section = CategoryFactory::createOne(['name' => 'News', 'slug' => 'news']);
        ArticleFactory::createMany(3, ['category' => $section, 'title' => 'Still here']);

        $this->delete('/admin/manage/sections/'.$section->getId(), 'Delete this section');

        $articles = $this->entityManager()->getRepository(Article::class)->findAll();

        self::assertCount(3, $articles);

        foreach ($articles as $article) {
            self::assertNull($article->getCategory());
        }
    }

    public function testDeletingASectionMovesItsSubsectionsUpRatherThanToTheTop(): void
    {
        $this->signIn([User::ROLE_EDITOR]);

        $grandparent = CategoryFactory::createOne(['name' => 'Everything', 'slug' => 'everything']);
        $parent = CategoryFactory::createOne(['name' => 'News', 'slug' => 'news', 'parent' => $grandparent]);
        $child = CategoryFactory::createOne(['name' => 'Local', 'slug' => 'local', 'parent' => $parent]);

        $this->delete('/admin/manage/sections/'.$parent->getId(), 'Delete this section');

        $reloaded = $this->reloadSection($child->getId());

        self::assertNotNull($reloaded->getParent());
        self::assertSame('Everything', $reloaded->getParent()->getName());
    }

    /**
     * A section cannot be put inside its own subsection, and saying so is not an
     * error page.
     *
     * The parent list offers this section's own children, so the wrong choice is
     * one click away — and until a review found it, that click was a 500. The
     * entity had always refused the cycle and carried a sentence explaining it;
     * nothing here caught the exception, where the page screen next door always
     * had.
     */
    public function testASectionCannotBePutInsideItsOwnSubsection(): void
    {
        $this->signIn([User::ROLE_EDITOR]);

        $parent = CategoryFactory::createOne(['name' => 'News', 'slug' => 'news']);
        $child = CategoryFactory::createOne(['name' => 'Local', 'slug' => 'local', 'parent' => $parent]);

        $crawler = $this->client->request('GET', '/admin/manage/sections/'.$parent->getId().'/edit');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Save')->form();
        $values = $form->getPhpValues();
        $fields = $values['section'] ?? [];
        self::assertIsArray($fields);

        $fields['name'] = 'News';
        $fields['parent'] = (string) $child->getId();
        $values['section'] = $fields;

        $this->client->request('POST', $form->getUri(), $values);

        self::assertResponseIsSuccessful();
        self::assertNull($this->reloadSection($parent->getId())->getParent());
    }

    // -------------------------------------------------------------- labels

    public function testAnEditorCreatesALabel(): void
    {
        $this->signIn([User::ROLE_EDITOR]);

        $this->submit('/admin/manage/labels/new', 'Create', ['label[name]' => 'Doctrine']);

        $label = $this->onlyLabel();

        self::assertSame('Doctrine', $label->getName());
        self::assertSame('doctrine', $label->getSlug());
    }

    public function testDeletingALabelLeavesTheArticlesThatCarriedIt(): void
    {
        $this->signIn([User::ROLE_EDITOR]);

        $label = TagFactory::createOne(['name' => 'Doctrine', 'slug' => 'doctrine']);
        $article = ArticleFactory::createOne(['title' => 'Still here']);

        $article->addTag($label);
        $this->entityManager()->flush();

        $this->delete('/admin/manage/labels/'.$label->getId(), 'Delete this label');

        self::assertCount(1, $this->entityManager()->getRepository(Article::class)->findAll());
        self::assertCount(0, $this->labels()->findAll());
    }

    // ------------------------------------------------------------ accounts

    public function testAnAdministratorCreatesAnAccountThatCanSignIn(): void
    {
        $this->signIn([User::ROLE_ADMIN]);

        $crawler = $this->client->request('GET', '/admin/manage/accounts/new');
        $form = $crawler->selectButton('Create')->form([
            'account[email]' => 'newcomer@example.com',
            'account[displayName]' => 'A Newcomer',
            'account[password]' => 'a-perfectly-good-password',
        ]);

        // The permissions are added to the submitted values rather than set on
        // the form. Expanded multiple choice renders one checkbox per role, all
        // sharing the name `account[roles][]`, and the crawler will only accept
        // a value the *first* of them offers.
        $values = $form->getPhpValues();
        $account = $values['account'] ?? [];
        self::assertIsArray($account);
        $account['roles'] = [User::ROLE_EDITOR];
        $values['account'] = $account;

        $this->client->request($form->getMethod(), $form->getUri(), $values);
        self::assertResponseRedirects();

        $created = $this->accounts()->findOneByEmail('newcomer@example.com');
        self::assertInstanceOf(User::class, $created);
        self::assertContains(User::ROLE_EDITOR, $created->getRoles());

        // The password works, which is the only proof that it was hashed rather
        // than stored as typed or dropped.
        $this->signOut();
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Sign in')->form([
            '_username' => 'newcomer@example.com',
            '_password' => 'a-perfectly-good-password',
        ]));

        self::assertResponseRedirects();
    }

    /**
     * Blank means unchanged. An edit form that demanded a password to save a
     * display name would train people to retype one, and a retyped password is
     * a weaker password.
     */
    public function testEditingAnAccountWithoutAPasswordLeavesTheStoredOneAlone(): void
    {
        $this->signIn([User::ROLE_ADMIN]);
        $account = UserFactory::new()->editor()->withPassword()->create(['email' => 'editor@example.com']);
        $before = $account->getPassword();

        $this->submit('/admin/manage/accounts/'.$account->getId().'/edit', 'Save', [
            'account[email]' => 'editor@example.com',
            'account[displayName]' => 'A New Name',
            'account[password]' => '',
        ]);

        $reloaded = $this->reloadAccount($account->getId());

        self::assertSame('A New Name', $reloaded->getDisplayName());
        self::assertSame($before, $reloaded->getPassword());
    }

    /**
     * The one thing no response may ever contain.
     */
    public function testNoScreenRendersAStoredHash(): void
    {
        $this->signIn([User::ROLE_ADMIN]);
        $account = UserFactory::new()->editor()->withPassword()->create(['email' => 'editor@example.com']);

        foreach (['/admin/manage/accounts', '/admin/manage/accounts/'.$account->getId().'/edit'] as $address) {
            $this->client->request('GET', $address);

            self::assertStringNotContainsString(
                $account->getPassword(),
                (string) $this->client->getResponse()->getContent(),
                sprintf('%s rendered the stored hash.', $address),
            );
        }
    }

    /**
     * One administrator on a fresh installation deleting themselves leaves a
     * site nobody can administer. The route refuses it, and the screen does not
     * offer it.
     */
    public function testAnAdministratorCannotDeleteTheirOwnAccount(): void
    {
        $account = $this->signIn([User::ROLE_ADMIN]);

        $crawler = $this->client->request('GET', '/admin/manage/accounts/'.$account->getId().'/edit');
        self::assertCount(0, $crawler->filter('form[action$="/delete"]'));

        $this->client->request('POST', '/admin/manage/accounts/'.$account->getId().'/delete', [
            '_token' => 'anything',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertNotNull($this->accounts()->findOneByEmail($account->getEmail()));
    }

    /**
     * An account that owns content is refused with a sentence naming what it
     * owns, where the database constraint alone would answer with a foreign-key
     * name.
     */
    public function testAnAccountThatOwnsArticlesIsRefusedWithAnExplanation(): void
    {
        $this->signIn([User::ROLE_ADMIN]);

        $author = UserFactory::new()->author()->create(['email' => 'author@example.com']);
        ArticleFactory::createOne(['author' => $author]);

        $crawler = $this->client->request('GET', '/admin/manage/accounts/'.$author->getId().'/edit');

        self::assertStringContainsString(
            'cannot be removed while it owns any',
            $crawler->filter('main')->text(),
        );

        // No button either — offering one that always fails is worse than not
        // offering it. The rule itself lives in UserDeleter and is proven
        // against the database in
        // tests/Integration/Service/Account/UserDeleterTest.php; what this
        // asserts is that the screen tells the truth about it.
        self::assertCount(0, $crawler->filter('form[action$="/delete"]'));
    }

    // ---------------------------------------------------------- permissions

    /**
     * Anonymous first, then the wrong role — `docs/testing.md` asks for both on
     * every protected route.
     */
    public function testEveryScreenIsClosedToSomebodyNotSignedIn(): void
    {
        foreach ($this->everyScreen() as $address) {
            $this->client->request('GET', $address);

            self::assertResponseRedirects(message: sprintf('%s was open to anybody.', $address));
        }
    }

    public function testAnAuthorReachesNoneOfThem(): void
    {
        $this->signIn([User::ROLE_AUTHOR]);

        foreach ($this->everyScreen() as $address) {
            $this->client->request('GET', $address);

            self::assertResponseStatusCodeSame(403, sprintf('An author reached %s.', $address));
        }
    }

    /**
     * An editor runs the site; deciding who may run it is a different
     * authority.
     */
    public function testAnEditorReachesTaxonomyButNotAccounts(): void
    {
        $this->signIn([User::ROLE_EDITOR]);

        foreach (['/admin/manage', '/admin/manage/sections', '/admin/manage/labels'] as $address) {
            $this->client->request('GET', $address);
            self::assertResponseIsSuccessful(sprintf('An editor was refused %s.', $address));
        }

        foreach (['/admin/manage/accounts', '/admin/manage/accounts/new'] as $address) {
            $this->client->request('GET', $address);
            self::assertResponseStatusCodeSame(403, sprintf('An editor reached %s.', $address));
        }
    }

    /**
     * The screens are part of the same administration area as everything else,
     * which was the whole reason for replacing the generic ones.
     */
    public function testTheScreensCarryTheSameNavigationAsTheRest(): void
    {
        $this->signIn([User::ROLE_ADMIN]);

        foreach (['/admin/manage', '/admin/manage/sections', '/admin/manage/accounts'] as $address) {
            $crawler = $this->client->request('GET', $address);

            self::assertGreaterThan(
                0,
                $crawler->filter('header a[href="/admin/articles"]')->count(),
                sprintf('%s does not carry the administration navigation.', $address),
            );
        }
    }

    // ------------------------------------------------------------- helpers

    /**
     * @return list<string>
     */
    private function everyScreen(): array
    {
        return [
            '/admin/manage',
            '/admin/manage/sections',
            '/admin/manage/sections/new',
            '/admin/manage/labels',
            '/admin/manage/labels/new',
            '/admin/manage/accounts',
            '/admin/manage/accounts/new',
        ];
    }

    /**
     * @param array<string, mixed> $values
     */
    private function submit(string $address, string $button, array $values): void
    {
        $crawler = $this->client->request('GET', $address);
        self::assertResponseIsSuccessful(sprintf('%s did not open.', $address));

        $this->client->submit($crawler->selectButton($button)->form($values));

        self::assertTrue(
            $this->client->getResponse()->isRedirection(),
            sprintf('Submitting %s answered %d.', $address, $this->client->getResponse()->getStatusCode()),
        );
    }

    private function delete(string $base, string $button): void
    {
        $crawler = $this->client->request('GET', $base.'/edit');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->selectButton($button)->form());
    }

    private function onlySection(): Category
    {
        $all = $this->sections()->findAll();
        self::assertCount(1, $all);

        return $all[0];
    }

    private function onlyLabel(): Tag
    {
        $all = $this->labels()->findAll();
        self::assertCount(1, $all);

        return $all[0];
    }

    private function reloadSection(?int $id): Category
    {
        $section = $this->sections()->find($id);
        self::assertInstanceOf(Category::class, $section);

        return $section;
    }

    private function reloadAccount(?int $id): User
    {
        $account = $this->accounts()->find($id);
        self::assertInstanceOf(User::class, $account);

        return $account;
    }

    private function sections(): CategoryRepository
    {
        $this->entityManager()->clear();

        $repository = self::getContainer()->get(CategoryRepository::class);
        self::assertInstanceOf(CategoryRepository::class, $repository);

        return $repository;
    }

    private function labels(): TagRepository
    {
        $this->entityManager()->clear();

        $repository = self::getContainer()->get(TagRepository::class);
        self::assertInstanceOf(TagRepository::class, $repository);

        return $repository;
    }

    private function accounts(): UserRepository
    {
        $this->entityManager()->clear();

        $repository = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);

        return $repository;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    /**
     * @param list<string> $roles
     */
    private function signIn(array $roles): User
    {
        $account = UserFactory::new()->withPassword()->create([
            'email' => 'person@example.com',
            'roles' => $roles,
        ]);

        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Sign in')->form([
            '_username' => 'person@example.com',
            '_password' => UserFactory::DEVELOPMENT_PASSWORD,
        ]));
        $this->client->followRedirect();

        return $account;
    }
}
