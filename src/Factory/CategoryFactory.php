<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\Category;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Category>
 */
final class CategoryFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Category::class;
    }

    public function childOf(Category $parent): static
    {
        return $this->afterInstantiate(static function (Category $category) use ($parent): void {
            $category->setParent($parent);
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'name' => self::faker()->unique()->words(2, true),
            'slug' => self::faker()->unique()->slug(2),
        ];
    }
}
