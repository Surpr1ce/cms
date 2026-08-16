<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

/**
 * Base class for every rule the domain refuses to break.
 *
 * Catching this means "a domain rule said no", without having to enumerate the
 * individual cases. Each subclass carries the values a caller needs as typed
 * accessors, so tests and error messages never have to parse the message string.
 *
 * The parent is RuntimeException rather than PHP's DomainException despite the
 * name: every refusal here depends on runtime state — what an account owns, what
 * status a piece of content is in — rather than on a programming mistake, and
 * RuntimeException is the accurate signal for that. The name describes the layer
 * the exception belongs to, not its PHP ancestry.
 */
abstract class DomainException extends RuntimeException
{
}
