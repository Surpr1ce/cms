<?php

declare(strict_types=1);

namespace App\Service\Account;

use function mb_strlen;

/**
 * How long a password has to be, and what is said when it is not.
 *
 * This exists because the number was written in four places — the reset
 * controller, the account form's constraint, the console command that creates the
 * first administrator, and the help text on the accounts screen. Four places is
 * four chances to raise three of them, and a route that accepts a password the
 * others refuse is the kind of gap nobody notices until it is a finding.
 *
 * Static rather than injected, deliberately. There is no state, no collaborator
 * and nothing to configure, and `Assert\Length(min: ...)` needs a constant it can
 * read at compile time — a service could not supply one. {@see Paginator} takes
 * the same shape for the same reason.
 *
 * Length only. Anything stronger — character classes, a dictionary, a breach list
 * — is a policy decision this project has not made, and a complexity rule that
 * pushes people towards `Passw0rd!` would be theatre rather than security.
 */
final readonly class PasswordPolicy
{
    /**
     * Short enough not to be a nuisance, long enough that a password is not the
     * weak point next to everything else guarding an account.
     */
    public const int MINIMUM_LENGTH = 12;

    /**
     * @return string|null the sentence to show, or null when the password is
     *                     acceptable
     */
    public static function reasonToRefuse(string $password, string $confirmation): ?string
    {
        if (mb_strlen($password) < self::MINIMUM_LENGTH) {
            return self::tooShort();
        }

        if ($password !== $confirmation) {
            return 'The two passwords do not match.';
        }

        return null;
    }

    public static function tooShort(): string
    {
        return 'A password needs at least '.self::MINIMUM_LENGTH.' characters.';
    }
}
