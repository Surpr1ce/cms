<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Media;
use App\Repository\MediaRepository;
use App\Service\Media\DerivedImages;
use App\Service\Media\MediaStorage;
use App\Story\AppStory;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

use function in_array;
use function is_dir;
use function preg_match;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

/**
 * Loads the development dataset.
 *
 * The content lives in AppStory rather than here, so that fixtures and tests
 * build entities the same way — through the factories — and there is one
 * definition of what a valid article looks like rather than two that drift.
 */
class AppFixtures extends Fixture
{
    public function __construct(
        private readonly MediaRepository $media,
        private readonly MediaStorage $storage,
        private readonly PlaceholderImage $placeholders,
        private readonly DerivedImages $derived,
        private readonly Filesystem $filesystem,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        AppStory::load();

        $manager->flush();

        $this->writePlaceholderBytes();
        $this->removeWhatIsNoLongerCatalogued();
    }

    /**
     * Clears out the files the previous dataset left behind.
     *
     * `doctrine:fixtures:load` purges the database and does not touch the disk,
     * so every reload used to leave a full set of orphaned uploads and their
     * derived copies — files with generated names that nothing points at and
     * nobody can identify. After a few reloads the directory is mostly rubbish
     * and the only way to tell what is live is to compare it against the
     * catalogue by hand.
     *
     * Safe here in a way it would not be anywhere else: this runs only from a
     * command that has *already* emptied the database, so anything uncatalogued
     * is by definition the previous dataset. A name that does not look generated
     * is left alone regardless — the same rule the pruning command follows, for
     * the same reason.
     */
    private function removeWhatIsNoLongerCatalogued(): void
    {
        $catalogued = [];

        foreach ($this->media->findAll() as $media) {
            $catalogued[] = $media->getFilename();
        }

        $this->derived->remove($this->derived->orphans($catalogued));

        if (!is_dir($this->storage->directory())) {
            return;
        }

        $stale = [];

        foreach (new Finder()->files()->in($this->storage->directory())->depth(0) as $file) {
            // Only names this application generates: 32 hexadecimal characters
            // and an extension. Anything else was put there by a person, and a
            // fixture load is not entitled to an opinion about it.
            if (1 !== preg_match('/^[0-9a-f]{32}\.[a-z0-9]{2,5}$/', $file->getFilename())) {
                continue;
            }

            if (!in_array($file->getFilename(), $catalogued, true)) {
                $stale[] = $file->getPathname();
            }
        }

        $this->filesystem->remove($stale);
    }

    /**
     * Gives every catalogued file something to point at.
     *
     * The factories catalogue files without uploading any, which is right for a
     * test — but a fresh installation whose articles all show a missing image
     * looks broken rather than empty. A generated placeholder is enough to tell
     * the difference between "no image" and "the image is not being served".
     *
     * Deliberately not real photographs: this is development data, and a
     * repository that ships binary assets to make fixtures pretty is a
     * repository that grows.
     */
    private function writePlaceholderBytes(): void
    {
        foreach ($this->media->findAll() as $media) {
            if ($this->storage->exists($media)) {
                continue;
            }

            $this->storage->writeRaw($media, $this->placeholderFor($media));
        }
    }

    /**
     * The placeholder has to match the type the record claims.
     *
     * The first version wrote a PNG for every image, including records catalogued
     * as JPEG. Files are served with the recorded type *and* an
     * X-Content-Type-Options: nosniff header, so a browser told "this is a JPEG"
     * and handed a PNG refuses to render it rather than working it out — which is
     * exactly what that header is for. The fixtures were the first thing it
     * caught.
     *
     * The second version was a one-by-one pixel, which browsers stretch to
     * whatever the layout asks for. That stopped being enough at feature 012:
     * the site now asks for a thumbnail, a medium and a large, and a single pixel
     * scaled to sixteen hundred is not a picture of anything. A development site
     * that looks broken teaches people to ignore it looking broken.
     */
    private function placeholderFor(Media $media): string
    {
        if ('application/pdf' === $media->getMimeType()) {
            return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
        }

        // Seeded with the stored filename, so each catalogued file is a
        // different picture and the same one on every load.
        return $this->placeholders->draw($media->getFilename(), $media->getMimeType());
    }
}
