<?php

declare(strict_types=1);

namespace App\Exception;

use function sprintf;

/**
 * A stored filename was requested for a type the CMS does not accept.
 *
 * The allow-list is deliberately short. Deciding what may be stored by naming
 * what is permitted, rather than by naming what is forbidden, is what keeps an
 * unanticipated type from being accepted by default.
 */
final class UnsupportedMediaType extends DomainException
{
    /**
     * @param list<string> $supported
     */
    private function __construct(
        private readonly string $mimeType,
        private readonly array $supported,
    ) {
        parent::__construct(sprintf(
            'The type "%s" is not accepted. Accepted types: %s.',
            $mimeType,
            implode(', ', $supported),
        ));
    }

    /**
     * @param list<string> $supported
     */
    public static function forType(string $mimeType, array $supported): self
    {
        return new self($mimeType, $supported);
    }

    public function mimeType(): string
    {
        return $this->mimeType;
    }

    /**
     * @return list<string>
     */
    public function supported(): array
    {
        return $this->supported;
    }
}
