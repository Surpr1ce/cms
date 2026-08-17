<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Repository\MediaRepository;
use App\Service\Media\MediaStorage;
use App\Story\AppStory;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

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
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        AppStory::load();

        $manager->flush();

        $this->writePlaceholderBytes();
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

            $this->storage->writeRaw($media, $this->placeholderFor($media->getMimeType()));
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
     */
    private function placeholderFor(string $mimeType): string
    {
        return match ($mimeType) {
            'application/pdf' => "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n",
            'image/jpeg' => $this->decode('/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0a'
            .'HBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAA'
            .'AAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q=='),
            'image/gif' => $this->decode('R0lGODlhAQABAIAAAMzMzP///yH5BAEAAAAALAAAAAABAAEAAAICRAEAOw=='),
            // A 1×1 grey PNG. Browsers stretch it to whatever size the layout
            // asks for, which is what a placeholder should do.
            default => $this->decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='),
        };
    }

    private function decode(string $base64): string
    {
        return base64_decode($base64, true) ?: '';
    }
}
