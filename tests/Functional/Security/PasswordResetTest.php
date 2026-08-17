<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\PasswordResetRequest;
use App\Entity\User;
use App\Factory\PasswordResetRequestFactory;
use App\Factory\UserFactory;
use App\Repository\PasswordResetRequestRepository;
use App\Tests\Functional\SigningOut;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

use function hash;
use function is_resource;
use function preg_match;
use function str_repeat;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;
use Zenstruck\Foundry\Test\Factories;

/**
 * Getting back in — and learning nothing if the account is not yours.
 *
 * Three things are load-bearing here and each has its own test:
 *
 * **The response never says whether an address holds an account.** Not
 * "similar" — identical, compared byte for byte, because a reset form that
 * distinguishes the two is a way to test any list of addresses against this
 * installation.
 *
 * **The database holds no working link.** The test reads the row and asserts the
 * token in the email does not appear in it, because a token stored as it appears
 * in the link means anybody who reads a backup can sign in as anybody.
 *
 * **A link stops working.** Used, expired and superseded are three separate
 * tests, because they are three separate ways to leave a credential lying around
 * in somebody's inbox forever.
 */
final class PasswordResetTest extends WebTestCase
{
    use Factories;
    use MailerAssertionsTrait;
    use SigningOut;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    // ------------------------------------------- US2: telling nobody anything

    /**
     * FR-004, SC-002, and the assertion this file exists for.
     */
    public function testTheResponseIsIdenticalForAnAddressWithAnAccountAndOneWithout(): void
    {
        UserFactory::new()->editor()->withPassword()->create(['email' => 'editor@example.com']);

        $withAnAccount = $this->askForALink('editor@example.com');
        $without = $this->askForALink('nobody@example.com');

        self::assertSame($this->withoutTheNonce($without), $this->withoutTheNonce($withAnAccount));
    }

    /**
     * FR-003. The message is the visible half; this is the half that would
     * otherwise make this CMS a way of sending mail to strangers.
     */
    public function testNoMessageIsSentForAnAddressWithNoAccount(): void
    {
        $this->askForALink('nobody@example.com');

        self::assertNull($this->lastEmail());
    }

    public function testAMessageIsSentForAnAddressThatHasOne(): void
    {
        UserFactory::new()->editor()->withPassword()->create(['email' => 'editor@example.com']);

        $this->askForALink('editor@example.com');

        $email = $this->lastEmail();

        self::assertInstanceOf(Email::class, $email);
        self::assertSame('editor@example.com', $email->getTo()[0]->getAddress());
        self::assertStringContainsString('/reset-password/', $this->textOf($email));
    }

    /**
     * FR-005. Without a limit, this form sends an email to anybody's inbox as
     * often as somebody cares to ask.
     */
    public function testTooManyRequestsFromOneClientAreRefused(): void
    {
        UserFactory::new()->editor()->withPassword()->create(['email' => 'editor@example.com']);

        // Without this the kernel is rebuilt between requests, and in the test
        // environment the limiter's counters live in memory — so every request
        // would be the first one and there would be no limit to reach. Feature
        // 008's SignInThrottlingTest does the same thing for the same reason;
        // config/packages/cache.yaml explains why the counters are in memory.
        $this->client->disableReboot();

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->askForALink('editor@example.com');
        }

        $crawler = $this->client->request('GET', '/reset-password');
        $this->client->submit($crawler->selectButton('Send the link')->form(['email' => 'editor@example.com']));

