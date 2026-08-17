<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PasswordResetRequest;
use App\Entity\User;
use DateInterval;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PasswordResetRequest>
 */
final class PasswordResetRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PasswordResetRequest::class);
    }

    /**
     * Looks a request up by the hash of a token, never by the token.
     *
     * The parameter is named for what it is so that no caller can pass the wrong
     * one by accident — a method taking `$token` and hashing it inside would be
     * a method somebody could later "optimise" into a plain comparison.
     */
    public function findOneByTokenHash(string $tokenHash): ?PasswordResetRequest
    {
        return $this->findOneBy(['tokenHash' => $tokenHash]);
    }

    /**
     * The requests an account holds that would still open a link.
     *
     * Asking for a new link invalidates the earlier ones, and this is how they
     * are found. It used to return every request the account had ever made, used
     * and expired alike, on the reasoning that consuming an already-spent one
     * costs nothing. It does once the table is old: the rows are never removed,
     * so every reset loaded and rewrote the whole history — and a reset form open
     * to anybody is a way to make that history as long as you like.
     *
     * @return list<PasswordResetRequest>
     */
    public function findLiveFor(User $account, DateTimeImmutable $now): array
    {
        return array_values(
            $this->createQueryBuilder('request')
                ->andWhere('request.account = :account')
                ->andWhere('request.used = false')
                ->andWhere('request.requestedAt >= :cutoff')
                ->setParameter('account', $account)
                ->setParameter('cutoff', $this->cutoffFor($now))
                ->getQuery()
                ->getResult(),
        );
    }

    /**
     * Removes the requests that can no longer open anything.
     *
     * A used or expired row proves nothing and protects nobody — the refusal a
     * second attempt meets is the same whether the row is there or absent, since
     * an unknown token and a spent one are answered identically by design.
     *
     * @return int how many rows went
     */
    public function deleteSpentFor(User $account, DateTimeImmutable $now): int
    {
        return (int) $this->createQueryBuilder('request')
            ->delete()
            ->andWhere('request.account = :account')
            ->andWhere('request.used = true OR request.requestedAt < :cutoff')
            ->setParameter('account', $account)
            ->setParameter('cutoff', $this->cutoffFor($now))
            ->getQuery()
            ->execute();
    }

    /**
     * The moment before which a request has expired. Mirrors
     * {@see PasswordResetRequest::hasExpiredBy()} — expired means `now` is past
     * `requestedAt + LIFETIME`, which is the same as `requestedAt` being before
     * `now - LIFETIME`.
     */
    private function cutoffFor(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->sub(new DateInterval(PasswordResetRequest::LIFETIME));
    }
}
