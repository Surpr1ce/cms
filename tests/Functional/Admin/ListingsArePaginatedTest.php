<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\User;
use App\Factory\ArticleFactory;
use App\Factory\AuditEntryFactory;
use App\Factory\MediaFactory;
use App\Factory\PageFactory;
use App\Factory\TagFactory;
use App\Factory\UserFactory;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use PHPUnit\Framework\Attributes\DataProvider;

use function sprintf;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Zenstruck\Foundry\Test\Factories;

/**
 * Every administration listing fetches a page, not a table.
 *
 * The public side has been paginated since feature 002. The administration side
 * loaded whole tables until feature 019 — which nothing notices on a development
 * site with twelve articles, and which an editor with four thousand notices
 * before anybody else does.
 *
 * Written once over every screen rather than five times: the screens differ in
 * what they list and agree on how, so the interesting failure is one of them
 * being paginated and the next one being forgotten. A provider makes forgetting
 * the sixth screen the thing that fails.
 *
 * SC-001 and SC-003.
 */
final class ListingsArePaginatedTest extends WebTestCase
{
    use Factories;

    private const int PER_PAGE = 20;

    private KernelBrowser $client;

    /**
     * How many rows this test has created, so that a second helping of them is
     * numbered on from the first rather than starting again.
     */
    private int $rows = 0;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    /**
     * The address, the selector that finds one row on it, and how many rows the
     * screen already holds before this test creates any.
     *
     * The accounts screen starts at one: somebody is signed in, so their own
     * account is on it. Getting that wrong would make the first page 21 rows
     * long and the test would fail for the wrong reason.
     *
     * @return iterable<string, array{string, string, int}>
     */
    public static function listingProvider(): iterable
    {
        yield 'articles' => ['/admin/articles', 'table tbody tr', 0];
        yield 'pages' => ['/admin/pages', 'table tbody tr', 0];
        yield 'files' => ['/admin/media', 'ul li', 0];
        yield 'accounts' => ['/admin/manage/accounts', 'table tbody tr', 1];
        yield 'labels' => ['/admin/manage/labels', 'table tbody tr', 0];

        // The sixth screen, and the one this file's own docblock said a provider
        // exists to stop anybody forgetting. It was forgotten: the audit log has
        // been paginated since feature 014, through the same Paginator, and was
        // left out of the provider until the pass before the release found the
        // gap. No defect behind it — the log stores the actor as text, so it has
        // no lazy association to trip the query count — but SC-003 says *every*
        // paginated administration screen, and five is not every.
        yield 'log' => ['/admin/log', 'table tbody tr', 0];
    }

    #[DataProvider('listingProvider')]
    public function testAFullFirstPageOffersTheNextOne(string $path, string $rowSelector, int $existing): void
    {
        $this->signInAsAdministrator();
        $this->populate($path, self::PER_PAGE + 1 - $existing);

        $crawler = $this->client->request('GET', $path);

        self::assertResponseIsSuccessful();
        self::assertCount(self::PER_PAGE, $crawler->filter($rowSelector), 'The first page is not a full page.');
        self::assertCount(1, $crawler->filter('a[rel="next"]'), 'A full page offered no next one.');
        self::assertCount(0, $crawler->filter('a[rel="prev"]'), 'The first page offered a previous one.');
    }

