<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Page;
use App\Exception\ContentWasChangedElsewhere;
use App\Exception\DomainException;
use App\Exception\PageStillHasChildren;
use App\Form\Command\PageCommand;
use App\Form\PageType;
use App\Repository\PageRepository;
use App\Security\PageVoter;
use App\Service\Content\PageDeleter;
use App\Service\Content\PageEditor;
use App\Service\Content\PublicationService;
use DateTimeImmutable;

use function sprintf;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Standalone pages.
 *
 * Editorial throughout: PageVoter grants nothing to an author, because a page
 * has no owner. An author reaching any address here is refused, and the
 * navigation does not offer them the section at all.
 */
#[Route('/admin/pages')]
final class PageController extends AbstractController
{
    public function __construct(
        private readonly PageRepository $pages,
        private readonly PageEditor $editor,
        private readonly PageDeleter $deleter,
        private readonly PublicationService $publication,
    ) {
    }

    #[Route('', name: 'admin_page_index', methods: ['GET'])]
    public function index(): Response
    {
        // One permission check rather than a filter per row: every page is
        // governed identically, so if the viewer may see one they may see all.
        $this->denyAccessUnlessGranted(PageVoter::EDIT, $this->probe());

        return $this->render('admin/page/index.html.twig', [
            'pages' => $this->pages->findBy([], ['menuOrder' => 'ASC', 'title' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'admin_page_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $this->denyAccessUnlessGranted(PageVoter::EDIT, $this->probe());

        $command = new PageCommand();
        $form = $this->createForm(PageType::class, $command);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $page = $this->editor->create($command);

            $this->addFlash('success', sprintf('“%s” was created as a draft.', $page->getTitle()));

            return $this->redirectToRoute('admin_page_edit', ['id' => $page->getId()]);
        }

        return $this->render('admin/page/form.html.twig', ['form' => $form, 'page' => null]);
    }

    #[Route('/{id}/edit', name: 'admin_page_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Page $page, Request $request): Response
    {
        $this->denyAccessUnlessGranted(PageVoter::EDIT, $page);

        $command = PageCommand::from($page);
        $form = $this->createForm(PageType::class, $command, ['editing' => $page->getId()]);
        $form->handleRequest($request);

        $status = Response::HTTP_OK;

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->editor->update($command, $page);
                $this->addFlash('success', 'Saved.');

                return $this->redirectToRoute('admin_page_edit', ['id' => $page->getId()]);
            } catch (ContentWasChangedElsewhere $conflict) {
                // Caught ahead of the general case only to answer with a status
                // that says what happened. The handling is otherwise identical:
                // the form comes back holding what was submitted, because a
                // redirect would discard the typing this refusal protects.
                $this->addFlash('error', $conflict->getMessage());
                $status = Response::HTTP_CONFLICT;
            } catch (DomainException $refusal) {
                // A cycle in the page tree, most likely. The entity refuses it
                // and says why; the form comes back rather than an error page.
                $this->addFlash('error', $refusal->getMessage());
            }
        }

        return $this->render(
            'admin/page/form.html.twig',
            ['form' => $form, 'page' => $page],
            new Response(status: $status),
        );
    }

    #[Route('/{id}/{transition}', name: 'admin_page_transition', requirements: [
        'id' => '\d+',
        'transition' => 'publish|unpublish|archive|restore',
    ], methods: ['POST'])]
    public function transition(Page $page, string $transition, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(PageVoter::PUBLISH, $page);
        $this->denyUnlessTokenIsValid('page-transition-'.$page->getId(), $request);

        try {
            $this->publication->apply($transition, $page);
            $this->addFlash('success', sprintf('“%s” is now %s.', $page->getTitle(), $page->getStatus()->label()));
        } catch (DomainException $domainException) {
            $this->addFlash('error', $domainException->getMessage());
        }

        return $this->redirectToRoute('admin_page_edit', ['id' => $page->getId()]);
    }

    #[Route('/{id}/delete', name: 'admin_page_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Page $page, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(PageVoter::DELETE, $page);
        $this->denyUnlessTokenIsValid('page-delete-'.$page->getId(), $request);

        $title = $page->getTitle();

        try {
            $this->deleter->delete($page);
            $this->addFlash('success', sprintf('“%s” was deleted.', $title));

            return $this->redirectToRoute('admin_page_index');
        } catch (PageStillHasChildren $pageStillHasChildren) {
            // FR-017: this is a refusal a person can act on, not an error page.
            // The exception carries the count, so the message says how many.
            $this->addFlash('error', $pageStillHasChildren->getMessage());

            return $this->redirectToRoute('admin_page_edit', ['id' => $page->getId()]);
        }
    }

    /**
     * A page to ask the voter about when there is no particular one.
     *
     * PageVoter is governed by role alone, so any page gives the same answer —
     * but it will not answer at all without a subject, because a voter that
     * abstains on an unrecognised subject is safer than one that guesses. An
     * unsaved page is the cheapest honest subject to hand it.
     */
    private function probe(): Page
    {
        return new Page('probe', 'probe', new DateTimeImmutable());
    }

    private function denyUnlessTokenIsValid(string $id, Request $request): void
    {
        if (!$this->isCsrfTokenValid($id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid token.');
        }
    }
}
