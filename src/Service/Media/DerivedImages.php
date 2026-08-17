<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Entity\Media;
use App\Exception\ImageCannotBeResized;

use function bin2hex;

use const DIRECTORY_SEPARATOR;

use function is_file;
use function random_bytes;
use function sprintf;
use function str_contains;

use Symfony\Component\Filesystem\Filesystem;

/**
 * Smaller copies of stored images, made once and kept.
 *
 * **A derived image is a cache, not a record.** It can be reproduced from the
 * original at any moment, so nothing about it reaches the database: no table, no
 * migration, no row to go stale. Deleting the whole directory costs a little
 * processor time and nothing else, which is the property that makes it safe to
 * treat as disposable.
 *
 * They live beside the originals, outside the web root, and are served through
 * the same controller with the same headers. A derived file is still a file this
 * application wrote to disk, and feature 005's reasoning applies to it in full —
 * relaxing that for "it's only a thumbnail" is how a directory that cannot
 * execute anything acquires one that can.
 *
 * Writing goes through a temporary name and then a rename, because two readers
 * can ask for the same missing size at the same instant. A rename within a
 * directory is atomic, so the worst that happens is the work being done twice;
 * without it, the second reader can be served a half-written image.
 */
final readonly class DerivedImages
{
    private const string SUBDIRECTORY = 'derived';

    public function __construct(
        private MediaStorage $storage,
        private ImageResizer $resizer,
        private Filesystem $filesystem,
    ) {
    }

    /**
     * The path to a derived image, making it first if it is not there.
     *
     * @throws ImageCannotBeResized when the original cannot be read, is not an
     *                              image, or is too large to decode safely
     */
    public function pathFor(Media $media, ImageSize $size): string
    {
        $path = $this->pathWithoutMaking($media, $size);

        if (is_file($path)) {
            return $path;
        }

        $this->filesystem->mkdir($this->directory());

        // A temporary name in the same directory, so the rename below is a
        // rename rather than a copy across filesystems.
        $temporary = $path.'.'.bin2hex(random_bytes(8)).'.tmp';

        try {
            $this->resizer->resize(
                $this->storage->pathFor($media),
                $temporary,
                $media->getMimeType(),
                $size->longestSide(),
            );

            $this->filesystem->rename($temporary, $path, overwrite: true);
        } finally {
            // Whatever happened, no half-written file is left behind under a
            // name a later request would trust.
            $this->filesystem->remove($temporary);
        }

        return $path;
    }

    /**
     * Whether this file is the kind of thing that has smaller copies at all.
     *
     * A PDF does not. Asking for a thumbnail of one is answered with "not
     * found", which is the truth: there is no such image.
     */
    public function canDerive(Media $media): bool
    {
        return str_contains($media->getMimeType(), 'image/');
    }

    /**
     * Removes every size derived from a file.
     *
     * Called when the original goes. Without it the directory grows forever with
     * images nothing points at and nobody can name.
     */
    public function removeAllFor(Media $media): void
    {
        foreach (ImageSize::cases() as $size) {
            $this->filesystem->remove($this->pathWithoutMaking($media, $size));
        }
    }

    /**
     * The name carries the original's stored name and the size, and both halves
     * are things this application generated or enumerated. Nothing a client sent
     * reaches a path here, which is the same guarantee MediaStorage gives.
     */
    private function pathWithoutMaking(Media $media, ImageSize $size): string
    {
        return $this->directory().DIRECTORY_SEPARATOR.sprintf(
            '%s-%s',
            $size->value,
            $media->getFilename(),
        );
    }

    private function directory(): string
    {
        return $this->storage->directory().DIRECTORY_SEPARATOR.self::SUBDIRECTORY;
    }
}
