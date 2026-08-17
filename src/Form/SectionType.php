<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Category;
use App\Form\Command\SectionCommand;
use App\Repository\CategoryRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A section: a name, a description, and where it sits.
 *
 * No address field. It is generated from the name on creation and then fixed,
 * because a form offering to edit one invites breaking every link to a section
 * that already exists.
 *
 * @extends AbstractType<SectionCommand>
 */
final class SectionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $editing = $options['editing'];

        $builder
            ->add('name', TextType::class, ['label' => 'Name'])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => ['rows' => 3],
                'help' => 'Shown at the top of the section listing.',
            ])
            ->add('parent', EntityType::class, [
                'label' => 'Inside',
                'class' => Category::class,
                // Named explicitly: Category has no __toString(), by design —
                // an entity that stringifies itself invites being printed
                // somewhere nobody chose.
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => 'Top level',
                // A section cannot be inside itself, so it is not offered.
                'query_builder' => static function (CategoryRepository $repository) use ($editing): QueryBuilder {
                    $query = $repository->createQueryBuilder('section')->orderBy('section.name', 'ASC');

                    if (null !== $editing) {
                        $query->andWhere('section.id != :editing')->setParameter('editing', $editing);
                    }

                    return $query;
                },
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults([
                'data_class' => SectionCommand::class,
                'editing' => null,
            ])
            ->setAllowedTypes('editing', ['null', 'int'])
        ;
    }
}
