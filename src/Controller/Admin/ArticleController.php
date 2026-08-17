<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Article;
use App\Entity\User;
use App\Exception\ContentWasChangedElsewhere;
use App\Exception\DomainException;
use App\Form\ArticleType;
use App\Form\Command\ArticleCommand;
use App\Repository\ArticleRepository;
use App\Security\ArticleVoter;
use App\Service\Content\ArticleEditor;
use App\Service\Content\PublicationService;
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
    ) {
    }

    #[Route('', name: 'admin_article_index', methods: ['GET'])]
    public function index(): Response
    {
        $viewer = $this->currentUser();

        // Everything the viewer may see, filtered by the same voter that guards
        // the edit screen — so a listing can never offer a link that leads to a
        // refusal.
        $visible = array_values(array_filter(
            $this->articles->findBy([], ['createdAt' => 'DESC']),
            fn (Article $article): bool => $this->isGranted(ArticleVoter::VIEW, $article),
        ));

        return $this->render('admin/article/index.html.twig', [
            'articles' => $visible,
            'viewer' => $viewer,
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
