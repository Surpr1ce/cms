<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Somebody tried to change their own password through the accounts screen.
 *
 * The account page requires the current password before it will set a new one,
 * and says why at length: a browser left open on a shared machine is not consent
 * to hand the account over, and the point of changing a password is that
 * afterwards only one person knows it.
 *
 * The accounts screen sets a password without asking for the current one, which
 * is correct — an administrator resetting somebody else's password does not know
 * it. Applied to their own account it is a way round the control the other screen
 * enforces, and it turns a borrowed session into a permanent one by locking the
 * owner out. So that one case is refused here and sent to the screen that asks.
 */
final class OwnPasswordNeedsTheCurrentOne extends DomainException
{
    private function __construct()
    {
        parent::__construct(
            'Change your own password on your account page, where the current one is asked for. '
            .'Nothing on this form was saved.',
        );
    }

    public static function onAnAdministrationScreen(): self
    {
        return new self();
    }
}
