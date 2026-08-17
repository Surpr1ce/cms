<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Tag;
use App\Repository\TagRepository;
use App\Security\AdministrationVoter;
use App\Service\Slug\UniqueSlugGenerator;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Labels.
 *
 * The scaffolded delete is kept here, and that is a decision rather than an
 * oversight: the join table carries `ON DELETE CASCADE`, so removing a label
 * removes the *associations* and never an article. The database already does
 * exactly what the domain asks, which is why feature 001 never wrote a
 * TagDeleter — and adding one now to look symmetrical with sections would be a
 * service with nothing to do.
 *
 * @extends AbstractCrudController<Tag>
 */
final class TagCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly UniqueSlugGenerator $slugs,
        private readonly TagRepository $tags,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Tag::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Label')
            ->setEntityLabelInPlural('Labels')
            ->setDefaultSort(['name' => 'ASC'])
            ->setHelp('index', 'A label answers "what is this about". An article may carry any number.')
        ;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
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
        yield TextField::new('slug', 'Address')->onlyOnDetail();
    }

    public function createEntity(string $entityFqcn): Tag
    {
        return new Tag('', 'pending-'.bin2hex(random_bytes(6)));
    }

    public function persistEntity(EntityManagerInterface $entityManager, mixed $entityInstance): void
    {
        if ($entityInstance instanceof Tag) {
            $entityInstance->assignSlug($this->slugs->generate($entityInstance->getName(), $this->tags));
        }

        parent::persistEntity($entityManager, $entityInstance);
    }
}
