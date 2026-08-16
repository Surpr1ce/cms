<?php

declare(strict_types=1);

namespace App\Exception;

use function sprintf;

/**
 * Deletion was attempted on an account that still owns content.
 *
 * The counts are carried separately so a future admin screen can say what is
 * blocking the deletion rather than only that something is. Archiving content
 * does not release ownership, so the counts include archived items.
 */
final class UserStillOwnsContent extends DomainException
{
    private function __construct(
        private readonly string $email,
        private readonly int $articleCount,
        private readonly int $mediaCount,
    ) {
        parent::__construct(sprintf(
            'The account "%s" still owns %d article(s) and %d file(s) and cannot be deleted.',
            $email,
            $articleCount,
            $mediaCount,
        ));
    }

    public static function with(string $email, int $articleCount, int $mediaCount): self
    {
        return new self($email, $articleCount, $mediaCount);
    }

    public function email(): string
    {
        return $this->email;
    }

    public function articleCount(): int
    {
        return $this->articleCount;
    }

    public function mediaCount(): int
    {
        return $this->mediaCount;
    }
}
