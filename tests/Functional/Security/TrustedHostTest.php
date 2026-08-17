<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use function sprintf;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Test\Factories;

/**
 * Which `Host:` headers this installation answers to.
 *
 * The header is written by whoever is asking, and Symfony builds absolute
 * addresses from it: canonical tags, the sitemap, the feed, and — the reason
 * this file exists — the password-reset link. Both the conventions review and the
 * security audit put the same thing first: with no restriction, anybody could
 * POST somebody else's address to the reset form with `Host: attacker.example`
 * and the victim would receive a genuine email from this site whose link pointed
 * at the attacker, handing over a live token. Every other control on that
 * feature — hashed at rest, single use, one hour, throttled, responses identical
 * — is bypassed rather than weakened, because the token is given away rather
 * than guessed.
 *
 * Two defences, and this asserts both:
 *
 * **The request is refused** before it reaches anything, by `trusted_hosts`.
 *
 * **The link is built from configuration** whatever the request said, in
 * PasswordResetController::linkFor(). That one is asserted in PasswordResetTest,
 * where the emailed link can be read.
 */
final class TrustedHostTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testAForgedHostHeaderIsRefused(): void
    {
        foreach (['attacker.example', 'localhost.attacker.example', 'evil'] as $host) {
            $this->client->request('GET', '/', server: ['HTTP_HOST' => $host]);

            self::assertSame(
                Response::HTTP_BAD_REQUEST,
                $this->client->getResponse()->getStatusCode(),
                sprintf('Host: %s was answered.', $host),
            );
        }
    }

    /**
     * The refusal must not depend on the address asked for. The reset form is the
     * one that matters, and it is deliberately outside the firewall, so nothing
     * else stands between a stranger and it.
     */
    public function testTheRefusalCoversTheResetFormToo(): void
    {
        $this->client->request('GET', '/reset-password', server: ['HTTP_HOST' => 'attacker.example']);

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
    }

    /**
     * The hosts this site is actually served under still work — a check that
     * costs nothing and would have caught a pattern that refused everything.
     */
    public function testTheHostsThisSiteIsServedUnderAreAnswered(): void
    {
        foreach (['localhost', '127.0.0.1'] as $host) {
            $this->client->request('GET', '/', server: ['HTTP_HOST' => $host]);

            self::assertResponseIsSuccessful(sprintf('Host: %s was refused.', $host));
        }
    }
}
