<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Publication was attempted on content that is not ready for it.
 *
 * The offending field is exposed so a caller can point at it, and so a test can
 * assert which rule fired without matching on the message.
 */
final class ContentNotPublishable extends DomainException
{
    private function __construct(private readonly string $field, string $message)
    {
        parent::__construct($message);
    }

    public static function withoutTitle(): self
    {
        return new self('title', 'Content cannot be published without a title.');
    }

    public static function withoutContent(): self
    {
        return new self('content', 'Content cannot be published with an empty body.');
    }

    public function field(): string
    {
        return $this->field;
    }
}
