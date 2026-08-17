<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Category;
use App\Repository\CategoryRepository;
use App\Security\AdministrationVoter;
use App\Service\Slug\UniqueSlugGenerator;
use App\Service\Taxonomy\CategoryDeleter;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Sections.
 *
 * Two things here are not scaffolding, and both are the point of FR-016.
 *
 * The address is generated on creation and then left alone. There is no slug
 * field, because a form offering to edit one invites breaking every link to a
 * section that already exists — the same reasoning that freezes an article's
 * address at publication.
 *
 * Deletion goes through CategoryDeleter. The scaffolded delete would call
 * EntityManager::remove() and leave the constraint to decide: the articles would
 * survive because of ON DELETE SET NULL, but the subsections would become
 * top-level instead of moving up to their grandparent. Not a leak, and a
 * behaviour changing because of a tool rather than because of a decision.
 *
 * @extends AbstractCrudController<Category>
 */
final class CategoryCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly UniqueSlugGenerator $slugs,
        private readonly CategoryRepository $categories,
        private readonly CategoryDeleter $deleter,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Category::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Section')
            ->setEntityLabelInPlural('Sections')
            ->setDefaultSort(['name' => 'ASC'])
            ->setHelp('index', 'A section answers "what part of the site is this in". An article belongs to at most one.')
        ;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            // Bulk operations are out of scope by the specification, and a batch
            // delete is also the one route most likely to be added later without
            // anybody remembering it bypasses a confirmation.
            ->disable(Action::BATCH_DELETE)
            ->setPermission(Action::INDEX, AdministrationVoter::MANAGE_TAXONOMY)
            ->setPermission(Action::DETAIL, AdministrationVoter::MANAGE_TAXONOMY)
            ->setPermission(Action::NEW, AdministrationVoter::MANAGE_TAXONOMY)
            ->setPermission(Action::EDIT, AdministrationVoter::MANAGE_TAXONOMY)
            ->setPermission(Action::DELETE, AdministrationVoter::MANAGE_TAXONOMY)
        ;
    }

    /**
     * @return iterable<mixed>
     */
    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name');
        yield TextareaField::new('description')->hideOnIndex()->setRequired(false);
        // The label is named explicitly. Without it EasyAdmin has no way to
        // render a Category as a choice — `Category` has no __toString(), by
        // design, because an entity that stringifies itself invites being
        // printed somewhere nobody chose. The form asks for what it needs
        // instead, and the section list stayed empty-formed until a test tried
        // to open it with a section already in the database.
        yield AssociationField::new('parent')
            ->setRequired(false)
            ->setFormTypeOption('choice_label', 'name')
            ->setHelp('Leave empty for a top-level section.');

        // Shown, never edited. A reader may have linked to it.
        yield TextField::new('slug', 'Address')->onlyOnDetail();
    }

    /**
     * Generates the address once, at creation.
     *
     * Category::__construct() requires one, so EasyAdmin cannot instantiate the
     * entity for a form without it — which is why this exists rather than a
     * lifecycle hook. The name is not known until the form is submitted, so a
     * placeholder is used and replaced here.
     */
    public function createEntity(string $entityFqcn): Category
    {
        return new Category('', 'pending-'.bin2hex(random_bytes(6)));
    }

    public function persistEntity(EntityManagerInterface $entityManager, mixed $entityInstance): void
    {
        if ($entityInstance instanceof Category) {
            $entityInstance->assignSlug($this->slugs->generate($entityInstance->getName(), $this->categories));
        }

        parent::persistEntity($entityManager, $entityInstance);
    }

    /**
     * Deletion, through the service rather than the scaffold.
     *
     * `deleteEntity()` rather than `delete()`, deliberately: EasyAdmin funnels
     * both the single delete and the batch delete through this one method, so
     * overriding the outer action would have left the batch route calling
     * `EntityManager::remove()` directly. The batch action is disabled below as
     * well, but the override is what makes the rule hold rather than the
     * absence of a button.
     */
    public function deleteEntity(EntityManagerInterface $entityManager, mixed $entityInstance): void
    {
        if (!$entityInstance instanceof Category) {
            parent::deleteEntity($entityManager, $entityInstance);

            return;
        }

        // Uncategorises the articles, moves the subsections up to their
        // grandparent, then removes the row. The constraint alone would keep the
        // articles but make the subsections top-level, which is coherent and is
        // not what the specification asks for.
        $this->deleter->delete($entityInstance);
    }
}
