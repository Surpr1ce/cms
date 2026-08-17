<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Article;
use App\Entity\Page;
use App\Entity\User;
use App\Factory\ArticleFactory;
use App\Factory\PageFactory;
use App\Factory\UserFactory;
use App\Repository\ArticleRepository;
use App\Repository\PageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Test\Factories;

/**
 * Two people editing the same thing.
 *
 * Every test here opens **two forms before either is saved**, which is the only
 * arrangement that can tell a working check from a decorative one. A version
 * read back out of the entity at save time always matches itself; a check that
 * runs after the changes are applied refuses with the damage already staged; a
 * version that never advances makes nothing stale. All three pass a test that
 * opens one form.
 *
 * And the assertion that matters is never "the second save was refused". It is
 * **what is stored afterwards** — because a refusal that still wrote is exactly
 * the failure this feature exists to prevent.
 */
final class ConcurrentEditingTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    /**
     * US1, and the assertion this whole file exists for.
     */
    public function testTheSecondSaveIsRefusedAndTheFirstEditorsWorkSurvives(): void
    {
        $this->signIn();
        $article = ArticleFactory::createOne(['title' => 'The original']);

        // Both forms are opened before either is submitted. This is the whole
        // scenario; opening the second one afterwards would test nothing.
        $first = $this->openArticleForm($article->getId());
        $second = $this->openArticleForm($article->getId());

        $this->client->submit($first, ['article[title]' => 'What the first editor wrote']);
        self::assertResponseRedirects();

        $this->client->submit($second, ['article[title]' => 'What the second editor wrote']);

        self::assertSame('What the first editor wrote', $this->reloadArticle($article->getId())->getTitle());
    }

    /**
     * FR-004. A refusal that applied half the form would be worse than none.
     */
    public function testARefusedSaveChangesNothingAtAll(): void
    {
        $this->signIn();
        $article = ArticleFactory::createOne([
            'title' => 'The original',
            'content' => '<p>The original body.</p>',
            'excerpt' => 'The original excerpt.',
        ]);

        $first = $this->openArticleForm($article->getId());
        $second = $this->openArticleForm($article->getId());

        $this->client->submit($first, ['article[title]' => 'Saved first']);
        $this->client->submit($second, [
            'article[title]' => 'Never stored',
            'article[content]' => '<p>Never stored either.</p>',
            'article[excerpt]' => 'Nor this.',
        ]);

        $stored = $this->reloadArticle($article->getId());

        self::assertSame('Saved first', $stored->getTitle());
        self::assertSame('<p>The original body.</p>', $stored->getContent());
        self::assertSame('The original excerpt.', $stored->getExcerpt());
    }

    /**
     * FR-011, and the requirement that stops this feature being worse than the
     * problem. One person editing alone must notice nothing.
     */
    public function testOnePersonSavingRepeatedlyIsNeverRefused(): void
    {
        $this->signIn();
        $article = ArticleFactory::createOne(['title' => 'The original']);

        foreach (['First save', 'Second save', 'Third save'] as $title) {
            // Reopened each time, which is what a browser does after the
            // redirect that follows a successful save.
            $form = $this->openArticleForm($article->getId());
            $this->client->submit($form, ['article[title]' => $title]);

            self::assertResponseRedirects('/admin/articles/'.$article->getId().'/edit');
        }

        self::assertSame('Third save', $this->reloadArticle($article->getId())->getTitle());
    }

    /**
     * US1 scenario 4. A refusal is recoverable by doing the obvious thing.
     */
    public function testTheSecondEditorSucceedsAfterReloading(): void
    {
        $this->signIn();
        $article = ArticleFactory::createOne(['title' => 'The original']);

        $first = $this->openArticleForm($article->getId());
        $second = $this->openArticleForm($article->getId());

        $this->client->submit($first, ['article[title]' => 'Saved first']);
        $this->client->submit($second, ['article[title]' => 'Refused']);

        $reloaded = $this->openArticleForm($article->getId());
        $this->client->submit($reloaded, ['article[title]' => 'Saved second, properly']);

        self::assertResponseRedirects();
        self::assertSame('Saved second, properly', $this->reloadArticle($article->getId())->getTitle());
    }

    /**
     * US2. What the second editor is actually told.
     */
    public function testTheRefusalIsAWorkingScreenWithAnExplanation(): void
    {
        $this->signIn();
        $article = ArticleFactory::createOne(['title' => 'The original']);

        $first = $this->openArticleForm($article->getId());
        $second = $this->openArticleForm($article->getId());

        $this->client->submit($first, ['article[title]' => 'Saved first']);

        // Followed, because a browser would. One client is standing in for two
        // editors here, and an unfollowed redirect leaves the first editor's
        // "Saved." flash in the session — where it would then be rendered on the
        // second editor's page and make this assertion meaningless.
        $this->client->followRedirect();

        $crawler = $this->client->submit($second, ['article[title]' => 'Refused']);

        // FR-007. 409 rather than 200, because the submission genuinely was
        // refused and a status that said otherwise would be the same kind of
        // lie as the "Saved." this feature exists to remove. It is still a whole
        // working screen — what FR-007 forbids is a stack trace.
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);

        // FR-006.
        self::assertStringContainsString(
            'somebody else',
            strtolower($crawler->filter('[role="alert"]')->text()),
        );

        // FR-006 from the other side: no success message anywhere on the page.
        // The layout renders a success flash as role="status" and a refusal as
        // role="alert", so this asks whether anything reported success at all
        // rather than picking at the wording of the refusal.
        self::assertCount(0, $crawler->filter('[role="status"]'));
    }

    /**
     * FR-005. Refusing a save must not also throw away what was typed.
     */
    public function testWhatTheEditorTypedIsStillInTheForm(): void
    {
        $this->signIn();
        $article = ArticleFactory::createOne(['title' => 'The original']);

        $first = $this->openArticleForm($article->getId());
        $second = $this->openArticleForm($article->getId());

        $this->client->submit($first, ['article[title]' => 'Saved first']);
        $crawler = $this->client->submit($second, [
            'article[title]' => 'An hour of work',
            'article[content]' => '<p>A great deal of typing.</p>',
        ]);

        self::assertSame(
            'An hour of work',
            $crawler->filter('input[name="article[title]"]')->attr('value'),
        );
        self::assertStringContainsString(
            'A great deal of typing.',
            $crawler->filter('textarea[name="article[content]"]')->text(),
        );
    }

    /**
     * FR-008. The version travels through the browser, so it is under somebody
     * else's control and cannot be trusted to be present or honest.
     */
    public function testASubmissionWithNoVersionIsRefused(): void
    {
        $this->signIn();
        $article = ArticleFactory::createOne(['title' => 'The original']);

        $form = $this->openArticleForm($article->getId());
        $values = $form->getPhpValues();

        $submitted = $values['article'] ?? [];
        self::assertIsArray($submitted);
        self::assertArrayHasKey('version', $submitted, 'The form carries no version, so nothing here is being checked.');

        unset($submitted['version']);
        $submitted['title'] = 'Sneaked past the check';
        $values['article'] = $submitted;

        $this->client->request($form->getMethod(), $form->getUri(), $values);

        self::assertSame('The original', $this->reloadArticle($article->getId())->getTitle());
    }

    public function testASubmissionCarryingAVersionThatWasNeverRealIsRefused(): void
    {
        $this->signIn();
        $article = ArticleFactory::createOne(['title' => 'The original']);

        foreach (['9999', '0', '-1'] as $forged) {
            $form = $this->openArticleForm($article->getId());

            $this->client->submit($form, [
                'article[title]' => 'Sneaked past the check',
                'article[version]' => $forged,
            ]);

            self::assertSame(
                'The original',
                $this->reloadArticle($article->getId())->getTitle(),
                'A version of "'.$forged.'" was accepted.',
            );
        }
    }

    /**
     * FR-009. Creating has no earlier version to conflict with, and a check
     * there would refuse the first article anybody ever wrote.
     */
    public function testCreatingIsUnaffected(): void
    {
        $this->signIn();

        $crawler = $this->client->request('GET', '/admin/articles/new');

        $this->client->submit($crawler->selectButton('Create')->form([
            'article[title]' => 'A brand new article',
            'article[content]' => '<p>Body.</p>',
        ]));

        self::assertResponseRedirects();
    }

    /**
     * FR-010. A publication change writes a status, not a body. Refusing to
     * publish because somebody fixed a typo is a rule nobody asked for.
     */
    public function testPublishingIsUnaffectedByAnEditInBetween(): void
    {
        $this->signIn([User::ROLE_EDITOR]);
        $article = ArticleFactory::createOne([
            'title' => 'The original',
            'content' => '<p>Long enough to publish.</p>',
        ]);

        // Open the screen, so its token belongs to a state that is about to
        // change underneath it.
        $crawler = $this->client->request('GET', '/admin/articles/'.$article->getId().'/edit');

        $form = $this->openArticleForm($article->getId());
        $this->client->submit($form, ['article[title]' => 'Somebody fixed a typo']);

        $this->client->submit($crawler->selectButton('Publish')->form());

        self::assertTrue($this->reloadArticle($article->getId())->isPublished());
    }

    /**
     * The same rule on the other kind of content, because
     * PublishableContent is shared and a rule that held for only one of them
     * would mean it had been added in the wrong place.
     */
    public function testAPageIsProtectedTheSameWay(): void
    {
        $this->signIn([User::ROLE_EDITOR]);
        $page = PageFactory::createOne(['title' => 'The original']);

        $first = $this->openPageForm($page->getId());
        $second = $this->openPageForm($page->getId());

        $this->client->submit($first, ['page[title]' => 'What the first editor wrote']);
        self::assertResponseRedirects();

        $this->client->submit($second, ['page[title]' => 'What the second editor wrote']);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('What the first editor wrote', $this->reloadPage($page->getId())->getTitle());
    }

    private function openArticleForm(?int $id): Form
    {
        self::assertNotNull($id);

        $crawler = $this->client->request('GET', '/admin/articles/'.$id.'/edit');
        self::assertResponseIsSuccessful();

        return $crawler->selectButton('Save')->form();
    }

    private function openPageForm(?int $id): Form
    {
        self::assertNotNull($id);

        $crawler = $this->client->request('GET', '/admin/pages/'.$id.'/edit');
        self::assertResponseIsSuccessful();

        return $crawler->selectButton('Save')->form();
    }

    private function reloadArticle(?int $id): Article
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->clear();

        $repository = self::getContainer()->get(ArticleRepository::class);
        self::assertInstanceOf(ArticleRepository::class, $repository);

        $article = $repository->find($id);
        self::assertInstanceOf(Article::class, $article);

        return $article;
    }

    private function reloadPage(?int $id): Page
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->clear();

        $repository = self::getContainer()->get(PageRepository::class);
        self::assertInstanceOf(PageRepository::class, $repository);

        $page = $repository->find($id);
        self::assertInstanceOf(Page::class, $page);

        return $page;
    }

    /**
     * An editor by default, because ArticleVoter grants an author permission
     * over their own work only — and the articles here are created by a factory
     * that gives them an author of their own. Ownership is feature 003's
     * subject; this file is about two people saving, whoever they are.
     *
     * @param list<string> $roles
     */
    private function signIn(array $roles = [User::ROLE_EDITOR]): User
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
}
