<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Media;
use App\Exception\ImageCannotBeResized;
use App\Repository\MediaRepository;
use App\Service\Media\DerivedImages;
use App\Service\Media\ImageSize;
use App\Service\Media\MediaStorage;

use function in_array;

use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
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

    /**
     * A year. Justified in serve(), where it is set.
     */
    private const int A_YEAR = 31_536_000;

    public function __construct(
        private readonly MediaRepository $media,
        private readonly MediaStorage $storage,
        private readonly DerivedImages $derived,
        private readonly LoggerInterface $logger,
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
    public function show(string $filename, Request $request): BinaryFileResponse
    {
        $media = $this->media->findOneByFilename($filename);

        if (!$media instanceof Media || !$this->storage->exists($media)) {
            // A record with no bytes and an address that means nothing produce
            // the same answer. There is nothing useful to tell them apart with.
            throw $this->createNotFoundException();
        }

        return $this->serve($this->storage->pathFor($media), $media, $request);
    }

    /**
     * A smaller copy, made on the first request for it and kept afterwards.
     *
     * The size comes out of an enum, so the route accepts the three that exist
     * and nothing else — "a size nobody defined" is refused by routing, before
     * any code of ours runs. Left open, a dimension in an address is an
     * invitation to ask one server to generate ten thousand images.
     */
    #[Route(
        '/media/{size}/{filename}',
        name: 'media_sized',
        // Spelled out because a route requirement is a compile-time string and
        // cannot call ImageSize::routePattern(). MediaSizeTest asserts the two
        // agree, so adding a case without touching this line fails a test rather
        // than producing a size nobody can reach.
        requirements: [
            'size' => 'thumbnail|medium|large',
            'filename' => '[0-9a-f]{32}\.[a-z0-9]{2,5}',
        ],
        methods: ['GET'],
    )]
    public function sized(ImageSize $size, string $filename, Request $request): BinaryFileResponse
    {
        $media = $this->media->findOneByFilename($filename);

        if (!$media instanceof Media || !$this->storage->exists($media) || !$this->derived->canDerive($media)) {
            throw $this->createNotFoundException();
        }

        try {
            $path = $this->derived->pathFor($media, $size);
        } catch (ImageCannotBeResized $imageCannotBeResized) {
            // A file that cannot be resized has no smaller copy, and saying so
            // is the truth. Serving the original instead would answer a request
            // for four hundred pixels with four thousand, which is the problem
            // this route exists to solve.
            $this->logger->warning('A derived image could not be produced.', [
                'media' => $media->getFilename(),
                'size' => $size->value,
                'reason' => $imageCannotBeResized->getMessage(),
            ]);

            throw $this->createNotFoundException();
        }

        return $this->serve($path, $media, $request);
    }

    /**
     * Everything both routes have in common, so that a derived image cannot
     * quietly acquire weaker headers than an original.
     */
    private function serve(string $path, Media $media, Request $request): BinaryFileResponse
    {
        $response = new BinaryFileResponse($path);

        // The recorded type — detected from the content when it was uploaded,
        // never the one the upload claimed. A derived image is written in the
        // same format, so the recorded type describes it too.
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

        // A year, and immutable, which is a strong claim — so it is worth saying
        // why it is safe. A stored name is sixteen random bytes generated once;
        // it is never reused, and the bytes at one can therefore never change.
        // Changed bytes are a new upload with a new address.
        $response->setPublic();
        $response->setMaxAge(self::A_YEAR);
        $response->setImmutable();

        // The validators. `autoLastModified()` and `autoEtag()` read the file
        // itself, so nothing has to be recorded for them to be right, and a
        // reader holding either is answered with 304 and no bytes at all.
        $response->setAutoLastModified();
        $response->setAutoEtag();
        $response->isNotModified($request);

        return $response;
    }
}
