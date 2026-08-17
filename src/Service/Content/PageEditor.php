<?php

declare(strict_types=1);

namespace App\Service\Content;

use App\Entity\Page;
use App\Form\Command\PageCommand;
use App\Repository\PageRepository;
use App\Service\Slug\UniqueSlugGenerator;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * The same three guarantees as ArticleEditor — sanitising, address generation,
 * and no surprise flushes — for standalone pages.
 *
 * A page has no author, so there is no ownership question here at all; who may
 * do this was already decided by PageVoter, and it is editorial.
 */
final readonly class PageEditor
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PageRepository $pages,
        private UniqueSlugGenerator $slugs,
        private ContentSanitiser $sanitiser,
        private ClockInterface $clock,
    ) {
    }

    public function create(PageCommand $command): Page
    {
        $title = $this->sanitiser->sanitiseText($command->title);

        $page = new Page(
            $title,
            $this->slugs->generate($title, $this->pages),
            $this->clock->now(),
        );

        $this->apply($command, $page);
        $this->entityManager->persist($page);
        $this->entityManager->flush();

        return $page;
    }

    public function update(PageCommand $command, Page $page): void
    {
        $this->apply($command, $page);
        $this->entityManager->flush();
    }

    private function apply(PageCommand $command, Page $page): void
    {
        $title = $this->sanitiser->sanitiseText($command->title);

        $page->setTitle($title);
        $page->setExcerpt(
            null === $command->excerpt ? null : ($this->sanitiser->sanitiseText($command->excerpt) ?: null),
        );
        $page->setContent($this->sanitiser->sanitiseMarkup($command->content));
        $page->setMenuOrder($command->menuOrder);
        $page->setFeaturedImage($command->featuredImage);

        // setParent() refuses a cycle, so a page cannot be made its own ancestor
        // from a screen any more than it can from anywhere else.
        $page->setParent($command->parent);

        $this->refreshSlug($page, $title);
    }

    private function refreshSlug(Page $page, string $title): void
    {
        if ($page->getPublishedAt() instanceof DateTimeImmutable) {
            return;
        }

        $candidate = $this->slugs->generate($title, $this->pages);

        if ($this->wouldBeTheSameAddress($page->getSlug(), $candidate)) {
            return;
        }

        $page->assignSlug($candidate);
    }

    private function wouldBeTheSameAddress(string $current, string $candidate): bool
    {
        if ($current === $candidate) {
            return true;
        }

        return 1 === preg_match('/^'.preg_quote($current, '/').'-\d+$/', $candidate);
    }
}
