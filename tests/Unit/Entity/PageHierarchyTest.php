<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Page;
use App\Exception\HierarchyWouldBeCircular;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * The page tree, which is also the site's menu.
 *
 * The same circularity rule as sections, and for the same reason — an unchecked
 * cycle is an infinite loop the first time a template walks the menu.
 */
final class PageHierarchyTest extends TestCase
{
    public function testAPageStartsAtTheTopLevelWithNoChildren(): void
    {
        $page = $this->page('about-us');

        self::assertNull($page->getParent());
        self::assertFalse($page->hasChildren());
        self::assertSame([], $page->getChildren());
    }

    public function testAPageCanBeNestedUnderAnother(): void
    {
        $parent = $this->page('about-us');
        $child = $this->page('our-team');

        $child->setParent($parent);

        self::assertSame($parent, $child->getParent());
        self::assertSame([$child], $parent->getChildren());
        self::assertTrue($parent->hasChildren());
    }

    public function testAPageCannotBeItsOwnParent(): void
    {
        $page = $this->page('about-us');

        $this->expectException(HierarchyWouldBeCircular::class);

        $page->setParent($page);
    }

    public function testAPageCannotBeItsOwnGrandparent(): void
    {
        $grandparent = $this->page('about-us');
        $parent = $this->page('our-team');
        $parent->setParent($grandparent);

        $this->expectException(HierarchyWouldBeCircular::class);

        $grandparent->setParent($parent);
    }

    public function testACycleIsRefusedAtAnyDepth(): void
    {
        $root = $this->page('level-0');
        $previous = $root;

        for ($depth = 1; $depth <= 6; ++$depth) {
            $next = $this->page('level-'.$depth);
            $next->setParent($previous);
            $previous = $next;
        }

        $this->expectException(HierarchyWouldBeCircular::class);

        $root->setParent($previous);
    }

    public function testTheRefusalNamesThePage(): void
    {
        $page = $this->page('about-us');

        try {
            $page->setParent($page);
            self::fail('A page under itself should have been refused.');
        } catch (HierarchyWouldBeCircular $hierarchyWouldBeCircular) {
            self::assertSame('page', $hierarchyWouldBeCircular->entityType());
            self::assertSame('A page', $hierarchyWouldBeCircular->label());
        }
    }

    public function testARefusedMoveLeavesTheTreeAlone(): void
    {
        $parent = $this->page('about-us');
        $child = $this->page('our-team');
        $child->setParent($parent);

        try {
            $parent->setParent($child);
        } catch (HierarchyWouldBeCircular) {
            // Expected; the assertions below are the point of the test.
        }

        self::assertNull($parent->getParent());
        self::assertSame([$child], $parent->getChildren());
    }

    public function testAPageCanBeMovedBackToTheTopLevel(): void
    {
        $parent = $this->page('about-us');
        $child = $this->page('our-team');
        $child->setParent($parent);

        $child->setParent(null);

        self::assertNull($child->getParent());
        self::assertFalse($parent->hasChildren());
    }

    public function testAPageCanBeMovedBetweenParents(): void
    {
        $first = $this->page('about-us');
        $second = $this->page('company');
        $child = $this->page('our-team');

        $child->setParent($first);
        $child->setParent($second);

        self::assertSame($second, $child->getParent());
        self::assertFalse($first->hasChildren());
        self::assertSame([$child], $second->getChildren());
    }

    public function testTheMenuPositionDefaultsToZeroAndCanBeSet(): void
    {
        $page = $this->page('about-us');

        self::assertSame(0, $page->getMenuOrder());

        $page->setMenuOrder(30);

        self::assertSame(30, $page->getMenuOrder());
    }

    /**
     * FR-019, restated as a compile-time fact rather than a runtime assertion:
     * Page has no author, category or tag accessors at all.
     */
    public function testAPageCarriesNoAuthorSectionOrLabels(): void
    {
        $page = $this->page('about-us');

        self::assertFalse(method_exists($page, 'getAuthor'));
        self::assertFalse(method_exists($page, 'getCategory'));
        self::assertFalse(method_exists($page, 'getTags'));
    }

    private function page(string $slug): Page
    {
        return new Page('A page', $slug, new DateTimeImmutable('2026-04-01 09:00:00'));
    }
}
