<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Exception\DomainException;
use App\Exception\UserStillOwnsContent;
use App\Form\AccountType;
use App\Form\Command\AccountCommand;
use App\Repository\UserRepository;
use App\Security\AdministrationVoter;
use App\Service\Account\AccountEditor;
use App\Service\Account\UserDeleter;

use function sprintf;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Accounts. Administrators only.
 *
 * Four refusals here are not about permissions and are easy to lose:
 *
 * **An administrator may not delete their own account.** One administrator on a
 * fresh installation doing so leaves a site nobody can administer. `DELETE_ACCOUNT`
 * holds that rule, and it is asked here rather than assumed.
 *
 * **Nor may they remove their own administrator permission**, which reaches the
 * same locked-out site through a different door. A security review found that one
 * — the deletion rule had been written and the demotion rule had not.
 *
 * **Nor set their own password here**, where the current one is not asked for.
 * Their account page asks; this screen exists for resetting *other* people's.
 *
 * **An account that owns content cannot be deleted.** `UserDeleter` answers with
 * a sentence naming what is owned, where the database constraint alone would
 * answer with a foreign-key name.
 */
#[Route('/admin/manage/accounts')]
final class AccountController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $accounts,
        private readonly AccountEditor $editor,
        private readonly UserDeleter $deleter,
    ) {
    }

    #[Route('', name: 'admin_accounts_index', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted(AdministrationVoter::MANAGE_ACCOUNTS);

        return $this->render('admin/accounts/index.html.twig', [
            'accounts' => $this->accounts->findBy([], ['email' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'admin_accounts_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $this->denyAccessUnlessGranted(AdministrationVoter::MANAGE_ACCOUNTS);

        $command = new AccountCommand();
        $form = $this->createForm(AccountType::class, $command, ['creating' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $account = $this->editor->create($command);

            $this->addFlash('success', sprintf('The account for %s was created.', $account->getEmail()));

            return $this->redirectToRoute('admin_accounts_index');
        }

        return $this->render('admin/accounts/form.html.twig', ['form' => $form, 'account' => null]);
    }

    #[Route('/{id}/edit', name: 'admin_accounts_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(User $account, Request $request): Response
    {
        $this->denyAccessUnlessGranted(AdministrationVoter::MANAGE_ACCOUNTS);

        $command = AccountCommand::from($account);
        $form = $this->createForm(AccountType::class, $command);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->editor->update($command, $account, $this->currentUser());

                $this->addFlash('success', 'Saved.');

                return $this->redirectToRoute('admin_accounts_edit', ['id' => $account->getId()]);
            } catch (DomainException $refusal) {
                // Removing your own administrator permission, or setting your own
                // password without proving you know the current one. The message
                // names which and says where to go instead; the form comes back
                // holding what was submitted rather than an error page.
                $this->addFlash('error', $refusal->getMessage());
            }
        }

        return $this->render('admin/accounts/form.html.twig', [
            'form' => $form,
            'account' => $account,
            'canBeDeleted' => $this->deleter->canBeDeleted($account),
        ]);
    }

    #[Route('/{id}/delete', name: 'admin_accounts_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(User $account, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(AdministrationVoter::DELETE_ACCOUNT, $account);

        if (!$this->isCsrfTokenValid('account-delete-'.$account->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid token.');
        }

        $email = $account->getEmail();

        try {
            $this->deleter->delete($account);
        } catch (UserStillOwnsContent $userStillOwnsContent) {
            // A refusal a person can act on, not an error page. The exception
            // carries the counts, so the message says how much.
            $this->addFlash('error', $userStillOwnsContent->getMessage());

            return $this->redirectToRoute('admin_accounts_edit', ['id' => $account->getId()]);
        }

        $this->addFlash('success', sprintf('The account for %s was deleted.', $email));

        return $this->redirectToRoute('admin_accounts_index');
    }

    private function currentUser(): User
    {
        $user = $this->getUser();

        // The firewall and MANAGE_ACCOUNTS both guarantee this. The guard is here
        // because "guaranteed elsewhere" is exactly the assumption that stops
        // being true when a route moves.
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
