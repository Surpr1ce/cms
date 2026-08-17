<?php

declare(strict_types=1);

namespace App\Exception;

use function sprintf;

/**
 * An upload was refused on size — too big, or nothing at all.
 *
 * Both are size problems and both leave nothing stored, so they share a class;
 * the accessors say which happened, for a message that can name the limit.
 */
final class UploadIsTooLarge extends DomainException
{
    private function __construct(
        private readonly int $size,
        private readonly int $limit,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function at(int $size, int $limit): self
    {
        return new self($size, $limit, sprintf(
            'That file is %s. The limit is %s.',
            self::humanise($size),
            self::humanise($limit),
        ));
    }

    public static function becauseItIsEmpty(): self
    {
        return new self(0, 0, 'That file is empty.');
    }

    public function size(): int
    {
        return $this->size;
    }

    public function limit(): int
    {
        return $this->limit;
    }

    public function isEmpty(): bool
    {
        return 0 === $this->size;
    }

    private static function humanise(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' bytes';
        }

        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024).' KB';
        }

        return round($bytes / (1024 * 1024), 1).' MB';
    }
}
