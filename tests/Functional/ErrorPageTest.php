<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Factory\PageFactory;

use function sprintf;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Test\Factories;

/**
 * User story 5: an error is a page belonging to this site, not a stack trace.
 *
 * Every test here runs with debug off, because that is the only configuration in
 * which the question makes sense. With debug on, Symfony answers with its own
 * exception page by design — asserting against that would be testing the
 * framework's development tooling and would tell us nothing about what a reader
 * gets.
 */
final class ErrorPageTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient(['debug' => false]);
    }

    public function testANotFoundResponseCarriesTheNotFoundStatus(): void
    {
        $this->client->request('GET', '/nothing-here');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /**
     * FR-021 and US5 scenario 3. A 200 with "not found" written on it tells
     * search engines and link checkers the opposite of the truth.
     */
    public function testANotFoundResponseIsNotDisguisedAsASuccess(): void
    {
        $this->client->request('GET', '/nothing-here');

        self::assertFalse($this->client->getResponse()->isSuccessful());
    }

    public function testTheNotFoundPageRendersInsideTheSitesOwnLayout(): void
    {
        PageFactory::new()->published()->create(['slug' => 'about-us', 'title' => 'About us']);

        $crawler = $this->client->request('GET', '/nothing-here');

        self::assertCount(1, $crawler->filter('header'));
        self::assertCount(1, $crawler->filter('footer'));
        self::assertCount(1, $crawler->filter('nav[aria-label="Site"] a[href="/about-us"]'));
    }

    public function testTheNotFoundPageOffersAWayBack(): void
    {
        $crawler = $this->client->request('GET', '/nothing-here');

        self::assertCount(1, $crawler->filter('main a[href="/"]'));
    }

    /**
     * FR-020. Each of these strings would tell an outsider something about how
     * the site is built, and a couple of them would tell them where it lives on
     * disk.
     */
    public function testTheNotFoundPageDisclosesNothingAboutTheSoftware(): void
    {
        $this->client->request('GET', '/nothing-here');
        $body = (string) $this->client->getResponse()->getContent();

        // "vendor/" alone is not on this list, and the first version of this
        // test failed because of it: the importmap legitimately references
        // /assets/vendor/@hotwired/…, which discloses nothing beyond the fact
        // that the site uses Stimulus. What follows are strings that could only
        // come from an error being rendered rather than handled.
        foreach ([
            'Symfony\\',
            'Exception',
            'Stack Trace',
            'vendor/symfony',
            'PhpstormProjects',
            'App\\',
            'Doctrine',
            'SELECT ',
            '.php',
        ] as $leak) {
            self::assertStringNotContainsString(
                $leak,
                $body,
                sprintf('The not-found page discloses "%s".', $leak),
            );
        }
    }

    /**
     * The 404 page is the same for every reason a thing is not there, so it can
     * say nothing about which reason applied. This asserts the wording carries
     * no branch.
     */
    public function testTheNotFoundPageSaysNothingAboutWhyContentIsMissing(): void
    {
        $this->client->request('GET', '/nothing-here');
        $body = (string) $this->client->getResponse()->getContent();

        foreach (['draft', 'archived', 'unpublished', 'withdrawn', 'removed'] as $tell) {
            self::assertStringNotContainsStringIgnoringCase(
                $tell,
                $body,
                sprintf('The not-found page hints at "%s".', $tell),
            );
        }
    }

    public function testTheNotFoundPageIsTheSameWhicheverAddressMissed(): void
    {
        $this->client->request('GET', '/nothing-here');
        $first = $this->bodyWithoutTheNonce();

        $this->client->request('GET', '/articles/nothing-here-either');
        $second = $this->bodyWithoutTheNonce();

        self::assertSame($first, $second);
    }

    /**
     * The response body, with the content security policy's nonce blanked out.
     *
     * Two responses can no longer be identical byte for byte: feature 008 gives
     * every response a fresh nonce, on purpose, and a value that repeated would
     * be a value an attacker could reuse. Blanking exactly that one value keeps
     * what this comparison is for — that nothing else about the two pages
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
}
