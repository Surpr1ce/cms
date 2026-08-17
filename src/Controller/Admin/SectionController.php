<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Category;
use App\Form\Command\SectionCommand;
use App\Form\SectionType;
use App\Repository\CategoryRepository;
use App\Security\AdministrationVoter;
use App\Service\Taxonomy\CategoryDeleter;
use App\Service\Taxonomy\TaxonomyEditor;

use function sprintf;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Sections.
 *
 * Written by hand, in the same shape as the article and page screens, because
 * until feature 016 these three lived in EasyAdmin and looked like a different
 * product: a different layout, a different typeface, different controls, and no
 * way to reach the rest of the administration from them. Consistency across the
 * screens somebody uses every day is worth more than the code a generic CRUD
 * bundle saves.
 *
 * Deletion goes through CategoryDeleter, which uncategorises the articles and
 * moves subsections up to their grandparent. The constraint alone would keep the
 * articles and make the subsections top-level — coherent, and not what the
 * specification asks for.
 */
#[Route('/admin/manage/sections')]
final class SectionController extends AbstractController
{
    public function __construct(
        private readonly CategoryRepository $sections,
        private readonly TaxonomyEditor $editor,
        private readonly CategoryDeleter $deleter,
    ) {
    }

    #[Route('', name: 'admin_section_index', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted(AdministrationVoter::MANAGE_TAXONOMY);

        return $this->render('admin/section/index.html.twig', [
            'sections' => $this->sections->findAllOrdered(),
        ]);
    }

    #[Route('/new', name: 'admin_section_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $this->denyAccessUnlessGranted(AdministrationVoter::MANAGE_TAXONOMY);

        $command = new SectionCommand();
        $form = $this->createForm(SectionType::class, $command);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $section = $this->editor->createSection($command);

            $this->addFlash('success', sprintf('“%s” was created.', $section->getName()));

            return $this->redirectToRoute('admin_section_index');
        }

        return $this->render('admin/section/form.html.twig', ['form' => $form, 'section' => null]);
    }

    #[Route('/{id}/edit', name: 'admin_section_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Category $section, Request $request): Response
    {
        $this->denyAccessUnlessGranted(AdministrationVoter::MANAGE_TAXONOMY);

        $command = SectionCommand::from($section);
        $form = $this->createForm(SectionType::class, $command, ['editing' => $section->getId()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->editor->updateSection($command, $section);

            $this->addFlash('success', 'Saved.');

            return $this->redirectToRoute('admin_section_edit', ['id' => $section->getId()]);
        }

        return $this->render('admin/section/form.html.twig', ['form' => $form, 'section' => $section]);
    }

    #[Route('/{id}/delete', name: 'admin_section_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Category $section, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(AdministrationVoter::MANAGE_TAXONOMY);

        if (!$this->isCsrfTokenValid('section-delete-'.$section->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid token.');
        }

        $name = $section->getName();

        $this->deleter->delete($section);

        $this->addFlash('success', sprintf('“%s” was deleted. Its articles are now uncategorised.', $name));

        return $this->redirectToRoute('admin_section_index');
    }
}
