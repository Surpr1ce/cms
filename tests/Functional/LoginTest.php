<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Factory\UserFactory;

use function sprintf;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Test\Factories;

final class LoginTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testAnybodyCanOpenTheSignInPage(): void
    {
        $crawler = $this->client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('form[action="/login"]'));
        self::assertCount(1, $crawler->filter('input[name="_username"]'));
        self::assertCount(1, $crawler->filter('input[name="_password"]'));
    }

    public function testTheSignInPageRendersInTheSitesOwnLayout(): void
    {
        $crawler = $this->client->request('GET', '/login');

        self::assertCount(1, $crawler->filter('header'));
        self::assertCount(1, $crawler->filter('footer'));
    }

    /**
     * FR-004: without the token, another site could cause a sign-in on
     * somebody's behalf.
     */
    public function testTheFormCarriesAOneTimeToken(): void
    {
        $crawler = $this->client->request('GET', '/login');

        self::assertCount(1, $crawler->filter('input[name="_csrf_token"]'));
        self::assertNotSame('', (string) $crawler->filter('input[name="_csrf_token"]')->attr('value'));
    }

    public function testCorrectCredentialsSignSomebodyIn(): void
    {
        UserFactory::new()->author()->withPassword()->create(['email' => 'author@example.com']);

        $this->signIn('author@example.com', UserFactory::DEVELOPMENT_PASSWORD);

        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Administration', (string) $this->client->getResponse()->getContent());
    }

    public function testAWrongPasswordIsRefused(): void
    {
        UserFactory::new()->author()->withPassword()->create(['email' => 'author@example.com']);

        $this->signIn('author@example.com', 'not-the-password');
        $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->client->getCrawler()->filter('[role="alert"]'));
    }

    public function testAnUnknownEmailAddressIsRefused(): void
    {
        $this->signIn('nobody@example.com', 'any-password-at-all');
        $this->client->followRedirect();

        self::assertCount(1, $this->client->getCrawler()->filter('[role="alert"]'));
    }

    /**
     * SC-002, and the assertion this test class exists for.
     *
     * If the two messages ever differ, the sign-in form becomes a way to
     * discover which email addresses hold accounts — a list worth having to
     * anybody preparing to guess passwords.
     */
    public function testAWrongPasswordAndAnUnknownAddressSayTheSameThing(): void
    {
        UserFactory::new()->author()->withPassword()->create(['email' => 'author@example.com']);

        $this->signIn('author@example.com', 'not-the-password');
        $this->client->followRedirect();
        $wrongPassword = trim($this->client->getCrawler()->filter('[role="alert"]')->text());

        $this->signIn('nobody@example.com', 'not-the-password');
        $this->client->followRedirect();
        $unknownAddress = trim($this->client->getCrawler()->filter('[role="alert"]')->text());

        self::assertSame($unknownAddress, $wrongPassword);
    }

    /**
     * A newly created account has an empty stored credential. Nothing must
     * authenticate against it — least of all an empty password.
     */
    public function testAnAccountWithNoStoredCredentialCanNeverSignIn(): void
    {
        UserFactory::new()->author()->create(['email' => 'unusable@example.com']);

        foreach (['', ' ', 'anything', UserFactory::DEVELOPMENT_PASSWORD] as $attempt) {
            $this->signIn('unusable@example.com', $attempt);

            self::assertResponseRedirects(
                '/login',
                Response::HTTP_FOUND,
                sprintf('An empty credential accepted "%s".', $attempt),
            );
        }
    }

    public function testASubmissionWithoutTheTokenIsRefused(): void
    {
        UserFactory::new()->author()->withPassword()->create(['email' => 'author@example.com']);

        $this->client->request('POST', '/login', [
            '_username' => 'author@example.com',
            '_password' => UserFactory::DEVELOPMENT_PASSWORD,
            // No _csrf_token.
        ]);

        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertCount(1, $this->client->getCrawler()->filter('[role="alert"]'));
    }

    public function testSigningOutEndsRecognition(): void
    {
        UserFactory::new()->author()->withPassword()->create(['email' => 'author@example.com']);
        $this->signIn('author@example.com', UserFactory::DEVELOPMENT_PASSWORD);

        $this->client->request('POST', '/logout');

        $this->client->request('GET', '/admin');
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    /**
     * FR-006: somebody already recognised has no use for the form.
     */
    public function testASignedInPersonIsSentAwayFromTheForm(): void
    {
        UserFactory::new()->author()->withPassword()->create(['email' => 'author@example.com']);
        $this->signIn('author@example.com', UserFactory::DEVELOPMENT_PASSWORD);
        $this->client->followRedirect();

        $this->client->request('GET', '/login');

        self::assertResponseRedirects('/admin');
    }

    /**
     * FR-007: back to where they were going, not to a default.
     */
    public function testSomebodyIsReturnedToWhereTheyWereGoing(): void
    {
        UserFactory::new()->editor()->withPassword()->create(['email' => 'editor@example.com']);

        $this->client->request('GET', '/admin');
        self::assertResponseRedirects();

        $this->signIn('editor@example.com', UserFactory::DEVELOPMENT_PASSWORD);

        self::assertResponseRedirects('http://localhost/admin');
    }

    /**
     * SC-004. The password is in the request; it must be in nothing else.
     */
    public function testThePasswordIsNeverEchoedBack(): void
    {
        UserFactory::new()->author()->withPassword()->create(['email' => 'author@example.com']);

        $this->signIn('author@example.com', 'a-very-distinctive-wrong-password');
        $this->client->followRedirect();

        $body = (string) $this->client->getResponse()->getContent();

        self::assertStringNotContainsString('a-very-distinctive-wrong-password', $body);
        // The address is echoed so a mistyped password does not cost it too.
        self::assertStringContainsString('author@example.com', $body);
    }

    public function testTheStoredHashNeverAppearsInAResponse(): void
    {
        $user = UserFactory::new()->author()->withPassword()->create(['email' => 'author@example.com']);

        $this->signIn('author@example.com', UserFactory::DEVELOPMENT_PASSWORD);
        $this->client->followRedirect();

        self::assertStringNotContainsString(
            $user->getPassword(),
            (string) $this->client->getResponse()->getContent(),
        );
    }

    public function testTheRolesAnAccountHoldsSurviveSigningIn(): void
    {
        UserFactory::new()->admin()->withPassword()->create(['email' => 'admin@example.com']);

        $this->signIn('admin@example.com', UserFactory::DEVELOPMENT_PASSWORD);
        $this->client->followRedirect();

        $token = self::getContainer()->get('security.token_storage')->getToken();
        self::assertNotNull($token);

        $user = $token->getUser();
        self::assertInstanceOf(User::class, $user);
        self::assertContains(User::ROLE_ADMIN, $user->getRoles());
    }

    private function signIn(string $email, string $password): void
    {
        $crawler = $this->client->request('GET', '/login');

        $this->client->submit($crawler->selectButton('Sign in')->form([
            '_username' => $email,
            '_password' => $password,
        ]));
    }
}
