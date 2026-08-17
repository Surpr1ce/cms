<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\User;
use App\Factory\UserFactory;
use App\Tests\Functional\SigningOut;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Test\Factories;

/**
 * Changing your own password, without telling anybody what it is going to be.
 *
 * The assertion that matters is `testTheCurrentPasswordIsRequired`. A session
 * proves that somebody was recognised at some point; it does not prove that the
 * person at the keyboard now is the same one. A change form that trusts the
 * session alone turns a browser left open on a shared machine into a way to take
 * an account over permanently — which is worse than reading the drafts, because
 * it survives the person coming back.
 */
final class ChangePasswordTest extends WebTestCase
{
    use Factories;
    use SigningOut;

    private const string CURRENT = 'the-current-password';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testSomebodySignedInCanChangeTheirOwnPassword(): void
    {
        $this->signIn();

        $this->submit(self::CURRENT, 'a-brand-new-password', 'a-brand-new-password');

        self::assertResponseRedirects('/admin/account');

        $this->signOut();
        $this->signInWith('a-brand-new-password');

        self::assertResponseRedirects();
    }

    public function testTheOldPasswordStopsWorking(): void
    {
        $this->signIn();

        $this->submit(self::CURRENT, 'a-brand-new-password', 'a-brand-new-password');

        $this->signOut();
        $this->signInWith(self::CURRENT);
        $this->client->followRedirect();

        self::assertCount(1, $this->client->getCrawler()->filter('[role="alert"]'));
    }

    /**
     * The assertion this file exists for.
     */
    public function testTheCurrentPasswordIsRequired(): void
    {
        $account = $this->signIn();
        $before = $this->reload($account)->getPassword();

        $this->submit('not-the-current-password', 'a-brand-new-password', 'a-brand-new-password');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString(
            'not your current password',
            $this->client->getCrawler()->filter('main')->text(),
        );
        self::assertSame($before, $this->reload($account)->getPassword());
    }

    public function testAShortPasswordIsRefusedAndNothingIsStored(): void
    {
        $account = $this->signIn();
        $before = $this->reload($account)->getPassword();

        $this->submit(self::CURRENT, 'short', 'short');

        self::assertStringContainsString('at least', $this->client->getCrawler()->filter('main')->text());
        self::assertSame($before, $this->reload($account)->getPassword());
    }

    public function testTwoDifferentNewPasswordsAreRefused(): void
    {
        $account = $this->signIn();
        $before = $this->reload($account)->getPassword();

        $this->submit(self::CURRENT, 'a-brand-new-password', 'a-different-password');

        self::assertStringContainsString('do not match', $this->client->getCrawler()->filter('main')->text());
        self::assertSame($before, $this->reload($account)->getPassword());
    }

    /**
     * A mistyped confirmation must not cost somebody their attempt at proving
     * who they are — so the current password is checked last.
     */
    public function testAMistypedConfirmationIsReportedRatherThanTheCurrentPassword(): void
    {
        $this->signIn();

        $this->submit('also-wrong', 'a-brand-new-password', 'a-different-password');

        self::assertStringContainsString('do not match', $this->client->getCrawler()->filter('main')->text());
    }

    public function testTheScreenIsClosedToSomebodyNotSignedIn(): void
    {
        $this->client->request('GET', '/admin/account');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    /**
     * FR-019, and the reason the roles on that screen are shown rather than
     * offered: somebody granting themselves an administrator's permissions from
     * their own account page is the oldest escalation there is.
     */
    public function testTheScreenOffersNoWayToChangeYourOwnPermissions(): void
    {
        $this->signIn();

        $crawler = $this->client->request('GET', '/admin/account');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('main input[name*="role"]'));
        self::assertCount(0, $crawler->filter('main select[name*="role"]'));
    }

    public function testNoResponseCarriesTheStoredHash(): void
    {
        $account = $this->signIn();

        $this->client->request('GET', '/admin/account');

        self::assertStringNotContainsString(
            $this->reload($account)->getPassword(),
            (string) $this->client->getResponse()->getContent(),
        );
    }

    private function submit(string $current, string $new, string $confirmation): void
    {
        $crawler = $this->client->request('GET', '/admin/account');

        $this->client->submit($crawler->selectButton('Change it')->form([
            'currentPassword' => $current,
            'password' => $new,
            'confirmation' => $confirmation,
        ]));
    }

    private function signIn(): User
    {
        $account = UserFactory::new()->editor()->create(['email' => 'editor@example.com']);

        $hasher = self::getContainer()->get('security.user_password_hasher');
        self::assertNotNull($hasher);

        $account->setPassword($hasher->hashPassword($account, self::CURRENT));
        $this->entityManager()->flush();

        $this->signInWith(self::CURRENT);
        $this->client->followRedirect();

        return $account;
    }

    private function signInWith(string $password): void
    {
        $crawler = $this->client->request('GET', '/login');

        $this->client->submit($crawler->selectButton('Sign in')->form([
            '_username' => 'editor@example.com',
            '_password' => $password,
        ]));
    }

    private function reload(User $account): User
    {
        $entityManager = $this->entityManager();
        $entityManager->clear();

        $reloaded = $entityManager->find(User::class, $account->getId());
        self::assertInstanceOf(User::class, $reloaded);

        return $reloaded;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }
}
