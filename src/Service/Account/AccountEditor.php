<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Entity\AuditAction;
use App\Entity\User;
use App\Form\Command\AccountCommand;
use App\Service\Audit\AuditLog;
use Doctrine\ORM\EntityManagerInterface;

use function sort;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The one path by which an account is created or changed from a screen.
 *
 * Three things happen here that must happen every time, and therefore happen in
 * exactly one place:
 *
 * **A password is hashed, never stored as typed**, and a blank one leaves the
 * stored credential alone.
 *
 * **A change of permissions is recorded**, and only when they actually changed.
 * An entry for every edit of a display name would bury the one entry anybody
 * ever needs to find — the moment somebody was granted authority.
 *
 * **Creation is recorded.** An account appearing with no record of who made it
 * is the gap an audit log exists to close.
 */
final readonly class AccountEditor
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private AuditLog $audit,
        private ClockInterface $clock,
    ) {
    }

    public function create(AccountCommand $command): User
    {
        // The constructor takes no password: Symfony's hasher needs the user
        // object to choose a hasher, so the account exists before its hash does.
        // An empty hash matches nothing, so the intermediate state cannot
        // authenticate.
        $account = new User($command->email, $command->displayName, $this->clock->now());

        $account->setRoles($command->roles);
        $this->applyPassword($command, $account);

        $this->entityManager->persist($account);
        $this->entityManager->flush();

        $this->audit->record(AuditAction::AccountCreated, $account->getEmail());

        return $account;
    }

    public function update(AccountCommand $command, User $account): void
    {
        $before = $this->sorted($account->getRoles());

        $account->setEmail($command->email);
        $account->setDisplayName($command->displayName);
        $account->setRoles($command->roles);
        $this->applyPassword($command, $account);

        $this->entityManager->flush();

        if ($before !== $this->sorted($account->getRoles())) {
            $this->audit->record(AuditAction::AccountPermissionsChanged, $account->getEmail());
        }

        if (null !== $command->password && '' !== $command->password) {
            $this->audit->record(AuditAction::PasswordChanged, $account->getEmail());
        }
    }

    private function applyPassword(AccountCommand $command, User $account): void
    {
        // Anything that is not a non-empty string means "leave it alone", which
        // covers the blank field on an edit and an absent one entirely.
        if (null === $command->password || '' === $command->password) {
            return;
        }

        $account->setPassword($this->passwordHasher->hashPassword($account, $command->password));
    }

    /**
     * Order is not meaning. Two lists holding the same permissions in a
     * different order are the same permissions, and recording that as a change
     * would be noise.
     *
     * @param list<string> $roles
     *
     * @return list<string>
     */
    private function sorted(array $roles): array
    {
        sort($roles);

        return $roles;
    }
}
