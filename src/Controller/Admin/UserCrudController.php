<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\AuditAction;
use App\Entity\User;
use App\Security\AdministrationVoter;
use App\Service\Account\UserDeleter;
use App\Service\Audit\AuditLog;

use function array_filter;
use function array_values;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

use function is_array;
use function is_string;
use function sort;

use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Accounts. Administrators only.
 *
 * Three things here are deliberate and none of them is scaffolding.
 *
 * **There is no password field bound to the entity.** `User::$password` holds a
 * hash, and a form field mapped to it would display that hash on the edit screen
 * and write whatever was typed straight into storage. The field below is
 * unmapped: it exists only to carry a new password, and it is hashed here.
 *
 * **Blank means unchanged.** An edit form that demanded a password to save a
 * display name would train people to retype one, and a retyped password is a
 * weaker password.
 *
 * **Deletion goes through UserDeleter.** The scaffolded delete would hit
 * `ON DELETE RESTRICT` and produce a foreign-key error naming a constraint,
 * where the service produces a sentence naming what the account owns.
 *
 * @extends AbstractCrudController<User>
 */
final class UserCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly UserDeleter $deleter,
        private readonly AuditLog $audit,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Account')
            ->setEntityLabelInPlural('Accounts')
            ->setDefaultSort(['email' => 'ASC'])
            ->setHelp('edit', 'Leave the password blank to keep the current one.')
        ;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::BATCH_DELETE)
            ->setPermission(Action::INDEX, AdministrationVoter::MANAGE_ACCOUNTS)
            ->setPermission(Action::DETAIL, AdministrationVoter::MANAGE_ACCOUNTS)
            ->setPermission(Action::NEW, AdministrationVoter::MANAGE_ACCOUNTS)
            ->setPermission(Action::EDIT, AdministrationVoter::MANAGE_ACCOUNTS)
            ->setPermission(Action::DELETE, AdministrationVoter::MANAGE_ACCOUNTS)
        ;
    }

    /**
     * @return iterable<mixed>
     */
    public function configureFields(string $pageName): iterable
    {
        yield EmailField::new('email');
        yield TextField::new('displayName', 'Display name')
            ->setHelp('Shown as the author byline.');

        yield ChoiceField::new('roles')
            ->setChoices([
                'Author — writes and edits their own drafts' => User::ROLE_AUTHOR,
                'Editor — edits and publishes anything, manages taxonomy and files' => User::ROLE_EDITOR,
                'Administrator — everything, including accounts' => User::ROLE_ADMIN,
            ])
            ->allowMultipleChoices()
            ->renderExpanded()
            ->setHelp('An account with no role can sign in and do nothing.');

        // Unmapped, so the stored hash is never loaded into it and never shown.
        yield TextField::new('plainPassword', 'Password')
            ->setFormType(PasswordType::class)
            ->setFormTypeOptions(['mapped' => false, 'required' => Crud::PAGE_NEW === $pageName])
            ->onlyOnForms()
            ->setHelp('At least 12 characters. Leave blank when editing to keep the current password.');

        yield DateTimeField::new('createdAt', 'Created')->onlyOnDetail();
    }

    public function createEntity(string $entityFqcn): User
    {
        return new User('placeholder@example.invalid', '', new DateTimeImmutable());
    }

    public function persistEntity(EntityManagerInterface $entityManager, mixed $entityInstance): void
    {
        if ($entityInstance instanceof User) {
            $this->applySubmittedPassword($entityInstance);
        }

        parent::persistEntity($entityManager, $entityInstance);

        $this->audit->record(AuditAction::AccountCreated, $entityInstance->getEmail());
    }

    public function updateEntity(EntityManagerInterface $entityManager, mixed $entityInstance): void
    {
        if (!$entityInstance instanceof User) {
            parent::updateEntity($entityManager, $entityInstance);

            return;
        }

        // Read before the save, because afterwards there is nothing left to
        // compare against.
        $before = $this->rolesOf($entityInstance, $entityManager);

        $this->applySubmittedPassword($entityInstance);

        parent::updateEntity($entityManager, $entityInstance);

        // Only permissions, and only when they actually changed. An entry for
        // every edit of a display name would bury the one entry anybody ever
        // needs to find — the moment somebody was granted authority.
        if ($before !== $this->sortedRoles($entityInstance)) {
            $this->audit->record(AuditAction::AccountPermissionsChanged, $entityInstance->getEmail());
        }
    }

    /**
     * Both the single and the batch delete funnel through here, so this is where
     * the rules go.
     *
     * Two checks, in order and for different reasons. Authority first: an
     * administrator may not remove their own account, because one administrator
     * on a fresh installation doing so leaves a site nobody can administer.
     * Ownership second, answered by UserDeleter against the database, which
     * throws a sentence naming what is owned where the constraint would throw a
     * foreign-key name.
     */
    public function deleteEntity(EntityManagerInterface $entityManager, mixed $entityInstance): void
    {
        if (!$entityInstance instanceof User) {
            parent::deleteEntity($entityManager, $entityInstance);

            return;
        }

        $this->denyAccessUnlessGranted(AdministrationVoter::DELETE_ACCOUNT, $entityInstance);

        $this->deleter->delete($entityInstance);
    }

    /**
     * The roles this account held before the current edit.
     *
     * Taken from Doctrine's record of what was loaded rather than from the
     * entity, which the form has already written to by the time anything here
     * runs.
     *
     * @return list<string>
     */
    private function rolesOf(User $account, EntityManagerInterface $entityManager): array
    {
        $original = $entityManager->getUnitOfWork()->getOriginalEntityData($account);
        $roles = $original['roles'] ?? null;

        return is_array($roles) ? $this->sorted(array_values(array_filter($roles, is_string(...)))) : [];
    }

    /**
     * @return list<string>
     */
    private function sortedRoles(User $account): array
    {
        return $this->sorted($account->getRoles());
    }

    /**
     * Order is not meaning. Two lists holding the same permissions in a
     * different order are the same permissions, and recording that as a change
     * would be noise.
     *
     * @param list<string> $roles
     *
     * @return list<string>
     */
    private function sorted(array $roles): array
    {
        sort($roles);

        return $roles;
    }

    /**
     * Reads the unmapped field and hashes it, or leaves the stored credential
     * alone when nothing was typed.
     */
    private function applySubmittedPassword(User $user): void
    {
        $request = $this->getContext()?->getRequest();

        if (!$request instanceof Request) {
            return;
        }

        $submitted = $request->request->all();
        $form = $submitted['User'] ?? [];

        if (!is_array($form)) {
            return;
        }

        $plain = $form['plainPassword'] ?? null;

        // Anything that is not a non-empty string means "leave it alone" — which
        // covers the blank field, an absent one, and whatever an edited form
        // might send instead.
        if (!is_string($plain) || '' === $plain) {
            return;
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $plain));
    }
}
