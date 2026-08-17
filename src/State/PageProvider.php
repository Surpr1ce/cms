<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\PageResource;
use App\Entity\Page;
use App\Repository\PageRepository;

use function is_string;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Pages, through the same published-scope methods the website uses.
 *
 * @implements ProviderInterface<PageResource>
 */
final readonly class PageProvider implements ProviderInterface
{
    public function __construct(
        private PageRepository $pages,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     *
     * @return list<PageResource>|PageResource|null
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array|PageResource|null
    {
        if ($operation instanceof CollectionOperationInterface) {
            return array_map(
                fn (Page $page): PageResource => PageResource::from($page, $this->mediaUrl(...)),
                $this->pages->findPublishedForMenu(),
            );
        }

        $slug = $uriVariables['slug'] ?? null;

        if (!is_string($slug)) {
            return null;
        }

        $page = $this->pages->findOnePublishedBySlugWithRelations($slug);

        return !$page instanceof Page ? null : PageResource::from($page, $this->mediaUrl(...));
    }

    private function mediaUrl(string $filename): string
    {
        return $this->urls->generate('media_show', ['filename' => $filename], UrlGeneratorInterface::ABSOLUTE_PATH);
    }
}
