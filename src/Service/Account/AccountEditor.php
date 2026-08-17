<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Entity\AuditAction;
use App\Entity\User;
use App\Exception\AdministratorWouldLockThemselvesOut;
use App\Exception\OwnPasswordNeedsTheCurrentOne;
use App\Form\Command\AccountCommand;
use App\Service\Audit\AuditLog;
use Doctrine\ORM\EntityManagerInterface;

use function in_array;
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
 *
 * And two things nobody may do to their own account through this screen, both
 * refused before anything is applied — see
 * {@see refuseWhatTheActorMayNotDoToThemselves()}.
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

    /**
     * @param User $actor the signed-in person making the change, which is not
     *                    always the account being changed
     *
     * @throws AdministratorWouldLockThemselvesOut
     * @throws OwnPasswordNeedsTheCurrentOne
     */
    public function update(AccountCommand $command, User $account, User $actor): void
    {
        $this->refuseWhatTheActorMayNotDoToThemselves($command, $account, $actor);

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

    /**
     * The two things this screen must not let somebody do to their own account.
     *
     * Checked before anything is applied, so a refusal leaves the account exactly
     * as it was rather than half-saved. Both are about the actor and the target
     * being the same person; neither is a permission question, which is why they
     * are here and not in AdministrationVoter — the voter answers "may you manage
     * accounts", and the answer is yes in both of these cases.
     */
    private function refuseWhatTheActorMayNotDoToThemselves(
        AccountCommand $command,
        User $account,
        User $actor,
    ): void {
        if (!$this->isSameAccount($account, $actor)) {
            return;
        }

        if (in_array(User::ROLE_ADMIN, $account->getRoles(), true)
            && !in_array(User::ROLE_ADMIN, $command->roles, true)
        ) {
            throw AdministratorWouldLockThemselvesOut::byDemotion();
        }

        if (null !== $command->password && '' !== $command->password) {
            throw OwnPasswordNeedsTheCurrentOne::onAnAdministrationScreen();
        }
    }

    /**
     * By identifier where both have one, and by object where either does not —
     * comparing two nulls as equal would make every unsaved account everybody's.
     * The same comparison AdministrationVoter makes, for the same reason.
     */
    private function isSameAccount(User $account, User $actor): bool
    {
        $accountId = $account->getId();
        $actorId = $actor->getId();

        if (null === $accountId || null === $actorId) {
            return $account === $actor;
        }

        return $accountId === $actorId;
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
