<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Factory\ArticleFactory;
use App\Factory\PageFactory;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

use function urlencode;

use Zenstruck\Foundry\Test\Factories;

/**
 * What a reader is offered while they are still typing.
 *
 * The same rule as the search page carries here, and for the same reason: this
 * reads through `SiteSearch`, which is the one delivery on the site whose
 * published-only guarantee is a `WHERE` clause rather than a repository method
 * that cannot return anything else. A second endpoint onto that query is a
 * second chance to get it wrong, which is why
 * {@see testAWordOnlyADraftContainsSuggestsNothing} compares whole responses
 * rather than counting entries — a leaked title is obvious, but so is a list
 * that is empty in one case and absent in the other.
 */
final class SearchSuggestionsTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testAWordInATitleIsSuggested(): void
    {
        ArticleFactory::new()->published()->create([
            'title' => 'Hippopotamus season',
            'slug' => 'hippopotamus-season',
        ]);

        $suggestions = $this->suggestionsFor('hippopotamus');

        self::assertCount(1, $suggestions);
        self::assertSame('Hippopotamus season', $suggestions[0]['title']);
        self::assertSame('article', $suggestions[0]['kind']);
        self::assertSame('/articles/hippopotamus-season', $suggestions[0]['url']);
    }

    public function testAPageIsSuggestedAndSaysThatIsWhatItIs(): void
    {
        PageFactory::new()->published()->create([
            'title' => 'Hippopotamus policy',
            'slug' => 'hippopotamus-policy',
        ]);

        $suggestions = $this->suggestionsFor('hippopotamus');

        self::assertCount(1, $suggestions);
        self::assertSame('page', $suggestions[0]['kind']);
        self::assertSame('/hippopotamus-policy', $suggestions[0]['url']);
    }

    /**
     * The assertion this file exists for. Not "the draft is absent" — the two
     * responses have to be the same, because a list that is present and empty in
     * one case and absent in the other answers "is somebody writing about this".
     */
    public function testAWordOnlyADraftContainsSuggestsNothing(): void
    {
        ArticleFactory::createOne(['title' => 'Hippopotamus season', 'content' => 'A draft.']);
        PageFactory::createOne(['title' => 'Hippopotamus policy', 'content' => 'A draft.']);

        $aboutADraft = $this->rawResponseFor('hippopotamus');
        $aboutNothing = $this->rawResponseFor('rhinoceros');

        self::assertSame($aboutNothing, $aboutADraft);
        self::assertSame('{"suggestions":[]}', $aboutADraft);
    }

    public function testAnArchivedArticleIsNotSuggested(): void
    {
        $article = ArticleFactory::new()->published()->create(['title' => 'Hippopotamus season']);
        $article->archive();
        $this->flush();

        self::assertSame([], $this->suggestionsFor('hippopotamus'));
    }

    /**
     * Below the minimum nothing is asked of the database at all. A single
     * character matches most of a site, and this route is the one designed to be
     * called on every keystroke.
     */
    public function testAQueryTooShortToBeWorthRunningSuggestsNothing(): void
    {
        ArticleFactory::new()->published()->create(['title' => 'Hippopotamus season']);

        self::assertSame([], $this->suggestionsFor('h'));
        self::assertSame([], $this->suggestionsFor(''));
    }

    /**
     * Six, so the list can be read without scrolling. Longer lists are what the
     * search page is for.
     */
    public function testTheListIsCappedWhateverMatches(): void
    {
        for ($index = 1; $index <= 9; ++$index) {
            ArticleFactory::new()->published()->create([
                'title' => 'Hippopotamus number '.$index,
                'slug' => 'hippopotamus-'.$index,
            ]);
        }

        self::assertCount(6, $this->suggestionsFor('hippopotamus'));
    }

    /**
     * The endpoint is unauthenticated and meant to be asked repeatedly, so it is
     * bounded — from the same allowance `/search` spends from, which is the half
     * an audit found missing.
     *
     * The limit is deliberately lower in the test environment, so that reaching
     * it does not cost the suite sixty requests to prove the same thing.
     */
    public function testAClientAskingTooOftenIsRefused(): void
    {
        // Without this the kernel is rebuilt between requests and the limiter's
        // counters, which live in memory in this environment, start again — so
        // every request would be the first one. The same reasoning as
        // SignInThrottlingTest and PasswordResetTest.
        $this->client->disableReboot();

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->client->request('GET', '/search/suggestions?q=hippopotamus');
            self::assertResponseIsSuccessful();
        }

        $this->client->request('GET', '/search/suggestions?q=hippopotamus');

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
    }

    /**
     * The other half of the same allowance. `/search` runs the identical query
     * for twenty-one rows rather than six, so a ceiling that stopped at the
     * suggestion endpoint was one anybody could step around by asking the search
     * page instead — which is what the first version of this feature shipped.
     */
    public function testTheSearchPageSharesTheSameAllowance(): void
    {
        $this->client->disableReboot();

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->client->request('GET', '/search/suggestions?q=hippopotamus');
            self::assertResponseIsSuccessful();
        }

        $crawler = $this->client->request('GET', '/search?q=hippopotamus');

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        self::assertStringContainsString('a lot of searching', $crawler->filter('main')->text());
    }

    /**
     * And a query too short to be worth running costs nothing, so somebody
     * typing one character repeatedly cannot exhaust their own allowance before
     * they have searched for anything.
     */
    public function testAQueryTooShortToRunSpendsNothing(): void
    {
        $this->client->disableReboot();

        for ($attempt = 1; $attempt <= 8; ++$attempt) {
            $this->client->request('GET', '/search?q=a');
            self::assertResponseIsSuccessful();
        }
    }

    public function testItAnswersJson(): void
    {
        $this->client->request('GET', '/search/suggestions?q=anything');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
    }

    /**
     * A title is somebody's words, and this is the one place on the site where
     * they are written into a page by script rather than by Twig.
     *
     * What this pins is a framework default the feature relies on rather than
     * anything `SearchSuggestionController` does: `JsonResponse` applies
     * `JSON_HEX_TAG`, so angle brackets leave as `<`. That is worth a test
     * precisely *because* it is a default — the day somebody passes custom
     * encoding flags to make the output prettier, this fails, and the browser's
     * `textContent` becomes the only thing left standing between a title and a
     * page.
     */
    public function testATitleIsCarriedWithNoMarkupInIt(): void
    {
        ArticleFactory::new()->published()->create([
            'title' => 'Hippopotamus <script>alert(1)</script> season',
        ]);

        $raw = $this->rawResponseFor('hippopotamus');

        self::assertStringNotContainsString('<script>', $raw);
        self::assertStringContainsString('Hippopotamus', $raw);
    }

    /**
     * @return list<array{title: string, kind: string, url: string}>
     */
    private function suggestionsFor(string $query): array
    {
        $payload = json_decode($this->rawResponseFor($query), true);

        self::assertIsArray($payload);
        self::assertArrayHasKey('suggestions', $payload);
        self::assertIsArray($payload['suggestions']);

        /** @var list<array{title: string, kind: string, url: string}> $suggestions */
        $suggestions = $payload['suggestions'];

        return $suggestions;
    }

    private function rawResponseFor(string $query): string
    {
        $this->client->request('GET', '/search/suggestions?q='.urlencode($query));

        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }

    private function flush(): void
    {
        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);

        $registry->getManager()->flush();
    }
}
