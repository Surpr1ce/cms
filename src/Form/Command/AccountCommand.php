<?php

declare(strict_types=1);

namespace App\Form\Command;

use App\Entity\User;
use App\Service\Account\PasswordPolicy;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the account form collected.
 *
 * **The password is not bound to the entity and never carries the stored hash.**
 * `User::$password` holds a hash; a field mapped to it would display that hash on
 * the edit screen and write whatever was typed straight into storage. This
 * carries a *new* password or nothing at all, and `AccountEditor` hashes it.
 *
 * Blank means unchanged, which is why it is nullable and has no `NotBlank`. An
 * edit form that demanded a password to save a display name would train people
 * to retype one, and a retyped password is a weaker password.
 */
final class AccountCommand
{
    #[Assert\NotBlank(message: 'An account needs an email address.')]
    #[Assert\Email(message: 'That does not look like an email address.')]
    #[Assert\Length(max: 180)]
    public string $email = '';

    #[Assert\NotBlank(message: 'An account needs a display name.')]
    #[Assert\Length(max: 100)]
    public string $displayName = '';

    /**
     * @var list<string>
     */
    public array $roles = [];

    /**
     * Null on an edit that leaves the password alone. Required on creation,
     * which the form asks for rather than this class — the rule differs between
     * the two screens and a constraint here could only describe one of them.
     */
    #[Assert\Length(
        min: PasswordPolicy::MINIMUM_LENGTH,
        minMessage: 'A password needs at least {{ limit }} characters.',
    )]
    public ?string $password = null;

    public static function from(User $account): self
    {
        $command = new self();
        $command->email = $account->getEmail();
        $command->displayName = $account->getDisplayName();
        // Without ROLE_USER, which User appends on read and does not store.
        $command->roles = array_values(array_filter(
            $account->getRoles(),
            static fn (string $role): bool => 'ROLE_USER' !== $role,
        ));

        return $command;
    }
}
