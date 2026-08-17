<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Article;
use App\Factory\UserFactory;
use App\Repository\ArticleRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;

use function sprintf;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The obligation feature 002 recorded and this feature inherits.
 *
 * Every assertion here reads the article back out of the database and looks at
 * what was **stored**. That is the whole design of the test.
 *
 * A test that submitted hostile markup and then checked the rendered admin page
 * for a script tag would pass with the sanitiser deleted, because Twig escapes
 * output by default — it would be testing Twig. The question that matters is
 * what a reader eventually receives, and a reader receives what is stored,
 * rendered with `|raw`.
 *
 * The submission goes through the real form, not through the service, because
 * the requirement is that the *screen* cannot be used to store an attack.
 */
final class SanitisingOnStoreTest extends WebTestCase
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
    public static function hostileBodyProvider(): iterable
    {
        yield 'a script element' => ['<p>Fine.</p><script>steal()</script>', 'steal()'];
        yield 'an onclick handler' => ['<p onclick="steal()">Words</p>', 'onclick'];
        yield 'an image onerror handler' => ['<img src="x" onerror="steal()">', 'onerror'];
        yield 'a javascript link' => ['<a href="javascript:steal()">Read</a>', 'javascript:'];
        yield 'an inline frame' => ['<iframe src="https://elsewhere.example"></iframe>', 'iframe'];
        yield 'an object' => ['<object data="x.swf"></object>', '<object'];
        yield 'a style element' => ['<style>body{display:none}</style>', 'display:none'];
        yield 'a form asking for a password' => ['<form><input name="password"></form>', '<form'];
        yield 'a data URL image' => ['<img src="data:text/html,<script>steal()</script>">', 'data:text/html'];
        yield 'svg with script' => ['<svg><script>steal()</script></svg>', 'steal()'];
    }

    /**
     * The attack an author would actually mount: they cannot publish, so the
     * target is the editor who opens their draft to review it. If the script
     * runs there, it runs with an editor's session and can publish anything.
     */
    #[DataProvider('hostileBodyProvider')]
    public function testHostileMarkupIsNeverStored(string $submitted, string $mustNotBeStored): void
    {
        UserFactory::new()->author()->withPassword()->create(['email' => 'author@example.com']);
        $this->signIn('author@example.com');

        $this->submitNewArticle('An article', $submitted);

        self::assertStringNotContainsStringIgnoringCase(
            $mustNotBeStored,
            $this->storedBodyOf('An article'),
        );
    }

    public function testOrdinaryFormattingIsStoredIntact(): void
    {
        UserFactory::new()->author()->withPassword()->create(['email' => 'author@example.com']);
        $this->signIn('author@example.com');

        $this->submitNewArticle(
            'A formatted article',
            '<h2>A heading</h2><p>With <strong>emphasis</strong> and <a href="https://example.com">a link</a>.</p>'
            .'<ul><li>One</li><li>Two</li></ul><blockquote><p>Quoted.</p></blockquote>',
        );

        $stored = $this->storedBodyOf('A formatted article');

        foreach (['<h2>', '<strong>', 'https://example.com', '<ul>', '<li>', '<blockquote>'] as $expected) {
            self::assertStringContainsString($expected, $stored, sprintf('%s did not survive.', $expected));
        }
    }

    /**
     * The text around an attack is the author's work and must not be lost with it.
     */
    public function testTheAuthorsWordsSurviveTheRemoval(): void
    {
        UserFactory::new()->author()->withPassword()->create(['email' => 'author@example.com']);
        $this->signIn('author@example.com');

        $this->submitNewArticle('Mixed', '<p>Before.</p><script>steal()</script><p>After.</p>');

        $stored = $this->storedBodyOf('Mixed');

        self::assertStringContainsString('Before.', $stored);
        self::assertStringContainsString('After.', $stored);
        self::assertStringNotContainsString('steal()', $stored);
    }

    /**
     * FR-005: a title is text, everywhere it is rendered.
     */
    public function testATitleNeverStoresMarkup(): void
    {
        UserFactory::new()->author()->withPassword()->create(['email' => 'author@example.com']);
        $this->signIn('author@example.com');

        $this->submitNewArticle('Title <script>steal()</script> here', '<p>Body.</p>');

        $title = $this->onlyArticle()->getTitle();

        // No markup of any kind survives. The words inside the tag do — the
        // stored title is "Title steal() here" — and that is right: a title is
        // rendered as text everywhere, so `steal()` in one is a peculiar choice
        // of words and nothing more. Asserting the absence of the word rather
        // than of the markup was this test's first, wrong, version.
        self::assertStringNotContainsString('<', $title);
        self::assertStringNotContainsString('>', $title);
        self::assertStringNotContainsString('script', $title);
    }

    public function testASummaryNeverStoresMarkup(): void
    {
        UserFactory::new()->author()->withPassword()->create(['email' => 'author@example.com']);
        $this->signIn('author@example.com');

        $this->submitNewArticle('An article', '<p>Body.</p>', '<script>steal()</script>A summary');

        $article = $this->onlyArticle();

        self::assertNotNull($article->getExcerpt());
        self::assertStringNotContainsString('<script', $article->getExcerpt());
        self::assertStringContainsString('A summary', $article->getExcerpt());
    }

    /**
     * FR-004 and the end-to-end proof: what a reader is served carries no attack.
     *
     * Rendered with `|raw`, which is correct precisely because the text was
     * sanitised on the way in.
     */
    public function testAReaderIsNeverServedTheAttack(): void
    {
        UserFactory::new()->editor()->withPassword()->create(['email' => 'editor@example.com']);
        $this->signIn('editor@example.com');

        $this->submitNewArticle('An article', '<p>Fine.</p><script>steal()</script>');

        $article = $this->onlyArticle();
        $this->publish($article);

        $this->client->request('GET', '/articles/'.$article->getSlug());

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('steal()', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('Fine.', (string) $this->client->getResponse()->getContent());
    }

    /**
     * Editing is a second storage path and must sanitise too. An edit screen
     * that trusted what was already there would be a way in for anybody who
     * could get an attack past the create screen once.
     */
    public function testEditingSanitisesAsWellAsCreating(): void
    {
        UserFactory::new()->author()->withPassword()->create(['email' => 'author@example.com']);
        $this->signIn('author@example.com');

        $this->submitNewArticle('An article', '<p>Harmless.</p>');
        $article = $this->onlyArticle();

        $crawler = $this->client->request('GET', '/admin/articles/'.$article->getId().'/edit');
        $this->client->submit($crawler->selectButton('Save')->form([
            'article[title]' => 'An article',
            'article[content]' => '<p>Still fine.</p><script>steal()</script>',
        ]));

        self::assertStringNotContainsString('steal()', $this->storedBodyOf('An article'));
    }

    private function signIn(string $email): void
    {
        $crawler = $this->client->request('GET', '/login');

        $this->client->submit($crawler->selectButton('Sign in')->form([
            '_username' => $email,
            '_password' => UserFactory::DEVELOPMENT_PASSWORD,
        ]));
        $this->client->followRedirect();
    }

    /**
     * Submits the create form and insists it worked.
     *
     * The check matters: without it, a form that came back with a validation
     * error fails four lines later as "expected 1 article, found 0", which says
     * nothing about why. Naming the errors here turns a puzzle into a sentence.
     */
    private function submitNewArticle(string $title, string $body, string $summary = ''): void
    {
        $crawler = $this->client->request('GET', '/admin/articles/new');

        $crawler = $this->client->submit($crawler->selectButton('Create')->form([
            'article[title]' => $title,
            'article[excerpt]' => $summary,
            'article[content]' => $body,
        ]));

        if (!$this->client->getResponse()->isRedirection()) {
            $errors = $crawler->filter('.form-error-message, ul.form-error-message li, [role="alert"]')
                ->each(static fn ($node): string => trim($node->text()));

            self::fail(sprintf(
                'Creating the article did not succeed (status %d). Errors: %s',
                $this->client->getResponse()->getStatusCode(),
                [] === $errors ? 'none reported' : implode(' | ', $errors),
            ));
        }
    }

    /**
     * Publishes through the screen, by pressing the button.
     *
     * The first version asked the container for a CSRF token, which fails
     * outside a request because the token storage is the session — and would
     * have been testing a token this application never issued. Taking it from
     * the rendered page is both simpler and closer to what happens.
     */
    private function publish(Article $article): void
    {
        $crawler = $this->client->request('GET', '/admin/articles/'.$article->getId().'/edit');

        $this->client->submit($crawler->selectButton('Publish')->form());
    }

    private function storedBodyOf(string $title): string
    {
        return $this->articleTitled($title)->getContent();
    }

    private function onlyArticle(): Article
    {
        $all = $this->repository()->findAll();
        self::assertCount(1, $all);

        return $all[0];
    }

    private function articleTitled(string $title): Article
    {
        foreach ($this->repository()->findAll() as $article) {
            if ($article->getTitle() === $title) {
                return $article;
            }
        }

        self::fail(sprintf('No article titled "%s" was stored.', $title));
    }

    private function repository(): ArticleRepository
    {
        // Cleared first, so what comes back is read from the database rather
        // than handed out of the identity map — otherwise this would assert on
        // the object the request left behind, not on what was written.
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->clear();

        $repository = self::getContainer()->get(ArticleRepository::class);
        self::assertInstanceOf(ArticleRepository::class, $repository);

        return $repository;
    }
}
