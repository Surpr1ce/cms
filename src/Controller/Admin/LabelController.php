<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Tag;
use App\Form\Command\LabelCommand;
use App\Form\LabelType;
use App\Repository\TagRepository;
use App\Security\AdministrationVoter;
use App\Service\Taxonomy\TaxonomyEditor;
use Doctrine\ORM\EntityManagerInterface;

use function sprintf;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Labels.
 *
 * The one screen here that deletes through the entity manager rather than a
 * service, and it is worth saying why: the join table between articles and
 * labels is `ON DELETE CASCADE`, so removing a label removes the rows that
 * applied it and touches nothing else. There is no rule for a service to hold —
 * a section has one, because its articles and its subsections both need
 * somewhere to go.
 */
#[Route('/admin/manage/labels')]
final class LabelController extends AbstractController
{
    public function __construct(
        private readonly TagRepository $labels,
        private readonly TaxonomyEditor $editor,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'admin_label_index', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted(AdministrationVoter::MANAGE_TAXONOMY);

        return $this->render('admin/label/index.html.twig', [
            'labels' => $this->labels->findAllOrdered(),
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

        $this->entityManager->remove($label);
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('“%s” was deleted. The articles that carried it are untouched.', $name));

        return $this->redirectToRoute('admin_label_index');
    }
}
