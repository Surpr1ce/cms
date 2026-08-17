<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Factory\ArticleFactory;
use App\Factory\CategoryFactory;
use App\Factory\PageFactory;
use App\Factory\TagFactory;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Test\Factories;

/**
 * User story 2, gathered in one file on purpose.
 *
 * This is the rule in the feature that cannot be fixed after the fact: an
 * article shown early has been shown. Scattering these assertions across the
 * per-controller tests would make it impossible to read the rule in one sitting
 * and see that nothing is missing.
 *
 * The strong form is what is tested. It is not enough that a draft is hidden
 * from listings — hiding content from a listing while leaving it readable by
 * address is the version of this bug that passes every happy-path test. Nor is
 * it enough that a draft returns 404: the response must be indistinguishable
 * from the one an address that never existed produces, or the site confirms
 * that unpublished work exists.
 */
final class UnpublishedContentIsInvisibleTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    /**
     * Debug off, deliberately.
     *
     * With debug on, Symfony answers a 404 with its own exception page — which
     * carries the exception message, the file, the line and a stack trace. That
     * is not what a reader ever sees, so asserting against it would test the
     * wrong thing entirely: two 404s would differ because their debug output
     * differs, and the real question — whether a *reader* can tell a draft from
     * an address that means nothing — would go unasked.
     */
    protected function setUp(): void
    {
        $this->client = self::createClient(['debug' => false]);
    }

    public function testADraftArticleIsNotFoundAtItsOwnAddress(): void
    {
        ArticleFactory::createOne(['slug' => 'a-draft']);

        $this->client->request('GET', '/articles/a-draft');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAnArchivedArticleIsNotFoundAtItsOwnAddress(): void
    {
        ArticleFactory::new()->publishedThenArchived()->create(['slug' => 'retired']);

        $this->client->request('GET', '/articles/retired');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /**
     * FR-003: no redirect. A redirect would confirm the address means something.
     */
    public function testAnUnpublishedArticleIsNotRedirectedAwayFrom(): void
    {
        ArticleFactory::createOne(['slug' => 'a-draft']);

        $this->client->request('GET', '/articles/a-draft');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertFalse($this->client->getResponse()->isRedirection());
    }

    public function testAnArticlePublishedAndThenUnpublishedBecomesUnreachable(): void
    {
        $article = ArticleFactory::new()->published()->create(['slug' => 'withdrawn']);

        $this->client->request('GET', '/articles/withdrawn');
        self::assertResponseIsSuccessful();

        $article->unpublish();
        $this->persist();

        $this->client->request('GET', '/articles/withdrawn');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testADraftPageIsNotFoundAtItsOwnAddress(): void
    {
        PageFactory::createOne(['slug' => 'a-draft-page']);

        $this->client->request('GET', '/a-draft-page');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAnArchivedPageIsNotFoundAtItsOwnAddress(): void
    {
        PageFactory::new()->publishedThenArchived()->create(['slug' => 'retired-page']);

        $this->client->request('GET', '/retired-page');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /**
     * SC-002, and the assertion this whole file exists for.
     *
     * If the two bodies ever differ, somebody has added a branch that knows why
     * the content was not shown — and a reader can then tell a draft from an
     * address that means nothing.
     */
    public function testADraftAndANonexistentAddressProduceIdenticalResponses(): void
    {
        ArticleFactory::createOne(['slug' => 'a-draft']);

        $this->client->request('GET', '/articles/a-draft');
        $draftStatus = $this->client->getResponse()->getStatusCode();
        $draftBody = $this->bodyWithoutTheNonce();

        $this->client->request('GET', '/articles/nothing-here-at-all');
        $missingStatus = $this->client->getResponse()->getStatusCode();
        $missingBody = $this->bodyWithoutTheNonce();

        self::assertSame($missingStatus, $draftStatus);
        self::assertSame($missingBody, $draftBody);
    }

    public function testADraftPageAndANonexistentAddressProduceIdenticalResponses(): void
    {
        PageFactory::createOne(['slug' => 'a-draft-page']);

        $this->client->request('GET', '/a-draft-page');
        $draftBody = $this->bodyWithoutTheNonce();

        $this->client->request('GET', '/nothing-here-at-all');
        $missingBody = $this->bodyWithoutTheNonce();

        self::assertSame($missingBody, $draftBody);
    }

    /**
     * Nothing in the response may name the content that was not shown.
     */
    public function testTheNotFoundPageNeverNamesWhatWasHidden(): void
    {
        ArticleFactory::createOne([
            'slug' => 'a-draft',
            'title' => 'A Secret Product Launch Nobody Should See',
        ]);

        $this->client->request('GET', '/articles/a-draft');

        self::assertStringNotContainsString(
            'Secret Product Launch',
            (string) $this->client->getResponse()->getContent(),
        );
    }

    public function testTheHomeListingShowsNoDraftsAndNoArchivedArticles(): void
    {
        ArticleFactory::createMany(3, ['title' => 'Hidden draft']);
        ArticleFactory::new()->publishedThenArchived()->many(2)->create(['title' => 'Hidden archive']);
        ArticleFactory::new()->published()->many(2)->create(['title' => 'Visible']);

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('article'));
        self::assertStringNotContainsString('Hidden', (string) $this->client->getResponse()->getContent());
    }

    public function testASectionListingShowsNoUnpublishedArticles(): void
    {
        $section = CategoryFactory::createOne(['slug' => 'news']);
        ArticleFactory::createMany(3, ['category' => $section, 'title' => 'Hidden draft']);
        ArticleFactory::new()->published()->many(1)->create(['category' => $section, 'title' => 'Visible']);

        $crawler = $this->client->request('GET', '/sections/news');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('article'));
    }

    /**
     * US2 scenario 7. An empty section rather than a 404, because 404 would
     * separate "no such section" from "a section holding only drafts".
     */
    public function testASectionHoldingOnlyDraftsRendersEmptyRatherThanDisclosingThem(): void
    {
        $section = CategoryFactory::createOne(['slug' => 'news']);
        ArticleFactory::createMany(4, ['category' => $section, 'title' => 'Hidden draft']);

        $crawler = $this->client->request('GET', '/sections/news');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('article'));
        self::assertStringNotContainsString('Hidden', (string) $this->client->getResponse()->getContent());
    }

    public function testALabelListingShowsNoUnpublishedArticles(): void
    {
        $tag = TagFactory::createOne(['slug' => 'php']);

        foreach (ArticleFactory::createMany(3, ['title' => 'Hidden draft']) as $draft) {
            $draft->addTag($tag);
        }

        ArticleFactory::new()->published()->create(['title' => 'Visible'])->addTag($tag);
        $this->persist();

        $crawler = $this->client->request('GET', '/topics/php');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('article'));
    }

    public function testTheMenuShowsNoDraftPages(): void
    {
        PageFactory::new()->published()->create(['title' => 'Visible page', 'slug' => 'visible']);
        PageFactory::createOne(['title' => 'Hidden page', 'slug' => 'hidden']);

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Visible page', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('Hidden page', (string) $this->client->getResponse()->getContent());
    }

    /**
     * A breadcrumb naming a draft parent would leak its title just as surely as
     * rendering the page itself would.
     */
    public function testABreadcrumbNeverNamesADraftAncestor(): void
    {
        $hiddenParent = PageFactory::createOne(['title' => 'Hidden parent', 'slug' => 'hidden-parent']);
        PageFactory::new()->published()->childOf($hiddenParent)->create([
            'title' => 'Visible child',
            'slug' => 'visible-child',
        ]);

        $this->client->request('GET', '/visible-child');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Hidden parent', (string) $this->client->getResponse()->getContent());
    }

    /**
     * The response body, with the content security policy's nonce blanked out.
     *
     * Two responses can no longer be identical byte for byte: feature 008 gives
     * every response a fresh nonce, on purpose, and a value that repeated would
     * be a value an attacker could reuse. Blanking exactly that one value keeps
     * what these comparisons are for — that nothing else about the two pages
     * differs — while allowing the one difference that is meant to be there.
     */
    private function bodyWithoutTheNonce(): string
    {
        return (string) preg_replace(
            '/nonce="[^"]*"/',
            'nonce="…"',
            (string) $this->client->getResponse()->getContent(),
        );
    }

    private function persist(): void
    {
        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);

        $registry->getManager()->flush();
    }
}
