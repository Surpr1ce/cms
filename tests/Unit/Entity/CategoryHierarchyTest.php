<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Article;
use App\Entity\Category;
use App\Entity\Tag;
use App\Entity\User;
use App\Exception\HierarchyWouldBeCircular;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * The two rules that make a taxonomy usable: a section cannot contain itself,
 * and an article is in one section but carries any number of labels.
 */
final class CategoryHierarchyTest extends TestCase
{
    public function testASectionCanBeNestedUnderAnother(): void
    {
        $parent = new Category('News', 'news');
        $child = new Category('Politics', 'politics');

        $child->setParent($parent);

        self::assertSame($parent, $child->getParent());
        self::assertSame([$child], $parent->getChildren());
        self::assertTrue($parent->hasChildren());
    }

    public function testASectionCannotBeItsOwnParent(): void
    {
        $category = new Category('News', 'news');

        $this->expectException(HierarchyWouldBeCircular::class);

        $category->setParent($category);
    }

    public function testASectionCannotBeItsOwnGrandparent(): void
    {
        $grandparent = new Category('News', 'news');
        $parent = new Category('Politics', 'politics');
        $parent->setParent($grandparent);

        $this->expectException(HierarchyWouldBeCircular::class);

        $grandparent->setParent($parent);
    }

    public function testACycleIsRefusedAtAnyDepth(): void
    {
        $root = new Category('A', 'a');
        $previous = $root;

        foreach (['b', 'c', 'd', 'e', 'f'] as $slug) {
            $next = new Category(strtoupper($slug), $slug);
            $next->setParent($previous);
            $previous = $next;
        }

        $this->expectException(HierarchyWouldBeCircular::class);

        $root->setParent($previous);
    }

    public function testTheRefusalNamesWhatItRefused(): void
    {
        $category = new Category('News', 'news');

        try {
            $category->setParent($category);
            self::fail('A section under itself should have been refused.');
        } catch (HierarchyWouldBeCircular $hierarchyWouldBeCircular) {
            self::assertSame('category', $hierarchyWouldBeCircular->entityType());
            self::assertSame('News', $hierarchyWouldBeCircular->label());
        }
    }

    public function testARefusedMoveLeavesTheTreeAlone(): void
    {
        $parent = new Category('News', 'news');
        $child = new Category('Politics', 'politics');
        $child->setParent($parent);

        try {
            $parent->setParent($child);
        } catch (HierarchyWouldBeCircular) {
            // Expected; the assertions below are the point of the test.
        }

        self::assertNull($parent->getParent());
        self::assertSame($parent, $child->getParent());
    }

    public function testASectionCanBeDetachedFromItsParent(): void
    {
        $parent = new Category('News', 'news');
        $child = new Category('Politics', 'politics');
        $child->setParent($parent);

        $child->setParent(null);

        self::assertNull($child->getParent());
        self::assertFalse($parent->hasChildren());
    }

    /**
     * FR-013: assigning replaces, because an article is in at most one section.
     */
    public function testAssigningASectionReplacesThePreviousOne(): void
    {
        $article = $this->article();
        $news = new Category('News', 'news');
        $opinion = new Category('Opinion', 'opinion');

        $article->setCategory($news);
        $article->setCategory($opinion);

        self::assertSame($opinion, $article->getCategory());
    }

    public function testAnArticleCanCarryManyLabels(): void
    {
        $article = $this->article();
        $php = new Tag('PHP', 'php');
        $symfony = new Tag('Symfony', 'symfony');

        $article->addTag($php);
        $article->addTag($symfony);

        self::assertCount(2, $article->getTags());
    }

    public function testAddingTheSameLabelTwiceChangesNothing(): void
    {
        $article = $this->article();
        $php = new Tag('PHP', 'php');

        $article->addTag($php);
        $article->addTag($php);

        self::assertCount(1, $article->getTags());
    }

    public function testALabelCanBeRemoved(): void
    {
        $article = $this->article();
        $php = new Tag('PHP', 'php');

        $article->addTag($php);
        $article->removeTag($php);

        self::assertSame([], $article->getTags());
        self::assertFalse($article->hasTag($php));
    }

    public function testRemovingALabelThatWasNeverThereChangesNothing(): void
    {
        $article = $this->article();
        $article->addTag(new Tag('PHP', 'php'));

        $article->removeTag(new Tag('Rust', 'rust'));

        self::assertCount(1, $article->getTags());
    }

    private function article(): Article
    {
        return new Article(
            'An article',
            'an-article',
            new User('author@example.com', 'An Author', new DateTimeImmutable('2026-01-01 00:00:00')),
            new DateTimeImmutable('2026-04-01 09:00:00'),
        );
    }
}
