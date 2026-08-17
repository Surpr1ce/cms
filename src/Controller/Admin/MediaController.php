<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Media;
use App\Entity\User;
use App\Exception\DomainException;
use App\Repository\MediaRepository;
use App\Security\AdministrationVoter;
use App\Service\Media\MediaDeleter;
use App\Service\Media\MediaUploader;
use App\Service\Media\UploadedFileValidator;
use Doctrine\ORM\EntityManagerInterface;

use function sprintf;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Uploading and managing files. Editorial throughout — an author has no claim on
 * the file library, which AdministrationVoter already decided.
 *
 * The form is built by hand rather than through a form type. There are two
 * fields, one of which is a file, and the validation that matters is done by
 * UploadedFileValidator on the bytes — a form type here would add a layer whose
 * only job is to pass values to the thing that actually decides.
 */
#[Route('/admin/media')]
final class MediaController extends AbstractController
{
    public function __construct(
        private readonly MediaRepository $media,
        private readonly MediaUploader $uploader,
        private readonly MediaDeleter $deleter,
        private readonly UploadedFileValidator $validator,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'admin_media_index', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted(AdministrationVoter::MANAGE_MEDIA);

        return $this->render('admin/media/index.html.twig', [
            'files' => $this->media->findRecent(100),
            'maximumBytes' => $this->validator->maximumBytes(),
        ]);
    }

    #[Route('/upload', name: 'admin_media_upload', methods: ['POST'])]
    public function upload(Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(AdministrationVoter::MANAGE_MEDIA);
        $this->denyUnlessTokenIsValid('media-upload', $request);

        $file = $request->files->get('file');
        $altText = trim((string) $request->request->get('altText'));

        if (!$file instanceof UploadedFile) {
            $this->addFlash('error', 'No file was chosen.');

            return $this->redirectToRoute('admin_media_index');
        }

        // FR-002. Checked before anything is written, so a refusal here leaves
        // nothing behind either.
        if ('' === $altText) {
            $this->addFlash('error', 'A description is required, so that people who cannot see the image still can.');

            return $this->redirectToRoute('admin_media_index');
        }

        try {
            $media = $this->uploader->upload($file, $altText, $this->currentUser());
            $this->addFlash('success', sprintf('“%s” was uploaded.', $media->getOriginalName()));
        } catch (DomainException $domainException) {
            // The refusal names the size, or what types are accepted. Nothing
            // was written — the validator runs before the file is moved.
            $this->addFlash('error', $domainException->getMessage());
        }

        return $this->redirectToRoute('admin_media_index');
    }

    #[Route('/{id}/describe', name: 'admin_media_describe', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function describe(Media $media, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(AdministrationVoter::MANAGE_MEDIA);
        $this->denyUnlessTokenIsValid('media-describe-'.$media->getId(), $request);

        $altText = trim((string) $request->request->get('altText'));

        if ('' === $altText) {
            $this->addFlash('error', 'A description cannot be empty.');

            return $this->redirectToRoute('admin_media_index');
        }

        $media->setAltText($altText);
        $this->entityManager->flush();

        $this->addFlash('success', 'Description saved.');

        return $this->redirectToRoute('admin_media_index');
    }

    #[Route('/{id}/delete', name: 'admin_media_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Media $media, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(AdministrationVoter::MANAGE_MEDIA);
        $this->denyUnlessTokenIsValid('media-delete-'.$media->getId(), $request);

        $name = $media->getOriginalName();

        // Detaches it from any content that used it, removes the record, then
        // removes the bytes. Content survives without a lead image.
        $this->deleter->delete($media);

        $this->addFlash('success', sprintf('“%s” was deleted.', $name));

        return $this->redirectToRoute('admin_media_index');
    }

    private function currentUser(): User
    {
        $user = $this->getUser();

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
