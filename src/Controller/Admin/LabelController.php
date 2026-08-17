<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Tag;
use App\Form\Command\LabelCommand;
use App\Form\LabelType;
use App\Repository\TagRepository;
use App\Security\AdministrationVoter;
use App\Service\Pagination\Paginator;
use App\Service\Taxonomy\TaxonomyEditor;

use function sprintf;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Labels.
 *
 * Deletion goes through TaxonomyEditor, which has no rule to enforce — the join
 * table between articles and labels is `ON DELETE CASCADE`, so removing a label
 * removes the rows that applied it and touches nothing else, where a section
 * needs somewhere to put its articles and its subsections. What the service does
 * hold is the audit entry, so that the deletion and the record of it cannot come
 * apart. This screen deleted through the entity manager and recorded nothing
 * until an audit noticed.
 */
#[Route('/admin/manage/labels')]
final class LabelController extends AbstractController
{
    public function __construct(
        private readonly TagRepository $labels,
        private readonly TaxonomyEditor $editor,
        private readonly Paginator $paginator,
    ) {
    }

    #[Route('', name: 'admin_label_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessGranted(AdministrationVoter::MANAGE_TAXONOMY);

        // Labels are a flat list, so unlike the sections screen there is nothing
        // structural to keep whole across a page boundary.
        $number = Paginator::pageNumberFrom($request->query->get('page'));

        $fetched = $this->labels->findPage(
            $this->paginator->fetchLimitFor(),
            $this->paginator->offsetFor($number),
        );

        return $this->render('admin/label/index.html.twig', [
            'page' => $this->paginator->paginate($fetched, $number),
        ]);
    }

    #[Route('/new', name: 'admin_label_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $this->denyAccessUnlessGranted(AdministrationVoter::MANAGE_TAXONOMY);

        $command = new LabelCommand();
        $form = $this->createForm(LabelType::class, $command);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $label = $this->editor->createLabel($command);

            $this->addFlash('success', sprintf('“%s” was created.', $label->getName()));

            return $this->redirectToRoute('admin_label_index');
        }

        return $this->render('admin/label/form.html.twig', ['form' => $form, 'label' => null]);
    }

    #[Route('/{id}/edit', name: 'admin_label_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Tag $label, Request $request): Response
    {
        $this->denyAccessUnlessGranted(AdministrationVoter::MANAGE_TAXONOMY);

        $command = LabelCommand::from($label);
        $form = $this->createForm(LabelType::class, $command);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->editor->updateLabel($command, $label);

            $this->addFlash('success', 'Saved.');

            return $this->redirectToRoute('admin_label_edit', ['id' => $label->getId()]);
        }

        return $this->render('admin/label/form.html.twig', ['form' => $form, 'label' => $label]);
    }

    #[Route('/{id}/delete', name: 'admin_label_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Tag $label, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(AdministrationVoter::MANAGE_TAXONOMY);

        if (!$this->isCsrfTokenValid('label-delete-'.$label->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid token.');
        }

        $name = $label->getName();

        $this->editor->deleteLabel($label);

        $this->addFlash('success', sprintf('“%s” was deleted. The articles that carried it are untouched.', $name));

        return $this->redirectToRoute('admin_label_index');
    }
}
