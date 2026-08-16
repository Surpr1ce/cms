<?php

declare(strict_types=1);

namespace App\Exception;

use function sprintf;

/**
 * A file with no alternative text was used as a lead image.
 *
 * Alternative text is not required to catalogue an upload — the uploader has not
 * necessarily reached that field yet — but it is required before the file is put
 * in front of a reader, because content must remain readable by people who
 * cannot see the image.
 */
final class MediaMissingAltText extends DomainException
{
    private function __construct(private readonly string $filename)
    {
        parent::__construct(sprintf(
            'The file "%s" has no alternative text and cannot be used as a lead image.',
            $filename,
        ));
    }

    public static function forFile(string $filename): self
    {
        return new self($filename);
    }

    public function filename(): string
    {
        return $this->filename;
    }
}
