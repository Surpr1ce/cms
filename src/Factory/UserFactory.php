<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\User;
use DateTimeImmutable;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<User>
 */
final class UserFactory extends PersistentObjectFactory
{
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
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'email' => self::faker()->unique()->safeEmail(),
            'displayName' => self::faker()->name(),
            'createdAt' => DateTimeImmutable::createFromMutable(self::faker()->dateTimeBetween('-1 year')),
            'roles' => [User::ROLE_AUTHOR],
            // A real hash of the word "password", so tests that authenticate can
            // do so without every factory call paying for a fresh hashing round.
            'password' => '$2y$04$0/eG0YoS/BAiZ0LP7QqSmO/AFXzHTe3iUAdKuFyMPTqYIeMxRIYQi',
        ];
    }
}
