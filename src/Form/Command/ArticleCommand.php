<?php

declare(strict_types=1);

namespace App\Form\Command;

use App\Entity\Article;
use App\Entity\Category;
use App\Entity\Media;
use App\Entity\Tag;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the article form collected. Plain data, and nothing else.
 *
 * The form does not write to an `Article` directly, and the reason is feature
 * 001's design rather than taste: an article has no setter for its status, its
 * address or its author, and it requires a title, an address and an author in
 * its constructor. A form cannot produce that, and adding setters to suit a form
 * would give away the invariants the entity exists to hold.
 *
 * So the form fills this, and ArticleEditor turns it into an article — which is
 * also the one place body text can be sanitised for every screen at once.
 */
final class ArticleCommand
{
    #[Assert\NotBlank(message: 'An article needs a title.')]
    #[Assert\Length(max: 200)]
    public string $title = '';

    #[Assert\Length(max: 500)]
    public ?string $excerpt = null;

    /**
     * Markup as typed. Sanitised by ArticleEditor before it reaches the entity —
     * never here, because a command object that sanitises would be doing work
     * the next command object would have to remember to repeat.
     */
    public string $content = '';

    public ?Category $category = null;

    /**
     * @var list<Tag>
     */
    public array $tags = [];

    public ?Media $featuredImage = null;

    public static function from(Article $article): self
    {
        $command = new self();
        $command->title = $article->getTitle();
        $command->excerpt = $article->getExcerpt();
        $command->content = $article->getContent();
        $command->category = $article->getCategory();
        $command->tags = $article->getTags();
        $command->featuredImage = $article->getFeaturedImage();

        return $command;
    }
}
