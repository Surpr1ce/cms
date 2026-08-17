<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\ContentStatus;
use App\Entity\Page;
use App\Entity\User;
use App\Factory\PageFactory;
use App\Factory\UserFactory;
use App\Repository\PageRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Test\Factories;

/**
 * Writing, publishing and deleting standalone pages.
 *
 * Articles have had a test of their own since feature 004; pages never did, and
 * the screens are not the same. A page has no author and no labels, but it has
 * three things an article does not — a place in the menu, a parent, and a rule
 * that it cannot be deleted while something hangs off it.
 *
 * The four rules asserted here are the ones that would be quiet if they broke:
 * an address freezes at publication, a page cannot become its own ancestor, a
 * page with children refuses deletion with a sentence rather than a foreign-key
 * error, and every one of these addresses is editorial.
 */
final class PageAdministrationTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testAnEditorCreatesAPageAsADraft(): void
    {
        $this->signIn([User::ROLE_EDITOR]);

        $crawler = $this->client->request('GET', '/admin/pages/new');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->selectButton('Create')->form([
            'page[title]' => 'Opening hours',
            'page[content]' => '<p>Nine to five.</p>',
            'page[menuOrder]' => 40,
        ]));

        self::assertResponseRedirects();

        $page = $this->onlyPage();

        self::assertSame('Opening hours', $page->getTitle());
        self::assertSame('opening-hours', $page->getSlug());
        self::assertSame(ContentStatus::Draft, $page->getStatus());
        self::assertSame(40, $page->getMenuOrder());
    }

    public function testRenamingADraftMovesItsAddress(): void
    {
        $this->signIn([User::ROLE_EDITOR]);
        $page = PageFactory::createOne(['title' => 'Openign hours', 'slug' => 'openign-hours']);

        $this->submitEdit($page, ['page[title]' => 'Opening hours']);

        self::assertSame('opening-hours', $this->reload($page)->getSlug());
    }

    /**
     * The rule that matters more, and the one that is silent when it breaks:
     * once readers have an address, renaming must not take it away from them.
     */
    public function testRenamingAPublishedPageLeavesItsAddressAlone(): void
    {
        $this->signIn([User::ROLE_EDITOR]);
        $page = PageFactory::new()->published()->create(['title' => 'Opening hours', 'slug' => 'opening-hours']);

        $this->submitEdit($page, ['page[title]' => 'When we are open']);

        $reloaded = $this->reload($page);

        self::assertSame('When we are open', $reloaded->getTitle());
        self::assertSame('opening-hours', $reloaded->getSlug());
    }

    public function testAPageCanBePublishedAndTakenDownAgain(): void
    {
        $this->signIn([User::ROLE_EDITOR]);
        $page = PageFactory::createOne(['title' => 'Opening hours', 'content' => '<p>Nine to five.</p>']);

        $this->transition($page, 'Publish');
        self::assertSame(ContentStatus::Published, $this->reload($page)->getStatus());

        $this->transition($page, 'Unpublish');
        self::assertSame(ContentStatus::Draft, $this->reload($page)->getStatus());
    }

    /**
     * A refused transition says why and changes nothing — the entity's rule, seen
     * from the screen.
     */
    public function testARefusedTransitionSaysSoRatherThanFailing(): void
    {
        $this->signIn([User::ROLE_EDITOR]);
        $page = PageFactory::createOne(['title' => 'Empty', 'content' => '']);

        $this->transition($page, 'Publish');

        self::assertSame(ContentStatus::Draft, $this->reload($page)->getStatus());

        // The message is the half a person actually sees, so it is followed and
        // read rather than inferred from a redirect. Without this the sentence
        // could be deleted and the test would stay green.
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();

        self::assertMatchesRegularExpression(
            '/body|content|empty/i',
            $crawler->filter('[role="alert"]')->text(),
        );
    }

    /**
     * The parent list on the edit screen offers this page's own descendants, so
     * the wrong choice is one click away. The entity refuses it; this asserts the
     * screen says so rather than answering with an error page.
     */
    public function testAPageCannotBeMadeItsOwnDescendant(): void
    {
        $this->signIn([User::ROLE_EDITOR]);

        // The page under test already has a parent of its own, so "its parent did
        // not change" is a claim that can fail. With a top-level page the
        // assertion was already true before the request, and a screen that
        // silently discarded every parent would have passed it.
        $grandparent = PageFactory::createOne(['title' => 'Company', 'slug' => 'company']);
        $parent = PageFactory::createOne(['title' => 'About us', 'slug' => 'about-us', 'parent' => $grandparent]);
        $child = PageFactory::createOne(['title' => 'Our team', 'slug' => 'our-team', 'parent' => $parent]);

        $this->submitEdit($parent, ['page[parent]' => (string) $child->getId()]);

        self::assertResponseIsSuccessful();

        $reloaded = $this->reload($parent);

        self::assertNotNull($reloaded->getParent());
        self::assertSame('Company', $reloaded->getParent()->getTitle());
    }

    /**
     * And the same screen still accepts a parent that is not a descendant, so
     * the refusal above is a rule rather than the field being broken.
     */
    public function testAPageCanStillBeGivenAnOrdinaryParent(): void
    {
        $this->signIn([User::ROLE_EDITOR]);

        $parent = PageFactory::createOne(['title' => 'About us', 'slug' => 'about-us']);
        $orphan = PageFactory::createOne(['title' => 'Our team', 'slug' => 'our-team']);

        $this->submitEdit($orphan, ['page[parent]' => (string) $parent->getId()]);

        $reloaded = $this->reload($orphan);

        self::assertNotNull($reloaded->getParent());
        self::assertSame('About us', $reloaded->getParent()->getTitle());
    }

    /**
     * FR-017. The database constraint alone would answer with a foreign-key
     * name; the refusal here counts what is in the way and says so.
     */
    public function testAPageWithChildrenRefusesToBeDeleted(): void
    {
        $this->signIn([User::ROLE_EDITOR]);

        $parent = PageFactory::createOne(['title' => 'About us', 'slug' => 'about-us']);
        PageFactory::createOne(['title' => 'Our team', 'slug' => 'our-team', 'parent' => $parent]);

        $crawler = $this->client->request('GET', '/admin/pages/'.$parent->getId().'/edit');
        $this->client->submit($crawler->selectButton('Delete this page')->form());

        self::assertResponseRedirects();
        self::assertCount(2, $this->pages()->findAll());

        // The count proves nothing was destroyed; this proves somebody was told
        // why, which is the part FR-017 is actually about. The refusal names the
        // page and says how many are in the way, so a database constraint's
        // answer — a foreign-key name — would not pass this.
        $message = $this->client->followRedirect()->filter('[role="alert"]')->text();

        self::assertStringContainsString('About us', $message);
        self::assertStringContainsString('1 child', $message);
    }

    public function testAPageWithNothingHangingOffItIsDeleted(): void
    {
        $this->signIn([User::ROLE_EDITOR]);
        $page = PageFactory::createOne(['title' => 'Obsolete', 'slug' => 'obsolete']);

        $crawler = $this->client->request('GET', '/admin/pages/'.$page->getId().'/edit');
        $this->client->submit($crawler->selectButton('Delete this page')->form());

        self::assertResponseRedirects('/admin/pages');
        self::assertCount(0, $this->pages()->findAll());
    }

    public function testTheListShowsEveryPageWhateverItsState(): void
    {
        $this->signIn([User::ROLE_EDITOR]);

        PageFactory::new()->published()->create(['title' => 'Published one', 'slug' => 'published-one']);
        PageFactory::createOne(['title' => 'Draft one', 'slug' => 'draft-one']);

        $crawler = $this->client->request('GET', '/admin/pages');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Published one', $crawler->filter('main')->text());
        self::assertStringContainsString('Draft one', $crawler->filter('main')->text());
    }

    /**
     * A page has no owner, so there is nothing for an author to own. Submitted
     * directly rather than looked for as an absent link, because a hidden control
     * is a courtesy and the check is the permission.
     */
    public function testAnAuthorReachesNoneOfIt(): void
    {
        $this->signIn([User::ROLE_AUTHOR]);
        $page = PageFactory::createOne(['title' => 'Opening hours', 'slug' => 'opening-hours']);

        foreach (['/admin/pages', '/admin/pages/new', '/admin/pages/'.$page->getId().'/edit'] as $address) {
            $this->client->request('GET', $address);

            self::assertResponseStatusCodeSame(
                Response::HTTP_FORBIDDEN,
                $address.' was not refused.',
            );
        }
    }

    // -------------------------------------------------------------- helpers

    /**
     * @param array<string, string> $values
     */
    private function submitEdit(Page $page, array $values): void
    {
        $crawler = $this->client->request('GET', '/admin/pages/'.$page->getId().'/edit');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->selectButton('Save')->form($values));
    }

    private function transition(Page $page, string $button): void
    {
        $crawler = $this->client->request('GET', '/admin/pages/'.$page->getId().'/edit');
        $control = $crawler->selectButton($button);

        self::assertGreaterThan(0, $control->count(), 'There is no "'.$button.'" control on the screen.');

        $this->client->submit($control->form());
    }

    private function onlyPage(): Page
    {
        $all = $this->pages()->findAll();
        self::assertCount(1, $all);

        return $all[0];
    }

    private function reload(Page $page): Page
    {
        $reloaded = $this->pages()->find($page->getId());
        self::assertInstanceOf(Page::class, $reloaded);

        return $reloaded;
    }

    private function pages(): PageRepository
    {
        $pages = self::getContainer()->get(PageRepository::class);
        self::assertInstanceOf(PageRepository::class, $pages);

        return $pages;
    }

    /**
     * @param list<string> $roles
     */
    private function signIn(array $roles): void
    {
        UserFactory::new()->withPassword()->create([
            'email' => 'person@example.com',
            'roles' => $roles,
        ]);

        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Sign in')->form([
            '_username' => 'person@example.com',
            '_password' => UserFactory::DEVELOPMENT_PASSWORD,
        ]));
        $this->client->followRedirect();
    }
}
