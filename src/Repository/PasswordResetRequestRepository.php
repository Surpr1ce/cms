<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PasswordResetRequest;
use App\Entity\User;
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
     * Every request belonging to an account, used or not.
     *
     * Asking for a new link invalidates the earlier ones, and this is how they
     * are found. All of them rather than the live ones: a used or expired
     * request is already refused, and consuming it costs nothing.
     *
     * @return list<PasswordResetRequest>
     */
    public function findAllFor(User $account): array
    {
        return array_values($this->findBy(['account' => $account]));
    }
}
