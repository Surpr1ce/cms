<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * An administrator tried to take their own administrator permission away.
 *
 * FR-020 already stops an administrator deleting their own account, so that one
 * administrator on a fresh installation cannot leave a site nobody can
 * administer. Demotion reaches exactly the same place by a different door: untick
 * the box, save, and the accounts screen and the audit log are gone on the next
 * request, with no way back that does not involve shell access to the machine.
 *
 * The rule lives in the domain rather than in the screen because it is about the
 * installation staying administrable, not about what one form allows.
 */
final class AdministratorWouldLockThemselvesOut extends DomainException
{
    private function __construct()
    {
        parent::__construct(
            'You cannot remove your own administrator permission. '
            .'Ask another administrator to do it, so that this site is never left without one.',
        );
    }

    public static function byDemotion(): self
    {
        return new self();
    }
}
