<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use App\Form\Command\AccountCommand;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * An account: who it is, what it may do, and — only when somebody types one — a
 * new password.
 *
 * The permissions are checkboxes rather than a list to pick from, because each
 * one is a separate grant and a person choosing them should see all three and
 * what each means. An account with none can sign in and do nothing, which is a
 * legitimate state and therefore not prevented.
 *
 * @extends AbstractType<AccountCommand>
 */
final class AccountType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $creating = true === $options['creating'];

        $builder
            ->add('email', EmailType::class, [
                'label' => 'Email address',
                'help' => 'Used to sign in, and where a password link would be sent.',
            ])
            ->add('displayName', TextType::class, [
                'label' => 'Display name',
                'help' => 'Shown as the author byline.',
            ])
            ->add('roles', ChoiceType::class, [
                'label' => 'Permissions',
                'choices' => [
                    'Author — writes and edits their own drafts' => User::ROLE_AUTHOR,
                    'Editor — edits and publishes anything, manages sections, labels and files' => User::ROLE_EDITOR,
                    'Administrator — everything, including accounts and the log' => User::ROLE_ADMIN,
                ],
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'help' => 'An account with none of these can sign in and do nothing.',
            ])
            // Never given a value, on either screen: a stored hash is not shown
            // and a typed password is not echoed back.
            ->add('password', PasswordType::class, [
                'label' => $creating ? 'Password' : 'New password',
                'required' => $creating,
                'help' => $creating
                    ? 'At least 12 characters.'
                    : 'At least 12 characters. Leave blank to keep the current password.',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults([
                'data_class' => AccountCommand::class,
                'creating' => false,
            ])
            ->setAllowedTypes('creating', 'bool')
        ;
    }
}
