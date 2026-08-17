<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Entity\Media;

use const DIRECTORY_SEPARATOR;

use InvalidArgumentException;

use function sprintf;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\File;

/**
 * The only class in the application that knows uploaded files have a path.
 *
 * Two properties are worth stating because they are the feature's whole point.
 *
 * The directory is outside the web root. `public/` is what a web server is
 * pointed at; `var/uploads/` is not, so there is no server configuration —
 * correct, mistaken, or inherited from somebody else's template — that can serve
 * these bytes directly. Whatever ends up in here, nothing will execute it,
 * because nothing will ever ask PHP to.
 *
 * A path is built from the generated name and nothing else. The name a client
 * supplied is not a parameter of any method here, so `../../public/shell.php`
 * cannot be assembled even by a mistake — the same design as
 * StoredFilenameGenerator and UploadedFileValidator, for the same reason.
 */
final readonly class MediaStorage
{
    public function __construct(
        private string $directory,
        private Filesystem $filesystem,
    ) {
    }

    /**
     * Moves an uploaded file into storage under a name this application chose.
     */
    public function store(File $file, string $storedName): void
    {
        $this->filesystem->mkdir($this->directory);

        $file->move($this->directory, $storedName);
    }

    /**
     * Writes bytes directly, for the development fixtures.
     *
     * Uploads go through store(), which takes a file the framework has already
     * received. The fixtures have no such file — they catalogue records without
     * uploading anything — so they need a way to give those records something to
     * point at. Same directory, same generated name, same guard on the path.
     */
    public function writeRaw(Media $media, string $contents): void
    {
        $this->filesystem->mkdir($this->directory);
        $this->filesystem->dumpFile($this->pathFor($media), $contents);
    }

    public function pathFor(Media $media): string
    {
        return $this->pathForName($media->getFilename());
    }

    public function exists(Media $media): bool
    {
        return is_file($this->pathFor($media));
    }

    /**
     * Removes the bytes, if they are there.
     *
     * A record whose file has already gone is not an error to delete: the
     * outcome asked for — no record, no bytes — is reached either way, and
     * raising here would leave a row nobody can remove.
     */
    public function remove(Media $media): void
    {
        $this->filesystem->remove($this->pathFor($media));
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * Guards the one thing that must never be true: that a name could reach
     * outside the directory.
     *
     * Generated names are hexadecimal plus a known extension, so this cannot
     * fire today. It is here because storage outliving the assumptions about
     * what calls it is exactly how traversal bugs appear.
     */
    private function pathForName(string $storedName): string
    {
        $basename = basename($storedName);

        if ($basename !== $storedName || '' === $basename) {
            throw new InvalidArgumentException(sprintf('"%s" is not a stored filename.', $storedName));
        }

        return $this->directory.DIRECTORY_SEPARATOR.$basename;
    }
}