    #[DataProvider('listingProvider')]
    public function testTheSecondPageHoldsTheRest(string $path, string $rowSelector, int $existing): void
    {
        $this->signInAsAdministrator();
        $this->populate($path, self::PER_PAGE + 1 - $existing);

        $crawler = $this->client->request('GET', $path.'?page=2');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter($rowSelector), 'The twenty-first row is not on the second page.');
        self::assertCount(1, $crawler->filter('a[rel="prev"]'), 'The second page offered no way back.');
        self::assertCount(0, $crawler->filter('a[rel="next"]'), 'The last page offered a further one.');
    }

    /**
     * A short listing offers nothing, rather than two dead links.
     */
    #[DataProvider('listingProvider')]
    public function testASingleShortPageOffersNoNavigationAtAll(string $path, string $rowSelector, int $existing): void
    {
        $this->signInAsAdministrator();
        $this->populate($path, 3);

        $crawler = $this->client->request('GET', $path);

        self::assertResponseIsSuccessful();
        self::assertCount(3 + $existing, $crawler->filter($rowSelector));
        self::assertCount(0, $crawler->filter('nav[aria-label="Pagination"]'));
    }

    /**
     * A page beyond the end is empty rather than an error — the rule the public
     * listings have followed since FR-022, now the administration's too. Somebody
     * who edits a URL by hand, or follows a bookmarked page two after the rows
     * were deleted, gets an empty screen.
     */
    #[DataProvider('listingProvider')]
    public function testAPageBeyondTheEndIsEmptyRatherThanAnError(string $path, string $rowSelector, int $existing): void
    {
        $this->signInAsAdministrator();
        $this->populate($path, 3);

        $crawler = $this->client->request('GET', $path.'?page=9');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter($rowSelector));
    }

    /**
     * SC-003: the query count must not grow with what the page holds.
     *
     * Compared against itself rather than against a fixed number, as
     * HomeControllerTest does for the public side: a fixed number becomes a chore
     * that gets bumped whenever anything changes, and what matters is that it does
     * not *grow*. This is the assertion that catches a listing whose template
     * touches a lazy association — the author of an article, the parent of a page,
     * the uploader of a file.
     */
    #[DataProvider('listingProvider')]
    public function testAListingIssuesTheSameNumberOfQueriesWhateverThePageHolds(
        string $path,
        string $rowSelector,
        int $existing,
    ): void {
        $this->signInAsAdministrator();
        $this->populate($path, 2);

        // A warm-up request first, unmeasured: the first request of a process pays
        // for metadata and container work that has nothing to do with the listing.
        $this->client->request('GET', $path);

        $this->client->enableProfiler();

        $crawler = $this->client->request('GET', $path);
        $small = $this->queryCount();

        self::assertCount(2 + $existing, $crawler->filter($rowSelector));

        $this->populate($path, 15);

        $this->client->enableProfiler();
        $crawler = $this->client->request('GET', $path);
        $large = $this->queryCount();

        self::assertCount(17 + $existing, $crawler->filter($rowSelector));

        self::assertSame(
            $small,
            $large,
            sprintf('%s took %d queries for 2 rows and %d for 17 — it has an N+1.', $path, $small, $large),
        );
    }

    /**
     * The article list is the one screen where the rows depend on who is reading,
     * so its page size is the one that could come out short: the old screen
     * fetched everything and then filtered, and twenty fetched rows would have
     * shown as however many survived the voter.
     *
     * Twenty-one drafts by somebody else, invisible to this author, and twenty-one
     * of their own. A full page of theirs is what proves the filtering happens in
     * the query.
     */
    public function testTheArticleListPagesAtFullSizeForAnAuthorWhoCannotSeeEverything(): void
    {
        $author = $this->signIn([User::ROLE_AUTHOR]);
        $somebodyElse = UserFactory::new()->author()->create(['email' => 'somebody-else@example.com']);

        foreach ([$somebodyElse, $author] as $whose => $writer) {
            for ($nth = 0; $nth <= self::PER_PAGE; ++$nth) {
                ArticleFactory::createOne([
                    'slug' => sprintf('draft-%d-%d', $whose, $nth),
                    'author' => $writer,
                ]);
            }
        }

        $crawler = $this->client->request('GET', '/admin/articles');

        self::assertCount(self::PER_PAGE, $crawler->filter('table tbody tr'), 'A page came out short.');
        self::assertCount(1, $crawler->filter('a[rel="next"]'));

        $crawler = $this->client->request('GET', '/admin/articles?page=2');

        self::assertCount(1, $crawler->filter('table tbody tr'), 'The rest is not on the second page.');
        self::assertCount(0, $crawler->filter('a[rel="next"]'), 'Somebody else’s drafts are being counted.');
    }

    /**
     * The files screen used to ask for a hundred and show a hundred, with nothing
     * on the page to say that the hundred-and-first existed.
     */
    public function testTheHundredAndFirstFileIsReachable(): void
    {
        $uploader = $this->signInAsAdministrator();

        // One uploader for all of them: a hundred and one accounts is a hundred
        // and one faker email addresses, and this test is not about those.
        MediaFactory::new()->many(101)->create(['uploadedBy' => $uploader]);

        $crawler = $this->client->request('GET', '/admin/media?page=6');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('ul li'), 'The hundred-and-first file has nowhere to be.');
    }

    /**
     * Rows for whichever screen is under test.
     *
     * Numbered from a counter rather than left to the factories' defaults. A test
     * here fills a screen twice — two rows, then fifteen more — and the faker
     * `word()` a label's name and address come from has a small vocabulary: the
     * first version of this test failed on a duplicate slug rather than on
     * anything it was asserting. Counted names cannot collide, and a failure
     * message naming "label-17" is more use than one naming "impedit".
     */
    private function populate(string $path, int $count): void
    {
        for ($created = 0; $created < $count; ++$created) {
            $nth = ++$this->rows;

            match ($path) {
                '/admin/articles' => ArticleFactory::createOne([
                    'title' => sprintf('Article %d', $nth),
                    'slug' => sprintf('article-%d', $nth),
                ]),
                '/admin/pages' => PageFactory::createOne([
                    'title' => sprintf('Page %d', $nth),
                    'slug' => sprintf('page-%d', $nth),
                ]),
                '/admin/media' => MediaFactory::createOne([
                    'originalName' => sprintf('file-%d.jpg', $nth),
                ]),
                '/admin/manage/accounts' => UserFactory::createOne([
                    'email' => sprintf('account-%d@example.com', $nth),
                ]),
                '/admin/manage/labels' => TagFactory::createOne([
                    'name' => sprintf('Label %d', $nth),
                    'slug' => sprintf('label-%d', $nth),
                ]),
                '/admin/log' => AuditEntryFactory::createOne([
                    'subject' => sprintf('Entry %d', $nth),
                ]),
                // A screen added to the provider with nothing to fill it would
                // otherwise pass every test above by listing nothing at all.
                default => self::fail(sprintf('%s has no fixture in this test.', $path)),
            };
        }
    }

    private function signInAsAdministrator(): User
    {
        return $this->signIn([User::ROLE_ADMIN]);
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

    private function queryCount(): int
    {
        $profile = $this->client->getProfile();
        self::assertInstanceOf(Profile::class, $profile, 'The profiler collected nothing.');

        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);

        return $collector->getQueryCount();
    }
}
