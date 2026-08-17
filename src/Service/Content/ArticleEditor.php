<?php

declare(strict_types=1);

namespace App\Service\Content;

use App\Entity\Article;
use App\Entity\Tag;
use App\Entity\User;
use App\Form\Command\ArticleCommand;
use App\Repository\ArticleRepository;
use App\Service\Slug\UniqueSlugGenerator;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

use function in_array;

use Symfony\Component\Clock\ClockInterface;

/**
 * The one path by which an article is created or changed from a screen.
 *
 * Three things happen here that must happen everywhere, and therefore happen in
 * exactly one place:
 *
 *  1. Body text is sanitised. Not in the controller, not in the form, not in the
 *     command object — here, so that adding a screen cannot skip it.
 *  2. The address is generated on creation and regenerated when the title of
 *     unpublished content changes. This closes the gap feature 001 recorded and
 *     could not close: the entity can freeze an address but cannot make a new
 *     one, because uniqueness needs the database. The administration layer is
 *     the single entry point that record said it was waiting for.
 *  3. Nothing is flushed that the caller did not ask for.
 */
final readonly class ArticleEditor
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ArticleRepository $articles,
        private UniqueSlugGenerator $slugs,
        private ContentSanitiser $sanitiser,
        private ClockInterface $clock,
    ) {
    }

    public function create(ArticleCommand $command, User $author): Article
    {
        $title = $this->sanitiser->sanitiseText($command->title);

        $article = new Article(
            $title,
            $this->slugs->generate($title, $this->articles),
            $author,
            $this->clock->now(),
        );

        $this->apply($command, $article);
        $this->entityManager->persist($article);
        $this->entityManager->flush();

        return $article;
    }

    public function update(ArticleCommand $command, Article $article): void
    {
        $this->apply($command, $article);
        $this->entityManager->flush();
    }

    private function apply(ArticleCommand $command, Article $article): void
    {
        $title = $this->sanitiser->sanitiseText($command->title);

        $article->setTitle($title);
        $article->setExcerpt(
            null === $command->excerpt ? null : ($this->sanitiser->sanitiseText($command->excerpt) ?: null),
        );
        $article->setContent($this->sanitiser->sanitiseMarkup($command->content));
        $article->setCategory($command->category);
        $article->setFeaturedImage($command->featuredImage);

        $this->syncTags($command->tags, $article);
        $this->refreshSlug($article, $title);
    }

    /**
     * The address follows the title while nobody can have linked to it, and
     * stops the moment somebody can.
     *
     * `assignSlug()` refuses once the content has been published, so the guard
     * here is not the rule — the entity is. This avoids asking for a new address
     * that would only be rejected, and avoids the pointless database round trip
     * that generating one costs.
     */
    private function refreshSlug(Article $article, string $title): void
    {
        if ($article->getPublishedAt() instanceof DateTimeImmutable) {
            return;
        }

        $candidate = $this->slugs->generate($title, $this->articles);

        // Unchanged titles produce a taken address — their own — and the
        // generator would hand back a suffixed one. Comparing the base against
        // what is already there avoids "hello-world" quietly becoming
        // "hello-world-2" on every save.
        if ($this->wouldBeTheSameAddress($article->getSlug(), $candidate)) {
            return;
        }

        $article->assignSlug($candidate);
    }

    private function wouldBeTheSameAddress(string $current, string $candidate): bool
    {
        if ($current === $candidate) {
            return true;
        }

        return 1 === preg_match('/^'.preg_quote($current, '/').'-\d+$/', $candidate);
    }

    /**
     * @param list<Tag> $wanted
     */
    private function syncTags(array $wanted, Article $article): void
    {
        foreach ($article->getTags() as $existing) {
            if (!in_array($existing, $wanted, true)) {
                $article->removeTag($existing);
            }
        }

        foreach ($wanted as $tag) {
            $article->addTag($tag);
        }
    }
}
