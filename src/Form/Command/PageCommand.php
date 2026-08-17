<?php

declare(strict_types=1);

namespace App\Form\Command;

use App\Entity\Media;
use App\Entity\Page;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the page form collected.
 *
 * Separate from ArticleCommand rather than a shared base with optional fields.
 * The two differ in exactly the way the entities differ — an author and a
 * section against a parent and a menu position — and a shared class would carry
 * the union of both with half of it null on any given screen. Nulls are where
 * rules go to hide.
 */
final class PageCommand
{
    #[Assert\NotBlank(message: 'A page needs a title.')]
    #[Assert\Length(max: 200)]
    public string $title = '';

    #[Assert\Length(max: 500)]
    public ?string $excerpt = null;

    public string $content = '';

    public ?Page $parent = null;

    #[Assert\PositiveOrZero(message: 'A menu position cannot be negative.')]
    public int $menuOrder = 0;

    public ?Media $featuredImage = null;

    /**
     * The version the form was opened on. See {@see ArticleCommand::$version} —
     * the reasoning is identical, which is what makes it right that the version
     * itself lives on the shared superclass.
     */
    public ?int $version = null;

    public static function from(Page $page): self
    {
        $command = new self();
        $command->version = $page->getVersion();
        $command->title = $page->getTitle();
        $command->excerpt = $page->getExcerpt();
        $command->content = $page->getContent();
        $command->parent = $page->getParent();
        $command->menuOrder = $page->getMenuOrder();
        $command->featuredImage = $page->getFeaturedImage();

        return $command;
    }
}
