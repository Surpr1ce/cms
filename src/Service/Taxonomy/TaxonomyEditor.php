<?php

declare(strict_types=1);

namespace App\Service\Taxonomy;

use App\Entity\Category;
use App\Entity\Tag;
use App\Form\Command\LabelCommand;
use App\Form\Command\SectionCommand;
use App\Repository\CategoryRepository;
use App\Repository\TagRepository;
use App\Service\Slug\UniqueSlugGenerator;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one path by which a section or a label is created or changed.
 *
 * Two rules live here and nowhere else, which is the point of the class:
 *
 * **An address is generated once and then left alone.** Both entities refuse a
 * new address after one exists, so this only asks for one on creation. Renaming
 * a section keeps its address, because readers and search engines already have
 * it — the same reasoning that freezes an article's address at publication.
 *
 * **Nothing is flushed that the caller did not ask for.**
 *
 * Sections and labels share a class where articles and pages do not, because
 * these two really are the same operation on different tables: a name, and for
 * one of them a description and a parent. The difference is one `if`, not a
 * second set of rules.
 */
final readonly class TaxonomyEditor
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CategoryRepository $sections,
        private TagRepository $labels,
        private UniqueSlugGenerator $slugs,
    ) {
    }

    public function createSection(SectionCommand $command): Category
    {
        $section = new Category($command->name, $this->slugs->generate($command->name, $this->sections));

        $this->applyToSection($command, $section);

        $this->entityManager->persist($section);
        $this->entityManager->flush();

        return $section;
    }

    public function updateSection(SectionCommand $command, Category $section): void
    {
        $this->applyToSection($command, $section);

        $this->entityManager->flush();
    }

    public function createLabel(LabelCommand $command): Tag
    {
        $label = new Tag($command->name, $this->slugs->generate($command->name, $this->labels));

        $this->entityManager->persist($label);
        $this->entityManager->flush();

        return $label;
    }

    public function updateLabel(LabelCommand $command, Tag $label): void
    {
        $label->setName($command->name);

        $this->entityManager->flush();
    }

    private function applyToSection(SectionCommand $command, Category $section): void
    {
        $section->setName($command->name);
        $section->setDescription('' === $command->description ? null : $command->description);
        $section->setParent($command->parent);
    }
}
