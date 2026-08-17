<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Article;
use App\Entity\AuditAction;
use App\Entity\User;
use App\Exception\ContentWasChangedElsewhere;
use App\Exception\DomainException;
use App\Form\ArticleType;
use App\Form\Command\ArticleCommand;
use App\Repository\ArticleRepository;
use App\Security\ArticleVoter;
use App\Service\Audit\AuditLog;
use App\Service\Content\ArticleEditor;
use App\Service\Content\PublicationService;
use App\Service\Pagination\Paginator;
use Doctrine\ORM\EntityManagerInterface;

use function sprintf;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Writing, editing, publishing and deleting articles.
 *
 * Every action asks a voter before it does anything, and asks it about the
 * article rather than about a role. A template that hides a button is a
 * courtesy; the check here is the permission. SC-004 requires the tests to
 * submit directly rather than look for an absent control, for exactly that
 * reason.
 *
 * Nothing here writes to an entity. The form fills a command object and
 * ArticleEditor applies it, which is what keeps sanitising in one place instead
 * of in every controller that will ever exist.
 */
#[Route('/admin/articles')]
final class ArticleController extends AbstractController
{
    public function __construct(
        private readonly ArticleRepository $articles,
        private readonly ArticleEditor $editor,
        private readonly PublicationService $publication,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLog $audit,
        private readonly Paginator $paginator,
    ) {
    }

    #[Route('', name: 'admin_article_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $number = Paginator::pageNumberFrom($request->query->get('page'));

        // Everything the viewer may see, decided in the query rather than by
        // asking the voter about each of however many rows came back. That is
        // not an optimisation: a listing filtered after it is fetched cannot be
        // cut into pages, because twenty rows fetched would show as six.
        //
        // The rule now exists twice — here as SQL and in ArticleVoter as the
        // permission — and ArticleVisibilityMatchesTheVoterTest runs both over
        // the same articles and asserts they agree, for every combination of
        // roles and ownership. Without that test this duplication would be a
        // liability rather than a trade.
        $fetched = $this->articles->findPageForViewer(
            $this->currentUser(),
            $this->paginator->fetchLimitFor(),
            $this->paginator->offsetFor($number),
        );

        // The viewer is not handed to the template: the one thing it decided
        // there — whether a title is a link — is `is_granted('ARTICLE_EDIT')`,
        // which asks the voter itself.
        return $this->render('admin/article/index.html.twig', [
            'page' => $this->paginator->paginate($fetched, $number),
        ]);
    }

    #[Route('/new', name: 'admin_article_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $command = new ArticleCommand();
        $form = $this->createForm(ArticleType::class, $command);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $article = $this->editor->create($command, $this->currentUser());

            $this->addFlash('success', sprintf('“%s” was created as a draft.', $article->getTitle()));

            return $this->redirectToRoute('admin_article_edit', ['id' => $article->getId()]);
        }

        return $this->render('admin/article/form.html.twig', [
            'form' => $form,
            'article' => null,
        ]);
    }

    #[Route('/{id}/edit', name: 'admin_article_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Article $article, Request $request): Response
    {
        $this->denyAccessUnlessGranted(ArticleVoter::EDIT, $article);

        $command = ArticleCommand::from($article);
        $form = $this->createForm(ArticleType::class, $command);
        $form->handleRequest($request);

        $status = Response::HTTP_OK;

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->editor->update($command, $article);

                $this->addFlash('success', 'Saved.');

                return $this->redirectToRoute('admin_article_edit', ['id' => $article->getId()]);
            } catch (ContentWasChangedElsewhere $conflict) {
                // Fall through to the render below rather than redirecting. A
                // redirect would send the editor back to a form filled from
                // storage, which is to say it would throw away the hour of
                // typing this refusal exists to protect. The form still holds
                // what was submitted.
                $this->addFlash('error', $conflict->getMessage());
                $status = Response::HTTP_CONFLICT;
            }
        }

        return $this->render('admin/article/form.html.twig', [
            'form' => $form,
            'article' => $article,
        ], new Response(status: $status));
    }

    /**
     * A publication state change.
     *
     * POST only and CSRF-checked: a state change reachable by following a link
     * is a state change another site can cause by embedding an image.
     */
    #[Route('/{id}/{transition}', name: 'admin_article_transition', requirements: [
        'id' => '\d+',
        'transition' => 'publish|unpublish|archive|restore',
    ], methods: ['POST'])]
    public function transition(Article $article, string $transition, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(ArticleVoter::PUBLISH, $article);
        $this->denyUnlessTokenIsValid('article-transition-'.$article->getId(), $request);

        try {
            $this->publication->apply($transition, $article);
            $this->addFlash('success', sprintf('“%s” is now %s.', $article->getTitle(), $article->getStatus()->label()));
        } catch (DomainException $domainException) {
            // The entity refused. Its message names the reason — a missing body,
            // a transition that is not allowed — and is written to be read.
            $this->addFlash('error', $domainException->getMessage());
        }

        return $this->redirectToRoute('admin_article_edit', ['id' => $article->getId()]);
    }

    #[Route('/{id}/delete', name: 'admin_article_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Article $article, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(ArticleVoter::DELETE, $article);
        $this->denyUnlessTokenIsValid('article-delete-'.$article->getId(), $request);

        $title = $article->getTitle();

        $this->entityManager->remove($article);
        $this->entityManager->flush();

        // The title is read above, before the row goes. Afterwards there is
        // nothing left to name it with, which is exactly the case the log
        // exists for — somebody asking a week later what used to be here.
        $this->audit->record(AuditAction::ContentDeleted, $title);

        $this->addFlash('success', sprintf('“%s” was deleted.', $title));

        return $this->redirectToRoute('admin_article_index');
    }

    private function currentUser(): User
    {
        $user = $this->getUser();

        // The firewall guarantees somebody is signed in, so this cannot fire in
        // practice. It is here because "cannot fire in practice" is exactly the
        // assumption that stops being true when a route moves.
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function denyUnlessTokenIsValid(string $id, Request $request): void
    {
        if (!$this->isCsrfTokenValid($id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid token.');
        }
    }
}
