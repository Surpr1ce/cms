<?php

declare(strict_types=1);

namespace App\Security\Csp;

use function bin2hex;
use function random_bytes;

/**
 * One nonce per request.
 *
 * A nonce is worth exactly as much as its unpredictability and its freshness. A
 * value reused across responses is a value an attacker can read from one page
 * and paste into an injection on the next, at which point the policy names a
 * source the attacker controls. So: a new value per request, and the same value
 * everywhere within one.
 *
 * Held in a property and cleared when a request begins, rather than stored on
 * the request object. Storing it on the request reads better and does not
 * survive the case that matters: when a controller throws, Symfony pops the
 * request off the stack and *then* renders the error page, so anything keyed to
 * "the current request" is unreachable at exactly the moment a 404 needs a
 * policy. A property is reachable throughout, and
 * {@see \App\EventSubscriber\SecurityHeadersSubscriber} clears it at the start
 * of every request a browser makes.
 */
final class NonceGenerator
{
    private ?string $nonce = null;

    public function nonce(): string
    {
        // 16 bytes. The specification asks for at least 128 bits of entropy and
        // this is exactly that; more would cost nothing but claim nothing.
        return $this->nonce ??= bin2hex(random_bytes(16));
    }

    /**
     * Forgets the current value, so the next request gets its own.
     *
     * Called for main requests only. A sub-request — a rendered fragment, or
     * the error controller — is part of the same thing a browser receives and
     * must keep the nonce the surrounding policy names.
     */
    public function forget(): void
    {
        $this->nonce = null;
    }
}
