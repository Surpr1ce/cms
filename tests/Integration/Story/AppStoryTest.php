<?php

declare(strict_types=1);

namespace App\Tests\Integration\Story;

use App\Entity\Article;
use App\Entity\Category;
use App\Entity\ContentStatus;
use App\Entity\Media;
use App\Entity\Page;
use App\Entity\User;
use App\Story\AppStory;

use function array_filter;
use function count;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The development dataset, loaded.
 *
 * This is the one part of the project every contributor runs on their first
 * afternoon and nothing tested. When an entity gains an invariant the story does
 * not satisfy, `doctrine:fixtures:load` starts failing — and it fails for the
 * person setting the project up, who has the least idea what changed.
 *
 * The assertions are about the *shape* the story is deliberately built to have,
 * not the exact titles. `AppStory` says so itself: content in all three states,
 * sections nested two deep, pages with an explicit order and one left as a draft,
 * files both with and without alternative text. A dataset where everything is
 * published and everything is described makes every screen look correct,
 * including the ones that are not — so if one of these ever becomes false, the
 * development site has quietly stopped exercising something.
 */
final class AppStoryTest extends KernelTestCase
{
    use Factories;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        AppStory::load();
        $this->entityManager->flush();
    }

    public function testItLoadsAtAll(): void
    {
        // Not a formality. Everything the story builds goes through the entity
        // constructors and the factories, so an invariant added to any entity
        // that the story does not satisfy fails right here — in CI, rather than
        // on somebody's first afternoon.
        self::assertNotEmpty($this->all(Article::class));
    }

    public function testTheTwoNamedAccountsCanBeSignedInAs(): void
    {
        $accounts = [];

        foreach ($this->all(User::class) as $user) {
            $accounts[$user->getEmail()] = $user;
        }

        self::assertArrayHasKey('admin@example.com', $accounts);
        self::assertArrayHasKey('editor@example.com', $accounts);

        // A stored credential, so the addresses in docs/setup.md actually work.
        self::assertNotSame('', $accounts['admin@example.com']->getPassword());
        self::assertContains(User::ROLE_ADMIN, $accounts['admin@example.com']->getRoles());
        self::assertContains(User::ROLE_EDITOR, $accounts['editor@example.com']->getRoles());
    }

    /**
     * All three states, which is what makes the administration list, the public
     * listings and the archive each have something in them.
     */
    public function testArticlesExistInEveryState(): void
    {
        $articles = $this->all(Article::class);

        foreach ([ContentStatus::Draft, ContentStatus::Published, ContentStatus::Archived] as $status) {
            self::assertNotEmpty(
                array_filter($articles, static fn (Article $a): bool => $a->getStatus() === $status),
                'No article is '.$status->value.'.',
            );
        }
    }

    public function testSectionsAreNestedRatherThanFlat(): void
    {
        $sections = $this->all(Category::class);

        self::assertNotEmpty(array_filter(
            $sections,
            static fn (Category $c): bool => $c->getParent() instanceof Category,
        ), 'Every section is top-level, so no screen exercises nesting.');
    }

    /**
     * One page is left unpublished on purpose, so the public menu and the
     * administration list are not the same list — which is the difference every
     * "only published content is reachable" test depends on being visible.
     */
    public function testThePagesIncludeADraftAndANestedOne(): void
    {
        $pages = $this->all(Page::class);

        self::assertNotEmpty(array_filter(
            $pages,
            static fn (Page $p): bool => ContentStatus::Draft === $p->getStatus(),
        ), 'Every page is published.');

        self::assertNotEmpty(array_filter(
            $pages,
            static fn (Page $p): bool => $p->getParent() instanceof Page,
        ), 'No page has a parent.');
    }

    /**
     * Files with and without alternative text. Without the second kind, the rule
     * that only described files may be a lead image is never met on a development
     * site — and it is a rule people meet by surprise.
     */
    public function testFilesExistBothDescribedAndNot(): void
    {
        $media = $this->all(Media::class);

        self::assertNotEmpty(array_filter($media, static fn (Media $m): bool => null !== $m->getAltText()));
        self::assertNotEmpty(array_filter($media, static fn (Media $m): bool => null === $m->getAltText()));
    }

    public function testSomeArticlesCarryALeadImageAndLabels(): void
    {
        $articles = $this->all(Article::class);

        self::assertNotEmpty(array_filter(
            $articles,
            static fn (Article $a): bool => $a->getFeaturedImage() instanceof Media,
        ));

        self::assertNotEmpty(array_filter(
            $articles,
            static fn (Article $a): bool => count($a->getTags()) > 0,
        ));
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    private function all(string $class): array
    {
        return array_values($this->entityManager->getRepository($class)->findAll());
    }
}
