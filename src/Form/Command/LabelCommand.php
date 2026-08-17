<?php

declare(strict_types=1);

namespace App\Form\Command;

use App\Entity\Tag;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the label form collected. A name, and nothing else.
 *
 * Separate from SectionCommand rather than shared: the two are the same size
 * today and are not the same thing — a section nests and carries a description,
 * a label does neither — and a shared class would be a place for one of them to
 * quietly acquire the other's fields.
 */
final class LabelCommand
{
    #[Assert\NotBlank(message: 'A label needs a name.')]
    #[Assert\Length(max: 50)]
    public string $name = '';

    public static function from(Tag $label): self
    {
        $command = new self();
        $command->name = $label->getName();

        return $command;
    }
}
