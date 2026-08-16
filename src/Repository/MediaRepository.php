<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Media;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Media>
 */
final class MediaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Media::class);
    }

    public function findOneByFilename(string $filename): ?Media
    {
        return $this->findOneBy(['filename' => $filename]);
    }

    /**
     * @return list<Media> newest first
     */
    public function findRecent(int $limit = 20): array
    {
        return array_values($this->findBy([], ['uploadedAt' => 'DESC', 'id' => 'DESC'], $limit));
    }

    /**
     * Everything the account uploaded, which is half of what UserDeleter refuses
     * on. There is no status to filter by — a catalogued file is owned from the
     * moment it exists.
     */
    public function countUploadedBy(User $uploader): int
    {
        return $this->count(['uploadedBy' => $uploader]);
    }
}
