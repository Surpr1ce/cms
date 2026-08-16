<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Controller\PageController;
use App\Factory\ArticleFactory;
use App\Factory\PageFactory;

use function sprintf;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Test\Factories;

/**
 * Standalone pages, the site menu, and the thing that makes a root-level
 * catch-all route safe.
 */
final class PageControllerTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testAnAnonymousReaderCanOpenAPublishedPage(): void
    {
        PageFactory::new()->published()->create(['slug' => 'about-us']);

        $this->client->request('GET', '/about-us');

        self::assertResponseIsSuccessful();
    }

    /**
     * FR-010: a page has no author and no date, so neither is shown.
     */
    public function testItShowsTheTitleAndBodyAndNeitherAnAuthorNorADate(): void
    {
        PageFactory::new()->published()->create([
            'slug' => 'about-us',
            'title' => 'About us',
            'content' => '<p>Who we are.</p>',
        ]);

        $crawler = $this->client->request('GET', '/about-us');

        self::assertSame('About us', $crawler->filter('h1')->text());
        self::assertStringContainsString('Who we are.', $crawler->filter('.prose')->text());
        self::assertCount(0, $crawler->filter('article time'));
    }

    public function testAPageThatDoesNotExistIsNotFound(): void
    {
        $this->client->request('GET', '/never-created');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /**
     * The reason a root-level catch-all is safe: the prefixed routes are tried
     * first. If declaration order ever changed, this is what would notice.
     */
    public function testTheCatchAllRouteDoesNotSwallowAnArticleAddress(): void
    {
        ArticleFactory::new()->published()->create(['slug' => 'a-published-article', 'title' => 'An article']);

        $crawler = $this->client->request('GET', '/articles/a-published-article');

        self::assertResponseIsSuccessful();
        self::assertSame('An article', $crawler->filter('h1')->text());
    }

    /**
     * A page must never answer for a first segment the site reserves.
     *
     * The assertion is about the *content*, not the status. `/api` already
     * returns 200 from API Platform's entrypoint, which is exactly the reason
     * `api` is on the reserved list — the first version of this test asserted
     * 404 and failed, correctly, on that one.
     */
    public function testAPageCannotShadowAPrefixTheSiteAlreadyUses(): void
    {
        foreach (PageController::RESERVED as $reserved) {
            PageFactory::new()->published()->create([
                'slug' => $reserved,
                'title' => 'Impostor page for '.$reserved,
            ]);

            $this->client->request('GET', '/'.$reserved);

            self::assertStringNotContainsString(
                'Impostor page for '.$reserved,
                (string) $this->client->getResponse()->getContent(),
                sprintf('/%s was answered by a page.', $reserved),
            );
        }
    }

    public function testANestedPageShowsWhereInTheStructureItIs(): void
    {
        $parent = PageFactory::new()->published()->create(['slug' => 'about-us', 'title' => 'About us']);
        PageFactory::new()->published()->childOf($parent)->create(['slug' => 'our-team', 'title' => 'Our team']);

        $crawler = $this->client->request('GET', '/our-team');

        self::assertCount(1, $crawler->filter('nav[aria-label="Breadcrumb"] a[href="/about-us"]'));
    }

    public function testATopLevelPageShowsNoBreadcrumb(): void
    {
        PageFactory::new()->published()->create(['slug' => 'about-us']);

        $crawler = $this->client->request('GET', '/about-us');

        self::assertCount(0, $crawler->filter('nav[aria-label="Breadcrumb"]'));
    }

    public function testAParentPageLinksToItsPublishedChildren(): void
    {
        $parent = PageFactory::new()->published()->create(['slug' => 'about-us']);
        PageFactory::new()->published()->childOf($parent)->create(['slug' => 'our-team', 'title' => 'Our team']);
        PageFactory::new()->childOf($parent)->create(['slug' => 'draft-child', 'title' => 'Draft child']);

        $crawler = $this->client->request('GET', '/about-us');

        // Scoped to main: the menu links to the same child, which is correct and
        // is what an unscoped selector counted twice.
        self::assertCount(1, $crawler->filter('main a[href="/our-team"]'));
        self::assertStringNotContainsString('Draft child', (string) $this->client->getResponse()->getContent());
    }

    // --- the menu, which every page carries ---

    public function testTheMenuListsPublishedPagesInTheirChosenOrder(): void
    {
        PageFactory::new()->published()->create(['slug' => 'privacy', 'title' => 'Privacy', 'menuOrder' => 30]);
        PageFactory::new()->published()->create(['slug' => 'about-us', 'title' => 'About us', 'menuOrder' => 10]);
        PageFactory::new()->published()->create(['slug' => 'contact', 'title' => 'Contact', 'menuOrder' => 20]);

        $crawler = $this->client->request('GET', '/');
        $titles = $crawler->filter('nav[aria-label="Site"] a')->each(
            static fn ($node): string => trim($node->text()),
        );

        self::assertSame(['About us', 'Contact', 'Privacy'], $titles);
    }

    public function testTheMenuNestsAChildUnderItsParent(): void
    {
        $parent = PageFactory::new()->published()->create(['slug' => 'about-us', 'title' => 'About us']);
        PageFactory::new()->published()->childOf($parent)->create(['slug' => 'our-team', 'title' => 'Our team']);

        $crawler = $this->client->request('GET', '/');
        $groups = $crawler->filter('nav[aria-label="Site"] > div');

        self::assertCount(1, $groups, 'A child must not appear alongside its parent.');
        self::assertStringContainsString('About us', $groups->text());
        self::assertStringContainsString('Our team', $groups->text());
    }

    public function testTheMenuAppearsOnEveryKindOfPage(): void
    {
        PageFactory::new()->published()->create(['slug' => 'about-us', 'title' => 'About us']);
        ArticleFactory::new()->published()->create(['slug' => 'a-published-article']);

        foreach (['/', '/articles/a-published-article', '/about-us', '/nothing-here'] as $path) {
            $this->client->request('GET', $path);

            self::assertStringContainsString(
                'About us',
                (string) $this->client->getResponse()->getContent(),
                sprintf('The menu is missing from %s', $path),
            );
        }
    }

    public function testASiteWithNoPagesRendersNoMenuRatherThanAnEmptyOne(): void
    {
        $crawler = $this->client->request('GET', '/');

        self::assertCount(0, $crawler->filter('nav[aria-label="Site"]'));
    }

    /**
     * A published page whose parent is a draft is still published, so it stays
     * reachable and appears at the top level. Dropping it would hide published
     * content, which is the opposite of the mistake this feature guards against.
     */
    public function testAPublishedPageUnderADraftParentIsStillInTheMenu(): void
    {
        $hiddenParent = PageFactory::createOne(['slug' => 'hidden-parent', 'title' => 'Hidden parent']);
        PageFactory::new()->published()->childOf($hiddenParent)->create([
            'slug' => 'visible-child',
            'title' => 'Visible child',
        ]);

        $crawler = $this->client->request('GET', '/');

        self::assertCount(1, $crawler->filter('nav[aria-label="Site"] a[href="/visible-child"]'));
    }
}
