<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Factory\ArticleFactory;
use App\Factory\CategoryFactory;
use App\Factory\PageFactory;
use App\Factory\TagFactory;
use App\Repository\ArticleRepository;
use App\Repository\CategoryRepository;
use App\Repository\PageRepository;
use App\Repository\TagRepository;
use App\Service\Sitemap\SitemapAddresses;

use function count;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * What a site past the sitemap's ceiling loses, and in what order.
 *
 * The ceiling itself is `SitemapBudgetTest`'s: pure arithmetic, no database. This
 * is the other half — the *policy* — and it needs real repositories, because what
 * is being asserted is which query is asked first and what the ones after it get.
 *
 * Constructed with a ceiling of a handful rather than fifty thousand, which is
 * the only reason this is testable at all. Below the ceiling every list is
 * complete and the policy is invisible; at fifty thousand nobody is going to
 * create the rows to find out. The architecture pass before the release named
 * this exactly: the spend order lived in a controller action, reordering two
 * lines silently changed what a large site drops, and nothing went red.
 */
final class SitemapAddressesTest extends KernelTestCase
{
    use Factories;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    /**
     * Three of each kind, and room for four addresses — one of which the home page
     * has already taken before any list is asked.
     *
     * So: the articles take all three that are left, and the pages, sections and
     * labels get nothing. Content first is the whole policy, stated as an
     * assertion rather than as a comment above a render array.
     */
    public function testTheContentIsAnnouncedAndTheListingsAreWhatGetsDropped(): void
    {
        $this->populate();

        $collected = $this->addresses(4);

        self::assertCount(3, $collected['articles']);
        self::assertSame([], $collected['pages']);
        self::assertSame([], $collected['categories']);
        self::assertSame([], $collected['tags']);
    }

    /**
     * One more address than the articles need, so the boundary falls inside the
     * second list rather than between two.
     */
    public function testTheListThatRunsOutIsTruncatedRatherThanDropped(): void
    {
        $this->populate();

        $collected = $this->addresses(6);

        self::assertCount(3, $collected['articles']);
        self::assertCount(2, $collected['pages'], 'The page list should be cut where the budget ran out.');
        self::assertSame([], $collected['categories']);
        self::assertSame([], $collected['tags']);
    }

    /**
     * The ordinary case, and the one every real site is in: nothing is dropped and
     * the count is exactly what exists plus the home page.
     */
    public function testASiteUnderTheCeilingLosesNothing(): void
    {
        $this->populate();

        $collected = $this->addresses(100);

        self::assertCount(3, $collected['articles']);
        self::assertCount(3, $collected['pages']);
        self::assertCount(3, $collected['categories']);
        self::assertCount(3, $collected['tags']);

        $total = 1 + count($collected['articles']) + count($collected['pages'])
            + count($collected['categories']) + count($collected['tags']);

        self::assertSame(13, $total);
    }

    /**
     * A label is announced only when a published article carries it, so the labels
     * here are given one. Everything else is three of a kind, published, because
     * what is under test is the ceiling and not the published scope —
     * `SitemapTest` asserts that nothing unpublished is ever named.
     */
    private function populate(): void
    {
        $articles = ArticleFactory::new()->published()->many(3)->create();

        PageFactory::new()->published()->many(3)->create();
        CategoryFactory::createMany(3);

        foreach (TagFactory::createMany(3) as $index => $label) {
            $articles[$index]->addTag($label);
        }

        self::getContainer()->get('doctrine')->getManager()->flush();
    }

    /**
     * @return array{articles: list<mixed>, pages: list<mixed>, categories: list<mixed>, tags: list<mixed>}
     */
    private function addresses(int $ceiling): array
    {
        $container = self::getContainer();

        $articles = $container->get(ArticleRepository::class);
        $pages = $container->get(PageRepository::class);
        $categories = $container->get(CategoryRepository::class);
        $tags = $container->get(TagRepository::class);

        self::assertInstanceOf(ArticleRepository::class, $articles);
        self::assertInstanceOf(PageRepository::class, $pages);
        self::assertInstanceOf(CategoryRepository::class, $categories);
        self::assertInstanceOf(TagRepository::class, $tags);

        return new SitemapAddresses($articles, $pages, $categories, $tags, $ceiling)->collect();
    }
}
