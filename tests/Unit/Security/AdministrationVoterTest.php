<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\User;
use App\Security\AdministrationVoter;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

use function sprintf;

use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class AdministrationVoterTest extends TestCase
{
    /**
     * @return iterable<string, array{list<string>, string, bool}>
     */
    public static function capabilityProvider(): iterable
    {
        $author = [User::ROLE_AUTHOR];
        $editor = [User::ROLE_EDITOR];
        $admin = [User::ROLE_ADMIN];

        yield 'author cannot manage taxonomy' => [$author, AdministrationVoter::MANAGE_TAXONOMY, false];
        yield 'author cannot manage files' => [$author, AdministrationVoter::MANAGE_MEDIA, false];
        yield 'author cannot manage accounts' => [$author, AdministrationVoter::MANAGE_ACCOUNTS, false];

        yield 'editor manages taxonomy' => [$editor, AdministrationVoter::MANAGE_TAXONOMY, true];
        yield 'editor manages files' => [$editor, AdministrationVoter::MANAGE_MEDIA, true];

        // FR-018, and the row a reviewer should look at first: running the site
        // and deciding who may run it are different authorities.
        yield 'editor cannot manage accounts' => [$editor, AdministrationVoter::MANAGE_ACCOUNTS, false];

        yield 'admin manages taxonomy' => [$admin, AdministrationVoter::MANAGE_TAXONOMY, true];
        yield 'admin manages files' => [$admin, AdministrationVoter::MANAGE_MEDIA, true];
        yield 'admin manages accounts' => [$admin, AdministrationVoter::MANAGE_ACCOUNTS, true];

        yield 'no roles manages nothing' => [[], AdministrationVoter::MANAGE_TAXONOMY, false];
        yield 'no roles cannot manage accounts' => [[], AdministrationVoter::MANAGE_ACCOUNTS, false];
        yield 'invented role manages nothing' => [['ROLE_SUPERUSER'], AdministrationVoter::MANAGE_TAXONOMY, false];
        yield 'invented role cannot manage accounts' => [['ROLE_SUPERUSER'], AdministrationVoter::MANAGE_ACCOUNTS, false];
    }

    /**
     * @param list<string> $roles
     */
    #[DataProvider('capabilityProvider')]
    public function testWhoMayManageWhat(array $roles, string $capability, bool $expected): void
    {
        $decision = new AdministrationVoter()->vote(
            new UsernamePasswordToken($this->account(1, $roles), 'main', $roles),
            null,
            [$capability],
        );

        self::assertSame(
            $expected ? VoterInterface::ACCESS_GRANTED : VoterInterface::ACCESS_DENIED,
            $decision,
        );
    }

    public function testAnAdministratorMayDeleteAnotherAccount(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voteOnDeleting($this->account(1, [User::ROLE_ADMIN]), $this->account(2, [User::ROLE_AUTHOR])),
        );
    }

    /**
     * FR-020. One administrator on a fresh installation removing their own
     * account leaves a site nobody can administer, and no interface to fix it
     * with — the account that could create a replacement is the one that just
     * went.
     */
    public function testAnAdministratorMayNotDeleteTheirOwnAccount(): void
    {
        $administrator = $this->account(1, [User::ROLE_ADMIN]);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voteOnDeleting($administrator, $administrator),
        );
    }

    public function testTheRuleFollowsTheAccountRatherThanTheObject(): void
    {
        // The same account loaded twice — two objects, one identifier, as
        // Doctrine may well hand back across a request.
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voteOnDeleting($this->account(1, [User::ROLE_ADMIN]), $this->account(1, [User::ROLE_ADMIN])),
        );
    }

    public function testAnEditorMayNotDeleteAnAccount(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voteOnDeleting($this->account(1, [User::ROLE_EDITOR]), $this->account(2, [User::ROLE_AUTHOR])),
        );
    }

    public function testAnAuthorMayNotDeleteAnAccount(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voteOnDeleting($this->account(1, [User::ROLE_AUTHOR]), $this->account(2, [User::ROLE_AUTHOR])),
        );
    }

    /**
     * A capability question carrying a subject is not the question this voter
     * answers, and it abstains rather than guessing.
     */
    public function testACapabilityQuestionWithASubjectIsAbstainedFrom(): void
    {
        $roles = [User::ROLE_ADMIN];

        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            new AdministrationVoter()->vote(
                new UsernamePasswordToken($this->account(1, $roles), 'main', $roles),
                $this->account(2, []),
                [AdministrationVoter::MANAGE_ACCOUNTS],
            ),
        );
    }

    public function testAnUnknownCapabilityIsAbstainedFrom(): void
    {
        $roles = [User::ROLE_ADMIN];

        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            new AdministrationVoter()->vote(
                new UsernamePasswordToken($this->account(1, $roles), 'main', $roles),
                null,
                ['MANAGE_INVENTED'],
            ),
        );
    }

    private function voteOnDeleting(User $actor, User $target): int
    {
        return new AdministrationVoter()->vote(
            new UsernamePasswordToken($actor, 'main', $actor->getRoles()),
            $target,
            [AdministrationVoter::DELETE_ACCOUNT],
        );
    }

    /**
     * @param list<string> $roles
     */
    private function account(int $id, array $roles): User
    {
        $user = new User(sprintf('person-%d@example.com', $id), 'A Person', new DateTimeImmutable('2026-01-01 00:00:00'));
        $user->setRoles($roles);

        $property = new ReflectionProperty(User::class, 'id');
        $property->setValue($user, $id);

        return $user;
    }
}
