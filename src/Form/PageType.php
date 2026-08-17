<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Media;
use App\Entity\Page;
use App\Form\Command\PageCommand;
use App\Repository\MediaRepository;
use App\Repository\PageRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<PageCommand>
 */
final class PageType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $editing = $options['editing'];

        $builder
            ->add('title', TextType::class, ['label' => 'Title'])
            ->add('excerpt', TextareaType::class, [
                'label' => 'Summary',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('content', TextareaType::class, [
                'label' => 'Body',
                'required' => false,
                'help' => 'Format with the toolbar, or switch to Markup to write it by hand. Either way it is cleaned before it is stored.',
                // See ArticleType for what this attribute does and why it is one.
                'attr' => ['rows' => 20, 'class' => 'font-mono', 'data-markup-editor' => true],
            ])
            ->add('parent', EntityType::class, [
                'label' => 'Parent page',
                'class' => Page::class,
                'choice_label' => 'title',
                'required' => false,
                'placeholder' => 'Top level',
                // A page cannot be its own parent, so it is not offered as one.
                // Deeper cycles are still possible to submit and are still
                // refused by Page::setParent() — this only keeps the obvious
                // case out of the list.
                'query_builder' => static function (PageRepository $repository) use ($editing): QueryBuilder {
                    $query = $repository->createQueryBuilder('page')
                        ->orderBy('page.menuOrder', 'ASC')
                        ->addOrderBy('page.title', 'ASC');

                    if (null !== $editing) {
                        $query->andWhere('page.id != :editing')->setParameter('editing', $editing);
                    }

                    return $query;
                },
            ])
            ->add('menuOrder', IntegerType::class, [
                'label' => 'Menu position',
                'help' => 'Lower numbers come first.',
            ])
            ->add('featuredImage', EntityType::class, [
                'label' => 'Lead image',
                'class' => Media::class,
                'choice_label' => 'originalName',
                'required' => false,
                'placeholder' => 'No lead image',
                'query_builder' => static fn (MediaRepository $repository): QueryBuilder => $repository
                    ->createQueryBuilder('media')
                    ->andWhere('media.altText IS NOT NULL')
                    ->orderBy('media.uploadedAt', 'DESC'),
            ])
            // See ArticleType for why this is an IntegerType drawn through the
            // hidden blocks rather than a HiddenType.
            ->add('version', IntegerType::class, [
                'block_prefix' => 'hidden',
                'label' => false,
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults([
                'data_class' => PageCommand::class,
                'editing' => null,
            ])
            ->setAllowedTypes('editing', ['null', 'int'])
        ;
    }
}
