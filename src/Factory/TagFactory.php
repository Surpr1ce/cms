<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\Tag;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Tag>
 */
final class TagFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Tag::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'name' => self::faker()->unique()->word(),
            'slug' => self::faker()->unique()->slug(1),
        ];
    }
}
