<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AuditEntry;

use function array_values;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Reading the record.
 *
 * There is no method here that writes, changes or removes anything, and that is
 * the design rather than a gap: `AuditLog` persists, and nothing else in the
 * application touches an entry at all.
 *
 * @extends ServiceEntityRepository<AuditEntry>
 */
final class AuditEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditEntry::class);
    }

    /**
     * Newest first, with the acting account fetched alongside.
     *
     * The join is a left join because an entry can outlive its actor — an inner
     * join here would silently hide every entry belonging to a deleted account,
     * which is precisely the history somebody is most likely to be looking for.
     *
     * @return list<AuditEntry>
     */
    public function findPage(int $limit, int $offset): array
    {
        $result = $this->createQueryBuilder('entry')
            ->leftJoin('entry.actor', 'actor')
            ->addSelect('actor')
            ->orderBy('entry.occurredAt', 'DESC')
            ->addOrderBy('entry.id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();

        return array_values($result);
    }
}
