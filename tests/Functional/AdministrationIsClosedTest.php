<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Factory\ArticleFactory;
use App\Factory\PageFactory;
use App\Factory\UserFactory;
use PHPUnit\Framework\Attributes\DataProvider;

use function sprintf;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Test\Factories;

/**
 * The gate. Constitution principle IV, in the feature it was written for:
 * every protected address gets an anonymous case *and* an insufficient-role
 * case.
 *
 * The distinction between the two outcomes is the point. Anonymous means "I do
 * not know who you are" and the answer is a redirect to the form. Signed in
 * without the role means "I know exactly who you are and the answer is no" —
 * and sending that person to a sign-in form they have already used would be a
 * loop they cannot escape.
 */
final class AdministrationIsClosedTest extends WebTestCase
{
    use Factories;
    use SigningOut;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testAnAnonymousRequestIsSentToSignIn(): void
    {
        $this->client->request('GET', '/admin');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    /**
     * FR-009: the redirect must not carry anything about what is behind the gate.
     */
    public function testAnAnonymousRequestDisclosesNothingAboutWhatIsBehindIt(): void
    {
        $this->client->request('GET', '/admin');
        $body = (string) $this->client->getResponse()->getContent();

        foreach (['Administration', 'Sign out', 'Signed in as'] as $leak) {
            self::assertStringNotContainsString($leak, $body);
        }
    }

    /**
     * FR-010. Recognised, and refused — not sent back to a form they have
     * already filled in.
     */
    public function testASignedInPersonWithoutAContentRoleIsRefusedRatherThanRedirected(): void
    {
        UserFactory::new()->withPassword()->create(['email' => 'nobody@example.com', 'roles' => []]);
        $this->signIn('nobody@example.com', UserFactory::DEVELOPMENT_PASSWORD);

        $this->client->request('GET', '/admin');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertFalse($this->client->getResponse()->isRedirection());
    }

    public function testAnAccountWithAnInventedRoleIsRefused(): void
    {
        UserFactory::new()->withPassword()->create([
            'email' => 'impostor@example.com',
            'roles' => ['ROLE_SUPERUSER'],
        ]);
        $this->signIn('impostor@example.com', UserFactory::DEVELOPMENT_PASSWORD);

        $this->client->request('GET', '/admin');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    /**
     * Each of the three content roles reaches the area. An administrator holds
     * only ROLE_ADMIN — there is no hierarchy granting them ROLE_AUTHOR — so
     * this is what would catch an access_control rule naming one role.
     *
     * @return iterable<string, array{list<string>}>
     */
    public static function contentRoleProvider(): iterable
    {
        yield 'author' => [[User::ROLE_AUTHOR]];
        yield 'editor' => [[User::ROLE_EDITOR]];
        yield 'administrator' => [[User::ROLE_ADMIN]];
    }

    /**
     * @param list<string> $roles
     */
    #[DataProvider('contentRoleProvider')]
    public function testEveryContentRoleReachesTheAdministrationArea(array $roles): void
    {
        UserFactory::new()->withPassword()->create(['email' => 'person@example.com', 'roles' => $roles]);
        $this->signIn('person@example.com', UserFactory::DEVELOPMENT_PASSWORD);

        $this->client->request('GET', '/admin');

        self::assertResponseIsSuccessful(sprintf('%s was locked out.', implode(', ', $roles)));
    }

    /**
     * SC-006: the public site is exactly as it was. This is a smaller version of
     * the whole feature-002 suite continuing to pass, kept here because a
     * firewall misconfigured to cover `^/` is a mistake somebody makes once.
     */
    public function testThePublicSiteIsStillOpenToEverybody(): void
    {
        ArticleFactory::new()->published()->create(['slug' => 'a-published-article']);
        PageFactory::new()->published()->create(['slug' => 'about-us']);

        foreach (['/', '/articles/a-published-article', '/about-us', '/login'] as $path) {
            $this->client->request('GET', $path);

            self::assertResponseIsSuccessful(sprintf('%s stopped being public.', $path));
        }
    }

    public function testThePublicNotFoundPageIsStillReachableAnonymously(): void
    {
        $this->client->request('GET', '/nothing-here');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /**
     * FR-024: revoking a role does not wait for the person to sign out.
     *
     * Worth reading `revokeRolesOf()` below before trusting this. The first two
     * attempts at this test failed, and the requirement was briefly weakened on
     * the grounds that an undemonstrable security property must not be claimed.
     * Both attempts were wrong about the test rather than about the code: they
     * modified an account belonging to an entity manager the kernel reboot had
     * already discarded, so the flush wrote nothing and the next request quite
     * correctly saw the old roles.
     *
     * A test that passes while proving nothing is worse than one that fails,
     * and this one came close to being the former in both directions.
     */
    public function testARevokedRoleTakesEffectOnTheNextRequest(): void
    {
        UserFactory::new()->editor()->withPassword()->create(['email' => 'editor@example.com']);
        $this->signIn('editor@example.com', UserFactory::DEVELOPMENT_PASSWORD);

        $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();

        $this->revokeRolesOf('editor@example.com');

        $this->client->request('GET', '/admin');

        self::assertFalse(
            $this->client->getResponse()->isSuccessful(),
            'A role taken away did not take effect until the next sign-in.',
        );
    }

    public function testARevokedRoleIsGoneAtTheNextSignIn(): void
    {
        UserFactory::new()->editor()->withPassword()->create(['email' => 'editor@example.com']);
        $this->signIn('editor@example.com', UserFactory::DEVELOPMENT_PASSWORD);
        $this->signOut();

        $this->revokeRolesOf('editor@example.com');

        $this->signIn('editor@example.com', UserFactory::DEVELOPMENT_PASSWORD);
        $this->client->request('GET', '/admin');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    /**
     * Reload the account through the *current* entity manager before changing it.
     *
     * The kernel is rebooted between requests, so an entity handed back by a
     * factory earlier in the test is managed by an entity manager that no longer
     * exists — flushing it writes nothing. The first version of these tests did
     * exactly that and passed while proving nothing, which is a worse outcome
     * than failing.
     */
    private function revokeRolesOf(string $email): void
    {
        $entityManager = self::getContainer()->get('doctrine')->getManager();

        $user = $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        $user->setRoles([]);
        $entityManager->flush();
        $entityManager->clear();
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
