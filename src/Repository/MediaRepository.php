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
     * One page of files for the administration screen.
     *
     * The screen used to ask `findRecent(100)`, which is a cap rather than a
     * page: the hundred-and-first file was simply not there, with nothing on
     * screen to say so. A page says how to reach the rest.
     *
     * The uploader is fetched with the file because the screen names them on
     * every row. Left to lazy loading that is one query per file, which is the
     * N+1 SC-003 exists to keep out — twenty files, twenty-one queries, and worse
     * on a fuller page.
     *
     * @return list<Media> newest first
     */
    public function findPage(int $limit, int $offset): array
    {
        return array_values(
            $this->createQueryBuilder('media')
                ->addSelect('uploader')
                ->innerJoin('media.uploadedBy', 'uploader')
                ->orderBy('media.uploadedAt', 'DESC')
                ->addOrderBy('media.id', 'DESC')
                ->setMaxResults($limit)
                ->setFirstResult($offset)
                ->getQuery()
                ->getResult(),
        );
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
