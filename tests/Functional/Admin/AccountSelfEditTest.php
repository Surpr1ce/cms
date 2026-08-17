<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\User;
use App\Factory\UserFactory;
use App\Tests\Functional\SigningOut;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The two things an administrator may not do to their own account here.
 *
 * Both were found by review rather than by the suite, and both are the same
 * mistake: a rule was written for one door and not for the door beside it.
 *
 * **Demotion.** FR-020 stops an administrator deleting their own account, so that
 * one administrator on a fresh installation cannot leave a site nobody can
 * administer. Unticking "Administrator" and saving reached exactly that state,
 * with no rule in the way and no test looking. Recovery was a shell.
 *
 * **Their own password.** The account page asks for the current password before
 * changing it, and says at length why: a browser left open on a shared machine is
 * not consent to hand the account over. This screen does not ask — correctly, an
 * administrator resetting somebody else's password cannot know it — so applied to
 * their own account it was a way round the control, turning a borrowed session
 * into a permanent one.
 *
 * Submitted directly rather than by looking for an absent control, for the reason
 * SC-004 gives: a template that hides a button is a courtesy, and the refusal has
 * to be the thing that refuses.
 */
final class AccountSelfEditTest extends WebTestCase
{
    use Factories;
    use SigningOut;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testAnAdministratorCannotRemoveTheirOwnAdministratorPermission(): void
    {
        $account = $this->signInAsAdministrator();

        $this->submitEdit($account, roles: [User::ROLE_EDITOR]);

        self::assertStringContainsString(
            'cannot remove your own administrator permission',
            $this->client->getCrawler()->filter('body')->text(),
        );
        self::assertContains(User::ROLE_ADMIN, $this->reload($account)->getRoles());
    }

    /**
     * The refusal has to hold when the whole form is submitted, not only when the
     * roles are the only thing that changed — otherwise renaming yourself in the
     * same save would carry the demotion through.
     */
    public function testTheDemotionIsRefusedEvenAlongsideOtherChanges(): void
    {
        $account = $this->signInAsAdministrator();

        $this->submitEdit($account, roles: [User::ROLE_AUTHOR], displayName: 'A New Name');

        $reloaded = $this->reload($account);

        self::assertContains(User::ROLE_ADMIN, $reloaded->getRoles());
        // Nothing was applied, rather than the name being saved and the roles
        // silently left alone — the refusal happens before anything is written.
        self::assertNotSame('A New Name', $reloaded->getDisplayName());
    }

    public function testAnAdministratorMayStillChangeSomebodyElsesPermissions(): void
    {
        $this->signInAsAdministrator();

        $other = UserFactory::new()->create([
            'email' => 'other@example.com',
            'roles' => [User::ROLE_ADMIN],
        ]);

        $this->submitEdit($other, roles: [User::ROLE_EDITOR], email: 'other@example.com');

        self::assertNotContains(User::ROLE_ADMIN, $this->reload($other)->getRoles());
    }

    public function testAnAdministratorCannotSetTheirOwnPasswordOnThisScreen(): void
    {
        $account = $this->signInAsAdministrator();
        $before = $account->getPassword();

        $this->submitEdit($account, password: 'a-brand-new-password');

        self::assertStringContainsString(
            'Change your own password on your account page',
            $this->client->getCrawler()->filter('body')->text(),
        );
        self::assertSame($before, $this->reload($account)->getPassword());
    }

    /**
     * The old password still works afterwards, which is the half that matters to
     * whoever owns the account: a borrowed session cannot lock them out.
     */
    public function testTheOwnersPasswordStillWorksAfterTheRefusal(): void
    {
        $account = $this->signInAsAdministrator();

        $this->submitEdit($account, password: 'a-brand-new-password');
        $this->signOut();

        $this->signInWith('admin@example.com', UserFactory::DEVELOPMENT_PASSWORD);

        self::assertResponseRedirects();
    }

    public function testAnAdministratorMayStillSetSomebodyElsesPassword(): void
    {
        $this->signInAsAdministrator();

        $other = UserFactory::new()->editor()->withPassword()->create(['email' => 'other@example.com']);
        $before = $other->getPassword();

        $this->submitEdit(
            $other,
            roles: [User::ROLE_EDITOR],
            email: 'other@example.com',
            password: 'a-brand-new-password',
        );

        self::assertNotSame($before, $this->reload($other)->getPassword());
    }

    // -------------------------------------------------------------- helpers

    /**
     * @param list<string> $roles
     */
    private function submitEdit(
        User $account,
        array $roles = [User::ROLE_ADMIN],
        string $email = 'admin@example.com',
        string $displayName = 'Alex Admin',
        string $password = '',
    ): void {
        $crawler = $this->client->request('GET', '/admin/manage/accounts/'.$account->getId().'/edit');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Save')->form();

        // Through the rendered form's own values rather than by assigning to the
        // fields: the permissions are three separate checkboxes, and assigning to
        // `account[roles]` reaches only the first of them — which quietly left
        // "Administrator" ticked and made an earlier version of this test pass
        // against a demotion that never happened. The token comes from the form,
        // so this is still a submission of that page rather than a bare POST.
        $values = $form->getPhpValues();
        $fields = $values['account'] ?? [];
        self::assertIsArray($fields);

        $fields['email'] = $email;
        $fields['displayName'] = $displayName;
        $fields['roles'] = $roles;
        $fields['password'] = $password;
        $values['account'] = $fields;

        $this->client->request('POST', $form->getUri(), $values);
    }

    private function signInAsAdministrator(): User
    {
        $account = UserFactory::new()->withPassword()->create([
            'email' => 'admin@example.com',
            'displayName' => 'Alex Admin',
            'roles' => [User::ROLE_ADMIN],
        ]);

        $this->signInWith('admin@example.com', UserFactory::DEVELOPMENT_PASSWORD);
        $this->client->followRedirect();

        return $account;
    }

    private function signInWith(string $email, string $password): void
    {
        $crawler = $this->client->request('GET', '/login');

        $this->client->submit($crawler->selectButton('Sign in')->form([
            '_username' => $email,
            '_password' => $password,
        ]));
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
}
