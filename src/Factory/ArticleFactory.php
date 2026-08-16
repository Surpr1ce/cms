<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\Article;
use DateTimeImmutable;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Article>
 */
final class ArticleFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Article::class;
    }

    /**
     * The default already is a draft; this state exists so a test can say so
     * out loud where the status is the point of the test.
     */
    public function draft(): static
    {
        return $this;
    }

    public function published(): static
    {
        return $this->afterInstantiate(static function (Article $article): void {
            $article->publish(new DateTimeImmutable('-1 week'));
        });
    }

    public function archived(): static
    {
        return $this->afterInstantiate(static function (Article $article): void {
            $article->archive();
        });
    }

    /**
     * Published first, then archived — which is how most archived content gets
     * there, and the only way to end up archived *with* a publication date.
     */
    public function publishedThenArchived(): static
    {
        return $this->afterInstantiate(static function (Article $article): void {
            $article->publish(new DateTimeImmutable('-1 month'));
            $article->archive();
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'title' => self::faker()->sentence(4),
            'slug' => self::faker()->unique()->slug(3),
            'author' => UserFactory::new(),
            'createdAt' => DateTimeImmutable::createFromMutable(self::faker()->dateTimeBetween('-1 year')),
            'content' => self::faker()->paragraphs(3, true),
            'excerpt' => self::faker()->sentence(),
        ];
    }
}
