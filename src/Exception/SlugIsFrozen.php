<?php

declare(strict_types=1);

namespace App\Exception;

use function sprintf;

/**
 * An attempt was made to change the address of content that has been published.
 *
 * Once readers can link to something, its address stops moving. Changing it
 * would break every existing link and search result, which is not recoverable
 * from without a redirect mechanism this project does not yet have.
 */
final class SlugIsFrozen extends DomainException
{
    private function __construct(
        private readonly string $currentSlug,
        private readonly string $attemptedSlug,
    ) {
        parent::__construct(sprintf(
            'The address "%s" is frozen because the content has been published; "%s" was refused.',
            $currentSlug,
            $attemptedSlug,
        ));
    }

    public static function between(string $currentSlug, string $attemptedSlug): self
    {
        return new self($currentSlug, $attemptedSlug);
    }

    public function currentSlug(): string
    {
        return $this->currentSlug;
    }

    public function attemptedSlug(): string
    {
        return $this->attemptedSlug;
    }
}
