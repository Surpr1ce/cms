<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Factory\ArticleFactory;
use App\Factory\UserFactory;

use function preg_match;
use function sprintf;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * US2 and US3 — the policy and the headers that come with it.
 *
 * A header is present whether or not it protects anything, and a policy loose
 * enough to permit everything reads as "CSP: yes" in an audit. So nothing here
 * asserts presence alone: the policy is checked for what it forbids, and the
 * nonce is checked against the script tag in **the same response**. A test that
 * read the header from one request and a script tag from another would pass
 * against a nonce that never matches anything.
 */
final class SecurityHeadersTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    /**
     * FR-007.
     */
    public function testEveryPublicPageCarriesAPolicy(): void
    {
        ArticleFactory::new()->published()->create(['slug' => 'a-published-article']);

        foreach (['/', '/articles/a-published-article', '/login'] as $address) {
            $this->client->request('GET', $address);

            self::assertResponseHeaderSame(
                'Content-Security-Policy',
                (string) $this->client->getResponse()->headers->get('Content-Security-Policy'),
            );
            self::assertNotNull(
                $this->client->getResponse()->headers->get('Content-Security-Policy'),
                sprintf('%s was delivered without a policy.', $address),
            );
        }
    }

    /**
     * FR-008. The single directive this whole layer rests on.
     */
    public function testThePolicyDoesNotAllowInlineScript(): void
    {
        $this->client->request('GET', '/');

        $policy = $this->policy();

        self::assertMatchesRegularExpression('/script-src [^;]*/', $policy);
        self::assertDoesNotMatchRegularExpression(
            "/script-src[^;]*'unsafe-inline'/",
            $policy,
            'The policy allows any inline script, which is the one thing it exists to forbid.',
        );
        self::assertDoesNotMatchRegularExpression(
            "/script-src[^;]*'unsafe-eval'/",
            $policy,
        );
    }

    /**
     * FR-009, and the assertion this class exists for.
     *
     * The policy names a nonce; the page carries scripts marked with one. If
     * those two values ever differ, the site loads no JavaScript at all and
     * every other assertion here still passes.
     */
    public function testThePolicysNonceIsTheOneOnTheResponsesOwnScripts(): void
    {
        $crawler = $this->client->request('GET', '/');

        $nonce = $this->nonceFromPolicy();

        $inline = $crawler->filter('script:not([src])');
        self::assertGreaterThan(0, $inline->count(), 'The page emits no inline script, so this proves nothing.');

        foreach ($this->noncesOn($inline) as $marked) {
            self::assertSame(
                $nonce,
                $marked,
                'An inline script carries a nonce the policy does not name.',
            );
        }
    }

    /**
     * FR-009. A nonce reused across responses is a nonce an attacker reads from
     * one page and writes into an injection on the next.
     */
    public function testTheNonceChangesBetweenResponses(): void
    {
        $this->client->request('GET', '/');
        $first = $this->nonceFromPolicy();

        $this->client->request('GET', '/');
        $second = $this->nonceFromPolicy();

        self::assertNotSame($first, $second);
    }

    /**
     * FR-010 and FR-011. Each directive checked for what it says, not for
     * being there.
     */
    public function testThePolicyClosesEachAddressCategory(): void
    {
        $this->client->request('GET', '/');

        $policy = $this->policy();

        foreach ([
            "default-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ] as $directive) {
            self::assertStringContainsString($directive, $policy);
        }
    }

    /**
     * SC-004, and the point of the whole layer.
     *
     * The script here is stored through no administration screen, so it never
     * meets the sanitiser. That is deliberate: content predating feature 004 is
     * in exactly this position, and so is anything an allow-list gets wrong.
     */
    public function testAScriptStoredInContentIsNotAllowedToRun(): void
    {
        ArticleFactory::new()->published()->create([
            'slug' => 'article-with-a-script',
            'content' => '<p>Ordinary text.</p><script>alert("stolen")</script>',
        ]);

        $crawler = $this->client->request('GET', '/articles/article-with-a-script');

        $nonce = $this->nonceFromPolicy();

        // The markup reached the page — this test is about the policy, not
        // about the sanitiser, and if the script had been stripped on the way
        // out there would be nothing left to prove.
        $hostile = $crawler->filter('script:not([src])')->reduce(
            static fn (Crawler $script): bool => str_contains($script->text(), 'stolen'),
        );

        self::assertGreaterThan(0, $hostile->count(), 'The stored script never reached the page.');
        self::assertNotSame(
            $nonce,
            $hostile->attr('nonce'),
            "A script that came out of the database carries the policy's nonce.",
        );
    }

    /**
     * FR-012. Per screen, because "the site still works" is not an assertion.
     */
    public function testEveryPublicScreenStillWorks(): void
    {
        ArticleFactory::new()->published()->create(['slug' => 'a-published-article']);

        foreach (['/', '/articles/a-published-article', '/login'] as $address) {
            $this->client->request('GET', $address);

            self::assertResponseIsSuccessful(sprintf('%s broke.', $address));
        }
    }

    /**
     * FR-012 for the administration area, which is where the risk of breakage
     * actually lives — those screens are not ours and mark their own scripts.
     */
    public function testEveryAdministrationScreenStillWorks(): void
    {
        UserFactory::new()->admin()->withPassword()->create(['email' => 'admin@example.com']);
        $this->signIn('admin@example.com');

        foreach ([
            '/admin',
            '/admin/articles',
            '/admin/pages',
            '/admin/media',
            '/admin/manage',
            '/admin/manage/sections',
            '/admin/manage/labels',
            '/admin/manage/accounts',
            '/admin/log',
            '/admin/account',
        ] as $address) {
            $crawler = $this->client->request('GET', $address);

            self::assertResponseIsSuccessful(sprintf('%s broke.', $address));

            // The screens that are not ours have to mark their own inline
            // scripts, and this is the only thing that proves they did.
            $nonce = $this->nonceFromPolicy();

            foreach ($this->noncesOn($crawler->filter('script:not([src])')) as $marked) {
                self::assertSame(
                    $nonce,
                    $marked,
                    sprintf('%s emits an inline script the policy will refuse.', $address),
                );
            }
        }
    }

    /**
     * FR-013, FR-014 and the second half of FR-011, on a public response.
     */
    public function testThePlainHeadersAreOnAPublicResponse(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        self::assertResponseHeaderSame('X-Frame-Options', 'DENY');
        self::assertResponseHeaderSame('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    /**
     * The same headers on an administration response. Listed separately
     * because an administration page is the one worth framing.
     */
    public function testThePlainHeadersAreOnAnAdministrationResponse(): void
    {
        UserFactory::new()->admin()->withPassword()->create(['email' => 'admin@example.com']);
        $this->signIn('admin@example.com');

        $this->client->request('GET', '/admin/manage');

        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        self::assertResponseHeaderSame('X-Frame-Options', 'DENY');
        self::assertResponseHeaderSame('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    /**
     * A 404 is a response like any other, and it is the one response nobody
     * remembers to protect because no controller of ours produces it.
     *
     * Debug off, and not merely to make the assertion pass. Symfony strips the
     * policy from an exception response while debugging, deliberately, because
     * its own exception page is built from inline scripts and styles that no
     * policy of ours would allow. That page is a development tool; the 404 a
     * reader gets is the one this test is about.
     */
    public function testTheNotFoundPageIsProtectedToo(): void
    {
        ArticleFactory::new()->draft()->create(['slug' => 'a-draft']);

        self::ensureKernelShutdown();
        $this->client = self::createClient(['debug' => false]);

        $this->client->request('GET', '/articles/a-draft');

        self::assertResponseStatusCodeSame(404);
        self::assertNotNull(
            $this->client->getResponse()->headers->get('Content-Security-Policy'),
            'The 404 carries no policy. If every other test here passes, suspect a stale '
            .'non-debug container in var/cache/test — Symfony does not rebuild that one on '
            .'a file change, and this is the only test that boots it.',
        );
        self::assertResponseHeaderSame('X-Frame-Options', 'DENY');
    }

    /**
     * The `nonce` attribute of every script in the given selection.
     *
     * Through the crawler rather than the DOM nodes underneath it, which are
     * typed as `DOMNode` and have no attributes as far as anything checking
     * types is concerned.
     *
     * @return list<string|null>
     */
    private function noncesOn(Crawler $scripts): array
    {
        return $scripts->each(static fn (Crawler $script): ?string => $script->attr('nonce'));
    }

    private function policy(): string
    {
        $policy = $this->client->getResponse()->headers->get('Content-Security-Policy');

        self::assertNotNull($policy, 'The response carries no policy.');

        return $policy;
    }

    private function nonceFromPolicy(): string
    {
        self::assertSame(
            1,
            preg_match("/script-src[^;]*'nonce-([A-Za-z0-9+\\/=_-]+)'/", $this->policy(), $matches),
            'The policy names no nonce.',
        );

        return $matches[1];
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
}
