<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PasswordResetRequestRepository;
use DateInterval;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Somebody's outstanding claim to be able to set a new password.
 *
 * **The token is stored as a hash, and this is the whole reason this class is
 * careful.** A reset token in a database *is* a working link: anybody who reads
 * the database — through a backup, a log, an injection — could sign in as
 * anybody. Storing a hash means a stolen database yields nothing usable, because
 * the value that opens the link exists only in the email that was sent.
 *
 * SHA-256 rather than the password hasher, deliberately. This is 128 bits of
 * randomness with nothing to guess, not a secret a person chose; a deliberately
 * slow hash would buy no strength and would put an expensive computation on an
 * unauthenticated route, which is a way to be knocked over rather than a defence.
 *
 * There is no setter for anything. A request is created, it is asked whether it
 * is usable, and it is consumed — the three things that can happen to it.
 */
#[ORM\Entity(repositoryClass: PasswordResetRequestRepository::class)]
// Every visit to a reset link looks a row up by this column, on a route nobody
// has to sign in for. Without the index that is a sequential scan of the whole
// table, which is a cheap thing for a stranger to ask for repeatedly.
#[ORM\Index(name: 'idx_password_reset_token_hash', fields: ['tokenHash'])]
class PasswordResetRequest
{
    /**
     * Long enough for somebody to find the message, short enough that a link
     * left in an inbox stops being a credential the same day.
     */
    public const string LIFETIME = 'PT1H';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Marked used rather than deleted, so that a second attempt with the same
     * link meets a request that says "already used" instead of meeting nothing.
     * Both are refused identically to whoever asked; the difference is only that
     * an operator reading the table can tell what happened.
     */
    #[ORM\Column]
    private bool $used = false;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private readonly User $account,
        /**
         * The hash of the token, never the token. Sixty-four hexadecimal
         * characters of SHA-256.
         */
        #[ORM\Column(length: 64)]
        private readonly string $tokenHash,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private readonly DateTimeImmutable $requestedAt,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAccount(): User
    {
        return $this->account;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getRequestedAt(): DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function isUsed(): bool
    {
        return $this->used;
    }

    public function hasExpiredBy(DateTimeImmutable $now): bool
    {
        return $now > $this->requestedAt->add(new DateInterval(self::LIFETIME));
    }

    /**
     * One question, asked in one place.
     *
     * A caller checking "not used" and "not expired" separately is a caller that
     * will one day check only one of them.
     */
    public function isUsableAt(DateTimeImmutable $now): bool
    {
        return !$this->used && !$this->hasExpiredBy($now);
    }

    /**
     * Spent. Deliberately one-way: there is no method that makes a used request
     * usable again, because there is no circumstance in which that is wanted.
     */
    public function consume(): void
    {
        $this->used = true;
    }
}
