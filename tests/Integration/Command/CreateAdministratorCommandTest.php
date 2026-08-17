<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\User;
use App\Factory\UserFactory;
use App\Repository\UserRepository;

use function count;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * SC-005: somebody can be given administrative access on a fresh installation
 * with no administration interface present.
 *
 * This is the bootstrap. If it does not work, a real deployment has a locked
 * door and no key.
 */
final class CreateAdministratorCommandTest extends KernelTestCase
{
    use Factories;

    private CommandTester $command;

    private UserRepository $users;

    protected function setUp(): void
    {
        self::bootKernel();

        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $this->users = $users;

        $kernel = self::$kernel;
        self::assertInstanceOf(KernelInterface::class, $kernel);

        $this->command = new CommandTester(
            new Application($kernel)->find('app:create-administrator'),
        );
    }

    public function testItCreatesAnAdministrator(): void
    {
        $this->command->execute([
            'email' => 'admin@example.com',
            'password' => 'a-long-enough-password',
            'displayName' => 'Alex Admin',
        ]);

        self::assertSame(Command::SUCCESS, $this->command->getStatusCode());

        $user = $this->users->findOneByEmail('admin@example.com');
        self::assertInstanceOf(User::class, $user);
        self::assertContains(User::ROLE_ADMIN, $user->getRoles());
        self::assertSame('Alex Admin', $user->getDisplayName());
    }

    public function testTheCredentialItSetsActuallyWorks(): void
    {
        $this->command->execute([
            'email' => 'admin@example.com',
            'password' => 'a-long-enough-password',
        ]);

        $user = $this->users->findOneByEmail('admin@example.com');
        self::assertInstanceOf(User::class, $user);

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        self::assertTrue($hasher->isPasswordValid($user, 'a-long-enough-password'));
        self::assertFalse($hasher->isPasswordValid($user, 'something-else-entirely'));
    }

    public function testThePasswordIsStoredHashedAndNeverInTheClear(): void
    {
        $this->command->execute([
            'email' => 'admin@example.com',
            'password' => 'a-long-enough-password',
        ]);

        $user = $this->users->findOneByEmail('admin@example.com');
        self::assertInstanceOf(User::class, $user);

        self::assertNotSame('a-long-enough-password', $user->getPassword());
        self::assertStringNotContainsString('a-long-enough-password', $user->getPassword());
        self::assertStringNotContainsString('a-long-enough-password', $this->command->getDisplay());
    }

    public function testTheDisplayNameFallsBackToTheAddress(): void
    {
        $this->command->execute([
            'email' => 'admin@example.com',
            'password' => 'a-long-enough-password',
        ]);

        $user = $this->users->findOneByEmail('admin@example.com');
        self::assertInstanceOf(User::class, $user);
        self::assertSame('admin@example.com', $user->getDisplayName());
    }

    /**
     * Running it twice is what somebody does when they have forgotten the
     * password. Failing on a unique-constraint violation would be an unhelpful
     * answer to that.
     */
    public function testAnExistingAccountIsPromotedRatherThanDuplicated(): void
    {
        UserFactory::new()->author()->create(['email' => 'existing@example.com']);

        $this->command->execute([
            'email' => 'existing@example.com',
            'password' => 'a-long-enough-password',
        ]);

        self::assertSame(Command::SUCCESS, $this->command->getStatusCode());
        self::assertCount(1, $this->users->findBy(['email' => 'existing@example.com']));

        $user = $this->users->findOneByEmail('existing@example.com');
        self::assertInstanceOf(User::class, $user);
        self::assertContains(User::ROLE_ADMIN, $user->getRoles());
    }

    public function testPromotingReplacesTheExistingCredential(): void
    {
        UserFactory::new()->author()->withPassword('the-old-password')->create(['email' => 'existing@example.com']);

        $this->command->execute([
            'email' => 'existing@example.com',
            'password' => 'the-brand-new-password',
        ]);

        $user = $this->users->findOneByEmail('existing@example.com');
        self::assertInstanceOf(User::class, $user);

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        self::assertTrue($hasher->isPasswordValid($user, 'the-brand-new-password'));
        self::assertFalse($hasher->isPasswordValid($user, 'the-old-password'));
    }

    public function testAShortPasswordIsRefused(): void
    {
        $this->command->execute(['email' => 'admin@example.com', 'password' => 'short']);

        self::assertSame(Command::INVALID, $this->command->getStatusCode());
        self::assertNull($this->users->findOneByEmail('admin@example.com'));
    }

    public function testAnEmptyPasswordIsRefused(): void
    {
        $this->command->execute(['email' => 'admin@example.com', 'password' => '']);

        self::assertSame(Command::INVALID, $this->command->getStatusCode());
        self::assertNull($this->users->findOneByEmail('admin@example.com'));
    }

    public function testAnEmptyEmailAddressIsRefused(): void
    {
        $this->command->execute(['email' => '   ', 'password' => 'a-long-enough-password']);

        self::assertSame(Command::INVALID, $this->command->getStatusCode());
    }

    /**
     * A refused run must leave nothing behind. Half-creating an account with no
     * usable credential would be worse than refusing outright.
     */
    public function testARefusedRunCreatesNothing(): void
    {
        $before = count($this->users->findAll());

        $this->command->execute(['email' => 'admin@example.com', 'password' => 'short']);

        self::assertCount($before, $this->users->findAll());
    }
}
