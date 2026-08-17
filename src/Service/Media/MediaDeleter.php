<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Entity\Media;
use App\Repository\ArticleRepository;
use App\Repository\PageRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Removes a catalogued file without taking the content that used it.
 *
 * `ON DELETE SET NULL` already clears the column, so why do this by hand? Because
 * the database updates the row and Doctrine's identity map does not: an Article
 * already loaded in the current request would keep returning a Media object for
 * a row that no longer exists. That is a defect waiting for its first
 * reproduction, and it would surface a long way from this line.
 *
 * The file on disk is not this service's concern. Cataloguing and storage are
 * separate, and the storage half arrives with the upload feature.
 */
final readonly class MediaDeleter
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ArticleRepository $articles,
        private PageRepository $pages,
        private MediaStorage $storage,
    ) {
    }

    public function delete(Media $media): void
    {
        foreach ($this->articles->findByFeaturedImage($media) as $article) {
            $article->setFeaturedImage(null);
        }

        foreach ($this->pages->findByFeaturedImage($media) as $page) {
            $page->setFeaturedImage(null);
        }

        $this->entityManager->remove($media);
        $this->entityManager->flush();

        // The bytes go after the row, not before.
        //
        // If removing the file failed first, the row would be gone and the bytes
        // would stay — a file nobody can find or delete through the application.
        // This way round, a failure leaves an orphaned file at worst, and the
        // catalogue is already correct.
        $this->storage->remove($media);
    }
}
