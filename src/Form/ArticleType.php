<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Category;
use App\Entity\Media;
use App\Entity\Tag;
use App\Form\Command\ArticleCommand;
use App\Repository\CategoryRepository;
use App\Repository\MediaRepository;
use App\Repository\TagRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<ArticleCommand>
 */
final class ArticleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Title',
            ])
            ->add('excerpt', TextareaType::class, [
                'label' => 'Summary',
                'required' => false,
                'help' => 'Shown in listings and used as the page description. Optional.',
                'attr' => ['rows' => 3],
            ])
            ->add('content', TextareaType::class, [
                'label' => 'Body',
                'required' => false,
                'help' => 'Format with the toolbar, or switch to Markup to write it by hand. Either way it is cleaned before it is stored: scripts and event handlers are removed.',
                // `data-markup-editor` is what assets/editor.js looks for. It is
                // an attribute rather than a different form type because the
                // field genuinely is a text area — the editor writes into this
                // one, and a browser that runs no script finds it unchanged.
                'attr' => ['rows' => 20, 'class' => 'font-mono', 'data-markup-editor' => true],
            ])
            ->add('category', EntityType::class, [
                'label' => 'Section',
                'class' => Category::class,
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => 'No section',
                'query_builder' => static fn (CategoryRepository $repository): QueryBuilder => $repository
                    ->createQueryBuilder('category')
                    ->orderBy('category.name', 'ASC'),
            ])
            ->add('tags', EntityType::class, [
                'label' => 'Labels',
                'class' => Tag::class,
                'choice_label' => 'name',
                'multiple' => true,
                'required' => false,
                'expanded' => true,
                'query_builder' => static fn (TagRepository $repository): QueryBuilder => $repository
                    ->createQueryBuilder('tag')
                    ->orderBy('tag.name', 'ASC'),
            ])
            ->add('featuredImage', EntityType::class, [
                'label' => 'Lead image',
                'class' => Media::class,
                'choice_label' => 'originalName',
                'required' => false,
                'placeholder' => 'No lead image',
                'help' => 'Only files that have alternative text can be used.',
                // Files with no alternative text are not offered, because
                // Article::setFeaturedImage() refuses them. Offering a choice
                // that will be rejected is a worse experience than not offering
                // it — and the entity still refuses it if the form is edited.
                'query_builder' => static fn (MediaRepository $repository): QueryBuilder => $repository
                    ->createQueryBuilder('media')
                    ->andWhere('media.altText IS NOT NULL')
                    ->orderBy('media.uploadedAt', 'DESC'),
            ])
            // The one field on this form nobody types into. It carries the
            // version the screen was opened on, so that saving can ask whether
            // it is still current rather than overwriting whatever somebody else
            // did in the meantime.
            //
            // An IntegerType rendered through the hidden blocks, rather than a
            // HiddenType: the command's property is an int, and a HiddenType
            // would submit the string "7" into it. `block_prefix` changes only
            // how it is drawn, so `form_end()` emits a bare input with no label
            // and no row — and a template that forgets it still gets it.
            ->add('version', IntegerType::class, [
                'block_prefix' => 'hidden',
                'label' => false,
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ArticleCommand::class,
        ]);
    }
}
