<?php

declare(strict_types=1);

namespace App\Twig;

use App\Security\Csp\NonceGenerator;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Makes this request's nonce available to templates as `csp_nonce()`.
 *
 * The name is not ours to choose. EasyAdmin's own templates already contain
 * `{% guard function csp_nonce %}` around their script tags — a guard that marks
 * them if a function by that name exists and leaves them alone if it does not.
 * Defining the function is therefore the whole integration with the generic
 * administration screens: no bundle, no template override, and nothing for a
 * future EasyAdmin upgrade to undo.
 *
 * The argument exists for the same reason. EasyAdmin calls `csp_nonce('script')`
 * and `csp_nonce('style')`, and both get the same value: the policy this project
 * builds names the nonce for scripts only, so the one on a stylesheet link is
 * ignored by the browser. Accepting the argument and disregarding it is what
 * keeps the contract compatible; pretending the two are separate secrets would
 * be a distinction the policy does not make.
 */
final class CspExtension extends AbstractExtension
{
    public function __construct(private readonly NonceGenerator $nonces)
    {
    }

    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('csp_nonce', $this->nonce(...)),
        ];
    }

    public function nonce(string $usage = 'script'): string
    {
        unset($usage);

        return $this->nonces->nonce();
    }
}
