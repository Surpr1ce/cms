<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Exception\DomainException;
use App\Exception\UnsupportedMediaType;
use App\Exception\UploadIsTooLarge;
use Symfony\Component\HttpFoundation\File\File;

/**
 * Decides whether a file may be stored at all.
 *
 * It reads two things: the bytes, and how many of them there are. The name the
 * client supplied is **not a parameter of any method here**, so it cannot be
 * consulted even by mistake — the same reasoning that made
 * StoredFilenameGenerator take only a MIME type in feature 001.
 *
 * That matters because a name is a claim. `photo.jpg` containing PHP source is
 * the oldest trick there is, and an application that believes the extension has
 * already lost. Symfony's File::getMimeType() reads the file's magic bytes, so
 * what this checks is what is actually there.
 *
 * The accepted types come from StoredFilenameGenerator, which already holds the
 * allow-list. One list, in one place: a validator and a namer that disagreed
 * about what is acceptable would accept files it could not name, or name files
 * it should have refused.
 */
final readonly class UploadedFileValidator
{
    public function __construct(
        private StoredFilenameGenerator $filenames,
        private int $maximumBytes,
    ) {
    }

    /**
     * @return string the detected MIME type, which is what gets recorded
     *
     * @throws DomainException when the file may not be stored
     */
    public function validate(File $file): string
    {
        $size = $file->getSize();

        if (false === $size || 0 === $size) {
            throw UploadIsTooLarge::becauseItIsEmpty();
        }

        if ($size > $this->maximumBytes) {
            throw UploadIsTooLarge::at($size, $this->maximumBytes);
        }

        // Read from the content, never from the name. getMimeType() uses the
        // magic bytes; getClientMimeType() would use what the browser claimed,
        // and a browser repeats what the file was called.
        $detected = $file->getMimeType();

        if (null === $detected || !$this->filenames->supports($detected)) {
            throw UnsupportedMediaType::forType($detected ?? 'unknown', StoredFilenameGenerator::supportedTypes());
        }

        return $detected;
    }

    public function maximumBytes(): int
    {
        return $this->maximumBytes;
    }
}
