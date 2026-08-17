<?php

declare(strict_types=1);

namespace App\Form\Command;

use App\Entity\Category;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the section form collected.
 *
 * The same shape as ArticleCommand and for the same reason: `Category` requires
 * a name and an address in its constructor and refuses to have its address
 * changed after publication, so a form cannot fill one directly without the
 * entity giving away the rules it exists to hold.
 *
 * There is no address field, deliberately. It is generated once from the name
 * and then fixed — a form offering to edit one invites breaking every link to a
 * section that already exists.
 */
final class SectionCommand
{
    #[Assert\NotBlank(message: 'A section needs a name.')]
    #[Assert\Length(max: 100)]
    public string $name = '';

    #[Assert\Length(max: 500)]
    public ?string $description = null;

    public ?Category $parent = null;

    public static function from(Category $section): self
    {
        $command = new self();
        $command->name = $section->getName();
        $command->description = $section->getDescription();
        $command->parent = $section->getParent();

        return $command;
    }
}
