<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;

use function in_array;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Permissions that are about a capability rather than about a particular thing:
 * managing the taxonomy, managing files, managing accounts.
 *
 * Account deletion is the exception — it has a subject, because an administrator
 * must not be able to delete themselves and lock everybody out (FR-020). One
 * administrator on a fresh installation removing their own account would leave
 * a site nobody can administer and no interface to fix it with.
 *
 * @extends Voter<string, User|null>
 */
final class AdministrationVoter extends Voter
{
    public const string MANAGE_TAXONOMY = 'MANAGE_TAXONOMY';

    public const string MANAGE_MEDIA = 'MANAGE_MEDIA';

    public const string MANAGE_ACCOUNTS = 'MANAGE_ACCOUNTS';

    public const string DELETE_ACCOUNT = 'DELETE_ACCOUNT';

    /**
     * The three capability attributes are answered whatever subject arrives,
     * including none.
     *
     * The first version required `null === $subject` for them, reasoning that a
     * capability question carrying a subject was a question this voter had not
     * been asked. That was too strict, and feature 007 found out how: EasyAdmin
     * passes a subject when it checks an action permission, so every one of its
     * screens was silently denied — the voter abstained, nothing else answered,
     * and an administrator could not reach the account list.
     *
     * The correction is the honest reading. Whether somebody may manage accounts
     * does not depend on any subject, so a subject cannot change the answer and
     * refusing to answer because one was supplied protects nothing. DELETE_ACCOUNT
     * is different — it genuinely needs to know *which* account — and stays
     * strict.
     */
    protected function supports(string $attribute, mixed $subject): bool
    {
        if (self::DELETE_ACCOUNT === $attribute) {
            return $subject instanceof User;
        }

        return in_array($attribute, [self::MANAGE_TAXONOMY, self::MANAGE_MEDIA, self::MANAGE_ACCOUNTS], true);
    }

    protected function voteOnAttribute(
        string $attribute,
        mixed $subject,
        TokenInterface $token,
        ?Vote $vote = null,
    ): bool {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        return match ($attribute) {
            self::MANAGE_TAXONOMY, self::MANAGE_MEDIA => $this->isEditorial($user),
            self::MANAGE_ACCOUNTS => $this->isAdministrator($user),
            self::DELETE_ACCOUNT => $subject instanceof User && $this->canDeleteAccount($user, $subject),
            default => false,
        };
    }

    /**
     * Administrators only, and never their own.
     *
     * Whether the target still owns content is a different question, answered by
     * UserDeleter against the database. This one is about authority, and the two
     * are kept apart because an administrator who is permitted to delete an
     * account should still be told the account owns twelve articles.
     */
    private function canDeleteAccount(User $actor, User $target): bool
    {
        if (!$this->isAdministrator($actor)) {
            return false;
        }

        return !$this->isSameAccount($actor, $target);
    }

    private function isSameAccount(User $actor, User $target): bool
    {
        $actorId = $actor->getId();
        $targetId = $target->getId();

        if (null === $actorId || null === $targetId) {
            return $actor === $target;
        }

        return $actorId === $targetId;
    }

    private function isEditorial(User $user): bool
    {
        $roles = $user->getRoles();

        return in_array(User::ROLE_EDITOR, $roles, true)
            || in_array(User::ROLE_ADMIN, $roles, true);
    }

    private function isAdministrator(User $user): bool
    {
        return in_array(User::ROLE_ADMIN, $user->getRoles(), true);
    }
}
