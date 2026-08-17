<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\TagResource;
use App\Entity\Tag;
use App\Repository\TagRepository;

use function is_string;

/**
 * @implements ProviderInterface<TagResource>
 */
final readonly class TagProvider implements ProviderInterface
{
    public function __construct(private TagRepository $tags)
    {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     *
     * @return list<TagResource>|TagResource|null
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array|TagResource|null
    {
        if ($operation instanceof CollectionOperationInterface) {
            // findInUse(), not findAll(). A label exists to describe what an
            // article is about, so a list of every label in the table would name
            // the subjects of unfinished drafts. The website already takes this
            // care; the API takes it by calling the same method.
            return array_map(TagResource::from(...), $this->tags->findInUse());
        }

        $slug = $uriVariables['slug'] ?? null;

        if (!is_string($slug)) {
            return null;
        }

        // findOneInUseBySlug, for the reason the collection above calls
        // findInUse: this resource's own description says a label here is one
        // carried by at least one published article, and until an audit noticed,
        // the item address answered for any label in the table.
        $tag = $this->tags->findOneInUseBySlug($slug);

        return !$tag instanceof Tag ? null : TagResource::from($tag);
    }
}
