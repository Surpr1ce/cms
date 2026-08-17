<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Factory\UserFactory;

use function sprintf;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * US1 — guessing a password stops being free.
 *
 * Everything here is asserted from the outside, against behaviour. A test that
 * read `login_throttling` out of the configuration would pass on an
 * installation where the limiter is defined and never consulted, which is
 * exactly the failure this feature is most likely to have.
 *
 * The load-bearing assertion is
 * `testTheLimitRefusesEvenTheCorrectPassword`: it is the only one that
 * distinguishes "the attempt was refused" from "the attempt was not made".
 */
final class SignInThrottlingTest extends WebTestCase
{
    use Factories;

    /**
     * Five in fifteen minutes — see the spec's Assumptions. The sixth attempt
     * is the first refused one.
     */
    private const int LIMIT = 5;

    /**
     * Symfony's own message key for bad credentials, which the sign-in template
     * translates in the `security` domain. Named here so that the tests below
     * assert "the refusal did not change" rather than "the refusal says this",
     * and so a message key that moves fails loudly in one place.
     */
    private const string ORDINARY_REFUSAL = 'Invalid credentials.';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();

        // Without this the kernel is rebuilt between requests, and in the test
        // environment the limiter's counters live in memory — so every attempt
        // would be the first one and there would be no limit to reach. This is
        // the only test class that needs them to add up, and the only one that
        // can therefore see throttling at all. See config/packages/cache.yaml
        // for why the counters are in memory rather than on disk.
        $this->client->disableReboot();
    }

    public function testAttemptsUpToTheLimitAreRefusedTheWayTheyAlwaysWere(): void
    {
        UserFactory::new()->author()->withPassword()->create(['email' => 'author@example.com']);

        for ($attempt = 1; $attempt <= self::LIMIT; ++$attempt) {
            $this->signIn('author@example.com', 'not-the-password');

            self::assertResponseRedirects('/login', message: sprintf('Attempt %d was treated differently.', $attempt));
            $this->client->followRedirect();
            self::assertSame(
                self::ORDINARY_REFUSAL,
                trim($this->client->getCrawler()->filter('[role="alert"]')->text()),
                sprintf('Attempt %d did not get the ordinary refusal.', $attempt),
            );
        }
    }

    /**
     * FR-001. The sixth attempt is not answered.
     */
    public function testTheAttemptAfterTheLimitIsRefusedForTryingTooOften(): void
    {
        UserFactory::new()->author()->withPassword()->create(['email' => 'author@example.com']);

        $this->exhaustTheLimitFor('author@example.com');

        $this->signIn('author@example.com', 'not-the-password');
        $this->client->followRedirect();

        self::assertStringContainsString(
            'too many',
            strtolower(trim($this->client->getCrawler()->filter('[role="alert"]')->text())),
        );
    }

    /**
     * FR-001, and the assertion this class exists for.
     *
     * If the correct password still works after the limit, the limiter is
     * counting failures and not preventing attempts — which is a log, not a
     * defence. This is the only test here that can tell the difference.
     */
    public function testTheLimitRefusesEvenTheCorrectPassword(): void
    {
        UserFactory::new()->author()->withPassword()->create(['email' => 'author@example.com']);

        $this->exhaustTheLimitFor('author@example.com');

        $this->signIn('author@example.com', UserFactory::DEVELOPMENT_PASSWORD);

        self::assertResponseRedirects('/login', message: 'The correct password was accepted after the limit.');
        $this->client->followRedirect();
        self::assertStringContainsString(
            'too many',
            strtolower(trim($this->client->getCrawler()->filter('[role="alert"]')->text())),
        );
    }

    /**
     * FR-003. Being locked out must not answer "does this address hold an
     * account", which is the question the whole of LoginTest's
     * testAWrongPasswordAndAnUnknownAddressSayTheSameThing protects.
     */
    public function testBeingLockedOutSaysTheSameThingForAKnownAndAnUnknownAddress(): void
    {
        UserFactory::new()->author()->withPassword()->create(['email' => 'author@example.com']);

        $this->exhaustTheLimitFor('author@example.com');
        $this->signIn('author@example.com', 'not-the-password');
        $this->client->followRedirect();
        $known = trim($this->client->getCrawler()->filter('[role="alert"]')->text());

        // A different handle, so this starts from its own allowance — which is
        // FR-002 doing the work rather than a reset.
        $this->exhaustTheLimitFor('nobody@example.com');
        $this->signIn('nobody@example.com', 'not-the-password');
        $this->client->followRedirect();
        $unknown = trim($this->client->getCrawler()->filter('[role="alert"]')->text());

        self::assertSame($unknown, $known);
    }

    /**
     * FR-002. The counter is per handle as well as per address, so attacking
     * one account must not close another.
     *
     * Without this, the limiter is a denial-of-service tool: exhaust the limit
     * against any handle and every editor is locked out.
     */
    public function testExhaustingTheLimitForOneAccountLeavesAnotherAlone(): void
    {
        UserFactory::new()->author()->withPassword()->create(['email' => 'target@example.com']);
        UserFactory::new()->editor()->withPassword()->create(['email' => 'bystander@example.com']);

        $this->exhaustTheLimitFor('target@example.com');

        $this->signIn('bystander@example.com', UserFactory::DEVELOPMENT_PASSWORD);

        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Administration', (string) $this->client->getResponse()->getContent());
    }

    /**
     * FR-004. Somebody who mistyped four times and then got it right starts
     * again from nothing, rather than carrying four failures into tomorrow.
     */
    public function testASuccessfulSignInClearsWhatCameBefore(): void
    {
        UserFactory::new()->author()->withPassword()->create(['email' => 'author@example.com']);

        for ($attempt = 1; $attempt < self::LIMIT; ++$attempt) {
            $this->signIn('author@example.com', 'not-the-password');
        }

        $this->signIn('author@example.com', UserFactory::DEVELOPMENT_PASSWORD);
        self::assertResponseRedirects();

        $this->client->request('POST', '/logout');

        // If the count had survived, this would be the sixth attempt and would
        // be refused for trying too often rather than for being wrong.
        $this->signIn('author@example.com', 'not-the-password');
        $this->client->followRedirect();

        self::assertSame(
            self::ORDINARY_REFUSAL,
            trim($this->client->getCrawler()->filter('[role="alert"]')->text()),
        );
    }

    /**
     * FR-005. Throttling counts attempts at the form. Somebody already signed
     * in is not attempting anything.
     */
    public function testSomebodyAlreadySignedInIsUnaffected(): void
    {
        UserFactory::new()->editor()->withPassword()->create(['email' => 'editor@example.com']);

        $this->signIn('editor@example.com', UserFactory::DEVELOPMENT_PASSWORD);
        $this->client->followRedirect();

        // A second browser, indistinguishable from the first as far as the
        // limiter is concerned — same address, same handle — burns the whole
        // allowance. Its own cookie jar is what makes it a different visitor.
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        $attacker = new KernelBrowser($kernel);
        $this->exhaustTheLimitFor('editor@example.com', $attacker);

        $this->client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
    }

    private function exhaustTheLimitFor(string $email, ?KernelBrowser $client = null): void
    {
        for ($attempt = 1; $attempt <= self::LIMIT; ++$attempt) {
            $this->signIn($email, 'not-the-password', $client);
        }
    }

    private function signIn(string $email, string $password, ?KernelBrowser $client = null): void
    {
        $client ??= $this->client;

        $crawler = $client->request('GET', '/login');

        $client->submit($crawler->selectButton('Sign in')->form([
            '_username' => $email,
            '_password' => $password,
        ]));
    }
}
