<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\Media;
use App\Service\Media\StoredFilenameGenerator;
use DateTimeImmutable;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Media>
 */
final class MediaFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Media::class;
    }

    /**
     * A catalogued file that is not yet describable to a reader — the state that
     * makes setFeaturedImage() refuse.
     */
    public function withoutAltText(): static
    {
        return $this->with(['altText' => null]);
    }

    public function pdf(): static
    {
        return $this->with([
            'filename' => new StoredFilenameGenerator()->generate('application/pdf'),
            'mimeType' => 'application/pdf',
            'originalName' => 'report.pdf',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            // Generated the same way production would, so a test can never
            // accidentally depend on a filename derived from the original.
            'filename' => new StoredFilenameGenerator()->generate('image/jpeg'),
            'originalName' => self::faker()->word().'.jpg',
            'mimeType' => 'image/jpeg',
            'size' => self::faker()->numberBetween(1_024, 4_194_304),
            'altText' => self::faker()->sentence(4),
            'uploadedBy' => UserFactory::new(),
            'uploadedAt' => DateTimeImmutable::createFromMutable(self::faker()->dateTimeBetween('-6 months')),
        ];
    }
}
