<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\User;

use function count;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    public function testEveryAccountHoldsTheBaselineRole(): void
    {
        $user = $this->account();

        self::assertContains('ROLE_USER', $user->getRoles());
    }

    public function testTheBaselineRoleIsPresentEvenWithNoRolesStored(): void
    {
        $user = $this->account();
        $user->setRoles([]);

        self::assertSame(['ROLE_USER'], $user->getRoles());
    }

    public function testStoredRolesAreKeptAlongsideTheBaseline(): void
    {
        $user = $this->account();
        $user->setRoles([User::ROLE_EDITOR]);

        self::assertEqualsCanonicalizing([User::ROLE_EDITOR, 'ROLE_USER'], $user->getRoles());
    }

    public function testDuplicateRolesAreCollapsed(): void
    {
        $user = $this->account();
        $user->setRoles([User::ROLE_ADMIN, User::ROLE_ADMIN, 'ROLE_USER']);

        $roles = $user->getRoles();

        self::assertSame(
            array_values(array_unique($roles)),
            $roles,
            'getRoles() must not repeat a role.',
        );
    }

    public function testRolesAreReturnedAsAList(): void
    {
        $user = $this->account();
        $user->setRoles([User::ROLE_ADMIN, 'ROLE_USER', User::ROLE_EDITOR]);

        self::assertSame(
            range(0, count($user->getRoles()) - 1),
            array_keys($user->getRoles()),
            'Symfony expects a list, not an array with gaps in its keys.',
        );
    }

    public function testTheAccountIsIdentifiedByItsEmailAddress(): void
    {
        $user = $this->account('editor@example.com');

        self::assertSame('editor@example.com', $user->getUserIdentifier());
    }

    public function testSurroundingWhitespaceIsStrippedFromTheEmailAddress(): void
    {
        $user = $this->account('  editor@example.com  ');

        self::assertSame('editor@example.com', $user->getEmail());
    }

    public function testAnAccountCannotBeCreatedWithoutAnEmailAddress(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->account('   ');
    }

    public function testANewAccountHoldsNoUsableCredential(): void
    {
        self::assertSame('', $this->account()->getPassword());
    }

    public function testTheStoredCredentialIsWhateverWasHashedForIt(): void
    {
        $user = $this->account();
        $user->setPassword('$2y$13$notarealhashbutlongenough');

        self::assertSame('$2y$13$notarealhashbutlongenough', $user->getPassword());
    }

    public function testAnUnpersistedAccountHasNoIdentifier(): void
    {
        self::assertNull($this->account()->getId());
    }

    public function testTheCreationTimeIsTheOnePassedIn(): void
    {
        $now = new DateTimeImmutable('2026-03-01 09:00:00');

        self::assertSame($now, new User('a@example.com', 'A', $now)->getCreatedAt());
    }

    private function account(string $email = 'author@example.com'): User
    {
        return new User($email, 'An Author', new DateTimeImmutable('2026-01-01 12:00:00'));
    }
}
