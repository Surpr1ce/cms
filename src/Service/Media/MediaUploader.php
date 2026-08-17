<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Entity\Media;
use App\Entity\User;
use App\Exception\DomainException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Receiving a file, in the order that leaves nothing behind when it goes wrong.
 *
 * Validate, then name, then write, then catalogue. The order is the design:
 *
 *  - Validation first, so a refused file is never written anywhere. FR-007 asks
 *    for neither a row nor a file after a refusal, and the cheapest way to
 *    guarantee that is to refuse before anything has happened.
 *  - The name is generated from the *detected* type, so the extension on disk
 *    describes the bytes rather than repeating a claim.
 *  - The row is written last. A row pointing at bytes that failed to arrive is a
 *    broken image; bytes with no row are a file nobody can find, which is
 *    untidy but harmless — so if one of the two must fail, it should be that one.
 */
final readonly class MediaUploader
{
    public function __construct(
        private UploadedFileValidator $validator,
        private StoredFilenameGenerator $filenames,
        private MediaStorage $storage,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws DomainException when the file may not be stored
     */
    public function upload(UploadedFile $file, string $altText, User $uploadedBy): Media
    {
        $detectedType = $this->validator->validate($file);

        $size = $file->getSize();
        $originalName = $file->getClientOriginalName();

        // Generated from the type alone. The client's name is carried forward
        // only as the display label below, and never touches a path.
        $storedName = $this->filenames->generate($detectedType);

        $this->storage->store($file, $storedName);

        $media = new Media(
            $storedName,
            $originalName,
            $detectedType,
            false === $size ? 0 : $size,
            $uploadedBy,
            $this->clock->now(),
        );
        $media->setAltText($altText);

        $this->entityManager->persist($media);
        $this->entityManager->flush();

        return $media;
    }
}
