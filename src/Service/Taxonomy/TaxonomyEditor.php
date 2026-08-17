<?php

declare(strict_types=1);

namespace App\Service\Taxonomy;

use App\Entity\AuditAction;
use App\Entity\Category;
use App\Entity\Tag;
use App\Form\Command\LabelCommand;
use App\Form\Command\SectionCommand;
use App\Repository\CategoryRepository;
use App\Repository\TagRepository;
use App\Service\Audit\AuditLog;
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
        private AuditLog $audit,
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

    /**
     * Deleting a label.
     *
     * There is no rule to enforce — the join table between articles and labels is
     * `ON DELETE CASCADE`, so the rows that applied it go with it and nothing else
     * is touched. A section is different, which is why CategoryDeleter exists and
     * this is a method rather than a class of its own.
     *
     * It is here rather than in the controller only so that the deletion and the
     * log entry cannot come apart: an audit review found that removing a label
     * recorded nothing, while removing an article, a page, a file or an account
     * all did.
     */
    public function deleteLabel(Tag $label): void
    {
        // Read before the row goes. Afterwards there is nothing left to name it
        // with, which is the case the log exists for.
        $name = $label->getName();

        $this->entityManager->remove($label);
        $this->entityManager->flush();

        $this->audit->record(AuditAction::LabelDeleted, $name);
    }

    private function applyToSection(SectionCommand $command, Category $section): void
    {
        $section->setName($command->name);
        $section->setDescription('' === $command->description ? null : $command->description);
        $section->setParent($command->parent);
    }
}
