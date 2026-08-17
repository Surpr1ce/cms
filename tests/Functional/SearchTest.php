<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Factory\ArticleFactory;
use App\Factory\PageFactory;
use App\Factory\UserFactory;

use function str_repeat;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

use function urlencode;

use Zenstruck\Foundry\Test\Factories;

/**
 * Finding an article by a word in it — and not finding what is not published.
 *
 * This is the first delivery that does not read through a published-only
 * repository method. Every earlier one is safe structurally, because the method
 * it calls has no code path that returns a draft; a search needs a `WHERE`
 * clause of its own, and a line of SQL is a thing that can be wrong.
 *
 * So the load-bearing assertion here is not "a draft is absent from the
 * results". It is `testAWordOnlyADraftContainsIsIndistinguishableFromAWordNobodyWrote`:
 * the whole response has to be identical, because a leaked count, a leaked total
 * or a paging control appearing would each answer "does an article about this
 * exist" without ever showing it.
 */
final class SearchTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testAWordInABodyFindsTheArticle(): void
    {
        ArticleFactory::new()->published()->create([
            'title' => 'An ordinary headline',
            'slug' => 'an-ordinary-headline',
            'content' => '<p>It mentions a hippopotamus in passing.</p>',
        ]);

        $crawler = $this->search('hippopotamus');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('An ordinary headline', $crawler->filter('main')->text());
    }

    /**
     * FR-002 and SC-002. Without weighting, searching for a headline finds the
     * twelve articles that refer to it before the one that is it.
     */
    public function testATitleMatchOutranksAPassingMention(): void
    {
        ArticleFactory::new()->published()->create([
            'title' => 'A passing mention',
            'slug' => 'a-passing-mention',
            'content' => '<p>Somewhere in here the word hippopotamus appears once.</p>',
        ]);
        ArticleFactory::new()->published()->create([
            'title' => 'The hippopotamus itself',
            'slug' => 'the-hippopotamus-itself',
            'content' => '<p>An article about nothing in particular.</p>',
        ]);

        $titles = $this->resultTitles($this->search('hippopotamus'));

        self::assertSame(['The hippopotamus itself', 'A passing mention'], $titles);
    }

    /**
     * Stemming, which is the reason this uses full-text search rather than a
     * `LIKE` scan. A reader cannot be expected to guess an author's grammar.
     */
    public function testADifferentFormOfTheSameWordStillMatches(): void
    {
        ArticleFactory::new()->published()->create([
            'title' => 'On publishing',
            'slug' => 'on-publishing',
            'content' => '<p>Everything about it.</p>',
        ]);

        self::assertContains('On publishing', $this->resultTitles($this->search('published')));
    }

    public function testBothArticlesAndPagesAreFoundAndLabelled(): void
    {
        ArticleFactory::new()->published()->create([
            'title' => 'An article about hippopotamuses',
            'slug' => 'an-article',
        ]);
        PageFactory::new()->published()->create([
            'title' => 'A page about hippopotamuses',
            'slug' => 'a-page',
        ]);

        $text = $this->search('hippopotamus')->filter('main')->text();

        self::assertStringContainsString('An article about hippopotamuses', $text);
        self::assertStringContainsString('A page about hippopotamuses', $text);
        self::assertStringContainsString('Article', $text);
        self::assertStringContainsString('Page', $text);
    }

    /**
     * Markup is stripped before indexing. Without that, a body's tags are words
     * and a search for "strong" matches most of the site.
     */
    public function testMarkupIsNotSearchable(): void
    {
        ArticleFactory::new()->published()->create([
            'title' => 'A formatted article',
            'slug' => 'a-formatted-article',
            'content' => '<p>A <strong>strongly</strong> worded <em>opinion</em>.</p>',
        ]);

        self::assertSame([], $this->resultTitles($this->search('href')));
    }

    public function testResultsPageTheWayEveryOtherListingPages(): void
    {
        ArticleFactory::new()->published()->many(25)->create([
            'content' => '<p>Every one of these mentions a hippopotamus.</p>',
        ]);

        $first = $this->search('hippopotamus');
        self::assertCount(20, $first->filter('main article'));

        $second = $this->client->request('GET', '/search?q=hippopotamus&page=2');
        self::assertResponseIsSuccessful();
        self::assertCount(5, $second->filter('main article'));
    }

    // ---------------------------------------------------------------- US2

    public function testADraftIsNotFound(): void
    {
        ArticleFactory::createOne([
            'title' => 'A draft about hippopotamuses',
            'slug' => 'a-draft',
        ]);

        self::assertSame([], $this->resultTitles($this->search('hippopotamus')));
    }

    public function testAnArchivedArticleIsNotFound(): void
    {
        ArticleFactory::new()->publishedThenArchived()->create([
            'title' => 'An archived article about hippopotamuses',
            'slug' => 'an-archived-article',
        ]);

        self::assertSame([], $this->resultTitles($this->search('hippopotamus')));
    }

    public function testADraftPageIsNotFound(): void
    {
        PageFactory::createOne([
            'title' => 'A draft page about hippopotamuses',
            'slug' => 'a-draft-page',
        ]);

        self::assertSame([], $this->resultTitles($this->search('hippopotamus')));
    }

    /**
     * FR-004, SC-003, and the assertion this file exists for.
     *
     * Absence from a list is not enough. A count, a total, or a "next page"
     * control appearing would each answer "does unpublished work about this
     * exist" without showing any of it.
     */
    public function testAWordOnlyADraftContainsIsIndistinguishableFromAWordNobodyWrote(): void
    {
        ArticleFactory::createOne([
            'title' => 'A confidential acquisition',
            'slug' => 'a-draft',
            'content' => '<p>Everything about the hippopotamus deal.</p>',
        ]);
        ArticleFactory::new()->published()->create([
            'title' => 'Something else entirely',
            'slug' => 'something-else',
        ]);

        $this->search('hippopotamus');
        $draftWord = (string) $this->client->getResponse()->getContent();

        $this->search('zqxjkvwmb');
        $nobodysWord = (string) $this->client->getResponse()->getContent();

        // The searched-for word appears in both, so it is normalised away before
        // the comparison — everything *else* has to match.
        self::assertSame(
            str_replace('zqxjkvwmb', 'THE-QUERY', $this->withoutTheNonce($nobodysWord)),
            str_replace('hippopotamus', 'THE-QUERY', $this->withoutTheNonce($draftWord)),
        );
    }

    /**
     * FR-014. The public search is not an administration tool, and an editor
     * who could find drafts through it would be using one.
     */
    public function testAnEditorSeesExactlyWhatAnAnonymousReaderSees(): void
    {
        ArticleFactory::createOne([
            'title' => 'A draft about hippopotamuses',
            'slug' => 'a-draft',
        ]);

        UserFactory::new()->withPassword()->create([
            'email' => 'editor@example.com',
            'roles' => [User::ROLE_EDITOR],
        ]);

        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Sign in')->form([
            '_username' => 'editor@example.com',
            '_password' => UserFactory::DEVELOPMENT_PASSWORD,
        ]));
        $this->client->followRedirect();

        self::assertSame([], $this->resultTitles($this->search('hippopotamus')));
    }

    // ---------------------------------------------------------------- US3

    public function testAnEmptyQueryInvitesOneRatherThanReportingNoResults(): void
    {
        ArticleFactory::new()->published()->many(2)->create();

        $text = $this->search('')->filter('main')->text();

        self::assertStringContainsString('Type a word', $text);
        self::assertStringNotContainsString('Nothing matched', $text);
    }

    public function testAQueryTooShortToBeUsefulIsRefusedKindly(): void
    {
        $text = $this->search('a')->filter('main')->text();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('at least', $text);
    }

    public function testAQueryThatMatchesNothingSaysSo(): void
    {
        ArticleFactory::new()->published()->create(['title' => 'Something else']);

        $text = $this->search('zqxjkvwmb')->filter('main')->text();

        self::assertStringContainsString('Nothing matched', $text);
    }

    /**
     * FR-007 and FR-011. A search box is a classic place to find an injection,
     * and full-text search takes an expression — so a query must be words, both
     * on the way to the database and on the way back to the page.
     */
    public function testAQueryMadeOfMarkupIsTreatedAsWords(): void
    {
        ArticleFactory::new()->published()->create(['title' => 'Something else']);

        $crawler = $this->search('<script>alert("x")</script>');

        self::assertResponseIsSuccessful();
        self::assertCount(
            0,
            $crawler->filter('main script'),
            'A query became a script tag.',
        );
        self::assertSame(
            '<script>alert("x")</script>',
            $crawler->filter('main input[name="q"]')->attr('value'),
        );
    }

    /**
     * Operators are words. A reader typing `AND` means the word, and
     * `plainto_tsquery` is chosen precisely because it agrees.
     */
    public function testQuerySyntaxIsNotHonoured(): void
    {
        ArticleFactory::new()->published()->create(['title' => 'Something else']);

        foreach (["' OR 1=1 --", 'hippo & !potamus', '"quoted phrase"', ':*'] as $hostile) {
            $this->search($hostile);

            self::assertResponseIsSuccessful('The query '.$hostile.' broke the search.');
        }
    }

    public function testAnEnormousQueryIsBoundedRatherThanRefused(): void
    {
        $this->search(str_repeat('hippopotamus ', 200));

        self::assertResponseIsSuccessful();
    }

    /**
     * FR-012.
     */
    public function testTheSearchBoxIsOnEveryPage(): void
    {
        ArticleFactory::new()->published()->create(['slug' => 'an-article']);

        foreach (['/', '/articles/an-article', '/search'] as $address) {
            $crawler = $this->client->request('GET', $address);

            self::assertGreaterThan(
                0,
                $crawler->filter('form[role="search"] input[name="q"]')->count(),
                $address.' has no search box.',
            );
        }
    }

    /**
     * FR-013. A results page is generated from somebody else's words and has no
     * permanent existence; an index full of them is what a search engine calls
     * thin content.
     */
    public function testResultsAreNotOfferedForIndexing(): void
    {
        $crawler = $this->search('hippopotamus');

        self::assertStringContainsString(
            'noindex',
            (string) $crawler->filter('meta[name="robots"]')->attr('content'),
        );
    }

    private function search(string $query): Crawler
    {
        $crawler = $this->client->request('GET', '/search?q='.urlencode($query));

        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /**
     * @return list<string>
     */
    private function resultTitles(Crawler $crawler): array
    {
        return $crawler->filter('main article h2')->each(
            static fn (Crawler $heading): string => trim($heading->text()),
        );
    }

    /**
     * Feature 008 gives every response a fresh content security policy nonce, so
     * no two responses are identical byte for byte. Blanking that one value is
     * what lets everything else be compared.
     */
    private function withoutTheNonce(string $body): string
    {
        return (string) preg_replace('/nonce="[^"]*"/', 'nonce="…"', $body);
    }
}
