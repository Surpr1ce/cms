<?php

declare(strict_types=1);

namespace App\Exception;

use function sprintf;

/**
 * A smaller copy of an image could not be made.
 *
 * One class rather than four, with named constructors for the reasons — the
 * caller's response is the same in every case (answer "not found" and serve the
 * original), so distinguishing them in the type system would buy nothing, while
 * the reason still has to reach a log.
 *
 * Refusing an enormous image is here rather than in the upload validator on
 * purpose. A file may be well within the eight-megabyte limit and still decode
 * to two hundred megabytes of pixels, and the number of pixels is not knowable
 * from a file's size.
 */
final class ImageCannotBeResized extends DomainException
{
    public static function becauseItCouldNotBeRead(string $path): self
    {
        return new self(sprintf('The image at "%s" could not be read.', $path));
    }

    public static function becauseItCouldNotBeWritten(string $path): self
    {
        return new self(sprintf('The resized image could not be written to "%s".', $path));
    }

    public static function becauseItIsTooLarge(int $pixels, int $limit): self
    {
        return new self(sprintf(
            'The image is %d pixels, past the limit of %d — decoding it would cost more memory than a request may spend.',
            $pixels,
            $limit,
        ));
    }

    public static function becauseTheTypeHasNoImage(string $mimeType): self
    {
        return new self(sprintf('"%s" is not a type this application can resize.', $mimeType));
    }

    public static function becauseScalingFailed(): self
    {
        return new self('The image library refused to scale the image.');
    }
}
