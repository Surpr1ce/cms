<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\User;
use DateTimeImmutable;

use const PASSWORD_BCRYPT;

use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<User>
 */
final class UserFactory extends PersistentObjectFactory
{
    /**
     * The password every fixture account uses.
     *
     * Development only, and the fact that it is written here in the open is the
     * point: an account whose password is in the repository is an account nobody
     * can mistake for a real one. Production accounts are created with
     * `app:create-administrator`, which is where a real password goes.
     */
    public const string DEVELOPMENT_PASSWORD = 'development-only';

    public static function class(): string
    {
        return User::class;
    }

    public function admin(): static
    {
        return $this->with(['roles' => [User::ROLE_ADMIN]]);
    }

    public function editor(): static
    {
        return $this->with(['roles' => [User::ROLE_EDITOR]]);
    }

    public function author(): static
    {
        return $this->with(['roles' => [User::ROLE_AUTHOR]]);
    }

    /**
     * An account that can actually sign in, for tests and fixtures that need to.
     *
     * The hash is computed rather than pasted, so it stays correct if the hasher
     * configuration changes. It is deliberately not the default: most tests do
     * not sign in, and hashing on every factory call would be a cost paid by all
     * of them for the benefit of a few.
     */
    public function withPassword(string $plain = self::DEVELOPMENT_PASSWORD): static
    {
        return $this->afterInstantiate(static function (User $user) use ($plain): void {
            $user->setPassword(password_hash($plain, PASSWORD_BCRYPT, ['cost' => 4]));
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'email' => self::faker()->unique()->safeEmail(),
            'displayName' => self::faker()->name(),
            'createdAt' => DateTimeImmutable::createFromMutable(self::faker()->dateTimeBetween('-1 year')),
            'roles' => [User::ROLE_AUTHOR],
            // No password by default. An account with an empty hash cannot
            // authenticate — which is the correct default, and is itself a case
            // LoginTest asserts. Call withPassword() for an account that can
            // sign in.
        ];
    }
}
