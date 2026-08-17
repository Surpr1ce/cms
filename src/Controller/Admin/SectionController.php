<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\AuditAction;
use App\Entity\Category;
use App\Exception\DomainException;
use App\Form\Command\SectionCommand;
use App\Form\SectionType;
use App\Repository\CategoryRepository;
use App\Security\AdministrationVoter;
use App\Service\Audit\AuditLog;
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
        private readonly AuditLog $audit,
    ) {
    }

    /**
     * The one administration listing feature 019 left unpaginated, on purpose.
     *
     * This screen renders a tree, and a tree cut across a page boundary is not a
     * tree: a subsection would appear at the top of page two with no parent above
     * it, indented under nothing. Paginating it properly means paginating roots
     * and fetching each one's children, which is a different query and a
     * different screen.
     *
     * Sections are structural and few by nature — they are the site's navigation,
     * so a site with hundreds of them has a problem pagination would only hide.
     * `findAllOrdered()` is therefore left as it is, and the fact that it is
     * unbounded is written here rather than discovered.
     */
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
            try {
                $this->editor->updateSection($command, $section);

                $this->addFlash('success', 'Saved.');

                return $this->redirectToRoute('admin_section_edit', ['id' => $section->getId()]);
            } catch (DomainException $refusal) {
                // A cycle in the section tree. The parent list offers this
                // section's own children, so putting a section inside its own
                // subsection is one wrong click away — and until a review found
                // it, that click was a 500 rather than the sentence the entity
                // refuses it with. The page screen has always handled this; this
                // one had not.
                $this->addFlash('error', $refusal->getMessage());
            }
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

        // The name is read above, before the row goes. Recorded because deleting
        // a section quietly uncategorises every article in it — a change nobody
        // can see afterwards from anywhere but here.
        $this->audit->record(AuditAction::SectionDeleted, $name);

        $this->addFlash('success', sprintf('“%s” was deleted. Its articles are now uncategorised.', $name));

        return $this->redirectToRoute('admin_section_index');
    }
}
