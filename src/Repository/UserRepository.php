<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
final class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findOneByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => $email]);
    }

    /**
     * One page of accounts for the administration screen.
     *
     * By address, which is what somebody scanning the list is reading, and the
     * column is unique — so it needs no tiebreak to make the order total.
     *
     * @return list<User>
     */
    public function findPage(int $limit, int $offset): array
    {
        return array_values($this->findBy([], ['email' => 'ASC'], $limit, $offset));
    }
}
