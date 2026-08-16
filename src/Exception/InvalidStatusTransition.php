<?php

declare(strict_types=1);

namespace App\Exception;

use App\Entity\ContentStatus;

use function sprintf;

/**
 * A transition the state machine does not allow was attempted.
 *
 * Thrown rather than ignored: a silent no-op and a successful transition look
 * identical from outside, so a caller that gets one when it expected the other
 * has no way to find out.
 */
final class InvalidStatusTransition extends DomainException
{
    private function __construct(
        private readonly ContentStatus $current,
        private readonly ContentStatus $attempted,
    ) {
        parent::__construct(sprintf(
            'Content in status "%s" cannot move to "%s". Allowed from here: %s.',
            $current->value,
            $attempted->value,
            implode(', ', array_map(
                static fn (ContentStatus $status): string => $status->value,
                $current->allowedTransitions(),
            )),
        ));
    }

    public static function between(ContentStatus $current, ContentStatus $attempted): self
    {
        return new self($current, $attempted);
    }

    public function current(): ContentStatus
    {
        return $this->current;
    }

    public function attempted(): ContentStatus
    {
        return $this->attempted;
    }
}
