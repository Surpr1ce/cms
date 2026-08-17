<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Media;
use App\Repository\MediaRepository;
use App\Service\Media\MediaStorage;

use function in_array;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves an uploaded file.
 *
 * The bytes live outside the web root, so a reader cannot reach them except
 * through here. That costs a PHP process per image where a web server would
 * have been faster — and it buys the guarantee that nothing in the uploads
 * directory is reachable as anything but a response this application composed.
 * See docs/adr/0011-serve-uploads-through-the-application.md.
 *
 * The authorisation this applies is "anybody may read". A file used in a
 * published article has to be readable by the public, and the CMS has no notion
 * of a private file. The specification says so rather than leaving a reader to
 * infer it from the absence of a check.
 */
final class MediaController extends AbstractController
{
    /**
     * Types a browser may render in place. Everything else is sent as an
     * attachment: a PDF opened inline is a document a plugin renders, and this
     * application has no reason to invite that.
     *
     * @var list<string>
     */
    private const array INLINE_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/avif',
    ];

    public function __construct(
        private readonly MediaRepository $media,
        private readonly MediaStorage $storage,
    ) {
    }

    /**
     * The stored name is the address, and it is hexadecimal plus a known
     * extension — so the route requirement admits only that shape, and a
     * traversal attempt never reaches this method.
     */
    #[Route(
        '/media/{filename}',
        name: 'media_show',
        requirements: ['filename' => '[0-9a-f]{32}\.[a-z0-9]{2,5}'],
        methods: ['GET'],
    )]
    public function show(string $filename): BinaryFileResponse
    {
        $media = $this->media->findOneByFilename($filename);

        if (!$media instanceof Media || !$this->storage->exists($media)) {
            // A record with no bytes and an address that means nothing produce
            // the same answer. There is nothing useful to tell them apart with.
            throw $this->createNotFoundException();
        }

        $response = new BinaryFileResponse($this->storage->pathFor($media));

        // The recorded type — detected from the content when it was uploaded,
        // never the one the upload claimed.
        $response->headers->set('Content-Type', $media->getMimeType());

        // Without this, a browser may decide for itself what the bytes are and
        // render them as something else entirely. It is the header that makes
        // "we recorded the type" mean anything.
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        $response->setContentDisposition(
            in_array($media->getMimeType(), self::INLINE_TYPES, true)
                ? ResponseHeaderBag::DISPOSITION_INLINE
                : ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            // The display name, sanitised by Symfony for the header. The stored
            // name is what was used to find the file; this is only what a
            // download is called.
            $media->getOriginalName(),
        );

        return $response;
    }
}
