<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\Page;
use DateTimeImmutable;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Page>
 */
final class PageFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Page::class;
    }

    public function draft(): static
    {
        return $this;
    }

    public function published(): static
    {
        return $this->afterInstantiate(static function (Page $page): void {
            $page->publish(new DateTimeImmutable('-1 week'));
        });
    }

    public function archived(): static
    {
        return $this->afterInstantiate(static function (Page $page): void {
            $page->archive();
        });
    }

    /**
     * Published first, then archived — which is how most archived content gets
     * there, and the only way to end up archived *with* a publication date.
     */
    public function publishedThenArchived(): static
    {
        return $this->afterInstantiate(static function (Page $page): void {
            $page->publish(new DateTimeImmutable('-1 month'));
            $page->archive();
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'title' => self::faker()->sentence(3),
            'slug' => self::faker()->unique()->slug(2),
            'createdAt' => DateTimeImmutable::createFromMutable(self::faker()->dateTimeBetween('-1 year')),
            'content' => self::faker()->paragraphs(2, true),
        ];
    }
}
