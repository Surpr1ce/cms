<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Entity\AuditAction;
use App\Entity\PasswordResetRequest;
use App\Entity\User;
use App\Repository\PasswordResetRequestRepository;
use App\Repository\UserRepository;
use App\Service\Audit\AuditLog;

use function bin2hex;

use Doctrine\ORM\EntityManagerInterface;

use function hash;
use function random_bytes;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Requesting a reset, checking a link, and completing one.
 *
 * Three properties are the point of this class, and all three are things a
 * caller could get wrong if it were spread across a controller:
 *
 * **A token exists in plain form exactly once.** It is generated here, returned
 * to the caller so it can go into an email, and never stored — only its hash
 * reaches the database. Nothing can ask this class for a token afterwards,
 * because it no longer knows any.
 *
 * **Asking again invalidates what came before.** Two live links for one account
 * is two credentials where the person expected one, and the older one is the one
 * they have forgotten about.
 *
 * **Nothing here tells the caller whether an address holds an account.**
 * `request()` returns the token when there is an account and null when there is
 * not, and the controller does the same thing with both — which is what makes the
 * two responses identical rather than merely similar.
 */
final readonly class PasswordResetService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRepository $accounts,
        private PasswordResetRequestRepository $requests,
        private UserPasswordHasherInterface $passwordHasher,
        private ClockInterface $clock,
        private AuditLog $audit,
    ) {
    }

    /**
     * Starts a reset for an address, if it holds an account.
     *
     * @return array{User, string}|null the account and the plain token, or null
     *                                  when no account holds that address — which the caller must treat
     *                                  exactly as it treats the other case
     */
    public function request(string $email): ?array
    {
        $account = $this->accounts->findOneByEmail($email);

        if (!$account instanceof User) {
            return null;
        }

        // Everything outstanding is spent first. A person who asks twice has one
        // link, and it is the one they just received.
        foreach ($this->requests->findAllFor($account) as $existing) {
            $existing->consume();
        }

        // Sixteen bytes: 128 bits, which is the point below which guessing stops
        // being impossible. Hexadecimal so it survives a URL, an email client
        // and a copy-and-paste without being re-encoded into something else.
        $token = bin2hex(random_bytes(16));

        $this->entityManager->persist(new PasswordResetRequest(
            $account,
            $this->hash($token),
            $this->clock->now(),
        ));
        $this->entityManager->flush();

        return [$account, $token];
    }

    /**
     * The request a token opens, if it opens one that is still usable.
     *
     * Invalid, expired, used and superseded all answer null. The caller shows
     * one refusal for all four, because telling them apart tells somebody
     * holding a stolen link which kind of stolen link they have.
     */
    public function findUsable(string $token): ?PasswordResetRequest
    {
        $request = $this->requests->findOneByTokenHash($this->hash($token));

        if (!$request instanceof PasswordResetRequest) {
            return null;
        }

        return $request->isUsableAt($this->clock->now()) ? $request : null;
    }

    /**
     * Sets the new password and spends the link, in that order.
     *
     * The order matters: consuming first and then failing to store would leave
     * somebody with neither their old password nor a working link.
     */
    public function complete(PasswordResetRequest $request, string $plainPassword): User
    {
        $account = $request->getAccount();

        $account->setPassword($this->passwordHasher->hashPassword($account, $plainPassword));

        $request->consume();

        $this->entityManager->flush();

        // That the credential moved, never what it moved to. Nobody is signed in
        // at this point, so the entry records no actor and names whose password
        // it was — which is the half a reader needs.
        $this->audit->record(AuditAction::PasswordChanged, $account->getEmail());

        return $account;
    }

    /**
     * Changes a password for somebody who already knows the current one.
     *
     * The current password is required even though a session proves recognition,
     * because a session left open on a shared machine is not consent to hand the
     * account over.
     */
    public function change(User $account, string $currentPassword, string $newPassword): bool
    {
        if (!$this->passwordHasher->isPasswordValid($account, $currentPassword)) {
            return false;
        }

        $account->setPassword($this->passwordHasher->hashPassword($account, $newPassword));
        $this->entityManager->flush();

        $this->audit->record(AuditAction::PasswordChanged, $account->getEmail());

        return true;
    }

    /**
     * SHA-256, and see the entity for why it is not the password hasher.
     */
    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