        self::assertStringContainsString('Too many', $this->client->getCrawler()->filter('main')->text());
    }

    // ----------------------------------------------------- US1: getting in

    /**
     * The whole path, end to end.
     */
    public function testSomebodyWhoForgotTheirPasswordGetsBackIn(): void
    {
        UserFactory::new()->editor()->withPassword()->create(['email' => 'editor@example.com']);

        $link = $this->linkFromTheEmailFor('editor@example.com');

        $crawler = $this->client->request('GET', $link);
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->selectButton('Set the password')->form([
            'password' => 'a-brand-new-password',
            'confirmation' => 'a-brand-new-password',
        ]));

        self::assertResponseRedirects();

        // The new password works.
        $this->signOut();
        $this->signIn('editor@example.com', 'a-brand-new-password');
        self::assertResponseRedirects();

        // And the old one does not.
        $this->signOut();
        $this->signIn('editor@example.com', UserFactory::DEVELOPMENT_PASSWORD);
        $this->client->followRedirect();
        self::assertCount(1, $this->client->getCrawler()->filter('[role="alert"]'));
    }

    /**
     * The token on the form, not the one in the link.
     *
     * Both actions behind `/reset-password/{token}` were one action until the
     * architecture pass split them by method, and the CSRF check lives in the POST
     * half. It survived the split — but the security pass pointed out that
     * deleting the check left the whole suite green, on the one route where the
     * consequence is somebody else's password. So this asserts it: a submission
     * without a valid form token changes nothing.
     *
     * Asserted on the stored hash rather than on a status code, because an
     * anonymous visitor meeting an access-denied exception is sent to the sign-in
     * page rather than shown a 403 — and what matters is not which page came back
     * but that the password did not change.
     */
    public function testASubmissionWithoutAValidFormTokenChangesNothing(): void
    {
        $account = UserFactory::new()->editor()->withPassword()->create(['email' => 'editor@example.com']);
        $before = $this->reload($account)->getPassword();

        $link = $this->linkFromTheEmailFor('editor@example.com');

        $this->client->request('POST', $link, [
            '_token' => 'not-the-token-that-was-issued',
            'password' => 'a-brand-new-password',
            'confirmation' => 'a-brand-new-password',
        ]);

        self::assertFalse($this->client->getResponse()->isSuccessful(), 'A forged submission was accepted.');
        self::assertSame($before, $this->reload($account)->getPassword());

        // And the link still works for the person who actually holds it, so the
        // refusal did not spend the token on their behalf.
        $this->client->request('GET', $link);
        self::assertResponseIsSuccessful();
    }

    /**
     * FR-007, SC-003. A token stored as it appears in the link is a working
     * link for anybody who can read the database.
     */
    public function testTheDatabaseHoldsNoWorkingLink(): void
    {
        UserFactory::new()->editor()->withPassword()->create(['email' => 'editor@example.com']);

        $token = $this->tokenFromTheEmailFor('editor@example.com');

        $stored = $this->onlyRequest();

        self::assertNotSame($token, $stored->getTokenHash());
        self::assertSame(hash('sha256', $token), $stored->getTokenHash());
    }

    /**
     * FR-009. One link, one use.
     */
    public function testALinkStopsWorkingOnceItHasBeenUsed(): void
    {
        UserFactory::new()->editor()->withPassword()->create(['email' => 'editor@example.com']);

        $link = $this->linkFromTheEmailFor('editor@example.com');

        $crawler = $this->client->request('GET', $link);
        $this->client->submit($crawler->selectButton('Set the password')->form([
            'password' => 'a-brand-new-password',
            'confirmation' => 'a-brand-new-password',
        ]));

        $this->signOut();
        $this->client->request('GET', $link);

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * FR-008.
     */
    public function testALinkStopsWorkingOnceItHasExpired(): void
    {
        $account = UserFactory::new()->editor()->withPassword()->create(['email' => 'editor@example.com']);

        // Built rather than aged. An earlier version asked for a real link and
        // then moved its timestamp with an UPDATE, because there is no setter
        // for the requested time — and there should not be one, since a way to
        // extend a link's life is the last thing this feature wants in
        // production code. A factory can pass a time to the constructor, which
        // is the honest way to arrange a request that is simply old.
        //
        // The token is known here and hashed into the row, which is exactly what
        // PasswordResetService does with a real one.
        $token = str_repeat('ab', 16);

        PasswordResetRequestFactory::createOne([
            'account' => $account,
            'tokenHash' => hash('sha256', $token),
            'requestedAt' => new DateTimeImmutable('-2 hours'),
        ]);

        $this->client->request('GET', '/reset-password/'.$token);

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * FR-010. Two live links for one account is two credentials where the
     * person expected one, and the older is the one they have forgotten about.
     */
    public function testAskingAgainInvalidatesTheEarlierLink(): void
    {
        UserFactory::new()->editor()->withPassword()->create(['email' => 'editor@example.com']);

        $first = $this->linkFromTheEmailFor('editor@example.com');
        $second = $this->linkFromTheEmailFor('editor@example.com');

        self::assertNotSame($first, $second);

        $this->client->request('GET', $first);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', $second);
        self::assertResponseIsSuccessful();
    }

    /**
     * FR-011. Altering a link must not open anything, and must not say what
     * kind of wrong it is.
     */
    public function testAnAlteredLinkIsRefused(): void
    {
        UserFactory::new()->editor()->withPassword()->create(['email' => 'editor@example.com']);

        $token = $this->tokenFromTheEmailFor('editor@example.com');

        // Each derived so it cannot accidentally equal the real one — flipping a
        // character to a fixed value would collide whenever the real token
        // already had that character there, and the test would pass by opening
        // a link it was supposed to have altered.
        $altered = [
            str_repeat('a', 32),
            str_repeat('0', 32),
            substr($token, 0, 31).('0' === $token[31] ? '1' : '0'),
            ('a' === $token[0] ? 'b' : 'a').substr($token, 1),
        ];

        foreach ($altered as $candidate) {
            $this->client->request('GET', '/reset-password/'.$candidate);

            self::assertResponseStatusCodeSame(404, 'A token of "'.$candidate.'" opened something.');
        }
    }

    public function testAPasswordTooShortIsRefusedAndNothingIsStored(): void
    {
        $account = UserFactory::new()->editor()->withPassword()->create(['email' => 'editor@example.com']);
        $before = $account->getPassword();

        $link = $this->linkFromTheEmailFor('editor@example.com');

        $crawler = $this->client->request('GET', $link);
        $this->client->submit($crawler->selectButton('Set the password')->form([
            'password' => 'short',
            'confirmation' => 'short',
        ]));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('at least', $this->client->getCrawler()->filter('main')->text());
        self::assertSame($before, $this->reload($account)->getPassword());
    }

    public function testTwoDifferentPasswordsAreRefused(): void
    {
        UserFactory::new()->editor()->withPassword()->create(['email' => 'editor@example.com']);

        $link = $this->linkFromTheEmailFor('editor@example.com');

        $crawler = $this->client->request('GET', $link);
        $this->client->submit($crawler->selectButton('Set the password')->form([
            'password' => 'a-brand-new-password',
            'confirmation' => 'a-different-password',
        ]));

        self::assertStringContainsString('do not match', $this->client->getCrawler()->filter('main')->text());
    }

    /**
     * An account an administrator created and nobody has used yet has an empty
     * stored credential. A reset is the only way it will ever become usable, so
     * it has to work.
     */
    public function testAnAccountThatHasNeverHadAPasswordCanReceiveOne(): void
    {
        UserFactory::new()->editor()->create(['email' => 'new@example.com']);

        $link = $this->linkFromTheEmailFor('new@example.com');

        $crawler = $this->client->request('GET', $link);
        $this->client->submit($crawler->selectButton('Set the password')->form([
            'password' => 'the-first-password',
            'confirmation' => 'the-first-password',
        ]));

        $this->signOut();
        $this->signIn('new@example.com', 'the-first-password');

        self::assertResponseRedirects();
    }

    /**
     * FR-019. The one thing no response may ever contain.
     */
    public function testNoResponseCarriesAStoredHash(): void
    {
        $account = UserFactory::new()->editor()->withPassword()->create(['email' => 'editor@example.com']);

        $body = $this->askForALink('editor@example.com');

        self::assertStringNotContainsString($account->getPassword(), $body);
        self::assertStringNotContainsString($this->onlyRequest()->getTokenHash(), $body);
    }

    /**
     * The link points at this site, whatever host the request claimed.
     *
     * The worst finding either audit produced. The link used to be generated with
     * ABSOLUTE_URL inside a request, which takes its host from the incoming
     * `Host:` header — so a stranger could POST an administrator's address with
     * `Host: attacker.example` and that administrator would receive a genuine
     * email from this site whose link led to the attacker. One click hands over a
     * live token, and complete() turns a token straight into a session.
     *
     * `trusted_hosts` refuses the forged header outright (TrustedHostTest), so
     * this asserts the second defence on its own: 127.0.0.1 is a *trusted* host
     * and is not the configured one, so a link built from the request would say
     * 127.0.0.1 here and a link built from configuration says localhost. The two
     * defences can therefore fail independently and be seen to.
     */
    public function testTheEmailedLinkIsBuiltFromConfigurationRatherThanTheRequest(): void
    {
        UserFactory::new()->editor()->withPassword()->create(['email' => 'editor@example.com']);

        $crawler = $this->client->request('GET', '/reset-password', server: ['HTTP_HOST' => '127.0.0.1']);
        $this->client->submit(
            $crawler->selectButton('Send the link')->form(['email' => 'editor@example.com']),
            serverParameters: ['HTTP_HOST' => '127.0.0.1'],
        );

        $message = $this->lastEmail();
        self::assertInstanceOf(Email::class, $message);

        $text = $this->textOf($message);

        self::assertStringContainsString('http://localhost/reset-password/', $text);
        self::assertStringNotContainsString('127.0.0.1', $text);
    }

    // -------------------------------------------------------------- helpers

    private function askForALink(string $email): string
    {
        $crawler = $this->client->request('GET', '/reset-password');
        $this->client->submit($crawler->selectButton('Send the link')->form(['email' => $email]));

        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }

    private function linkFromTheEmailFor(string $email): string
    {
        return '/reset-password/'.$this->tokenFromTheEmailFor($email);
    }

    private function tokenFromTheEmailFor(string $email): string
    {
        $this->askForALink($email);

        $message = $this->lastEmail();
        self::assertInstanceOf(Email::class, $message, 'No message was sent to '.$email);

        self::assertSame(
            1,
            preg_match('#/reset-password/([0-9a-f]{32})#', $this->textOf($message), $matches),
            'The message carries no link.',
        );

        return $matches[1];
    }

    /**
     * The text of a message, as a string.
     *
     * `Email::getTextBody()` may answer a stream as well as a string — a large
     * body is not held in memory — so it is normalised here rather than assumed
     * at the call sites.
     */
    private function textOf(Email $email): string
    {
        $body = $email->getTextBody();

        if (is_resource($body)) {
            return (string) stream_get_contents($body);
        }

        return (string) $body;
    }

    /**
     * Through Symfony's own mailer assertions rather than the profiler: the
     * message logger is on in the test environment whatever the profiler is
     * doing, and it survives the kernel being rebuilt between requests.
     */
    private function lastEmail(): ?Email
    {
        $message = self::getMailerMessage();

        return $message instanceof Email ? $message : null;
    }

    private function onlyRequest(): PasswordResetRequest
    {
        $repository = self::getContainer()->get(PasswordResetRequestRepository::class);
        self::assertInstanceOf(PasswordResetRequestRepository::class, $repository);

        $all = $repository->findBy([], ['id' => 'DESC']);
        self::assertNotEmpty($all, 'No reset request was recorded.');

        return $all[0];
    }

    private function reload(User $account): User
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->clear();

        $reloaded = $entityManager->find(User::class, $account->getId());
        self::assertInstanceOf(User::class, $reloaded);

        return $reloaded;
    }

    private function signIn(string $email, string $password): void
    {
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Sign in')->form([
            '_username' => $email,
            '_password' => $password,
        ]));
    }

    /**
     * Feature 008 gives every response a fresh policy nonce, so no two responses
     * are identical byte for byte. Blanking that one value is what lets
     * everything else be compared.
     */
    private function withoutTheNonce(string $body): string
    {
        return (string) preg_replace('/nonce="[^"]*"/', 'nonce="…"', $body);
    }
}
