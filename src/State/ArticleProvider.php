<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\ArticleResource;
use App\Entity\Article;
use App\Repository\ArticleRepository;
use App\Service\Pagination\Paginator;

use function is_string;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Where the API gets its articles.
 *
 * Note what is absent: there is no status comparison here, and no query. Both
 * methods called below have `Published` in the name and were written for the
 * website in feature 002. That is what makes ADR 2's claim true by construction
 * rather than by discipline — the API cannot disagree with the website about
 * what is published, because it does not have its own opinion to disagree with.
 *
 * FR-009 and SC-005 are about exactly this. If this class ever grows a
 * `->andWhere('status = ...')`, the architecture the project is arranged around
 * has quietly stopped holding.
 *
 * @implements ProviderInterface<ArticleResource>
 */
final readonly class ArticleProvider implements ProviderInterface
{
    public function __construct(
        private ArticleRepository $articles,
        private Paginator $paginator,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     *
     * @return list<ArticleResource>|ArticleResource|null
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array|ArticleResource|null
    {
        if ($operation instanceof CollectionOperationInterface) {
            return $this->collection($context);
        }

        $slug = $uriVariables['slug'] ?? null;

        if (!is_string($slug)) {
            return null;
        }

        $article = $this->articles->findOnePublishedBySlugWithRelations($slug);

        // Null becomes a 404 with no body worth reading. A draft and an address
        // that never existed arrive here identically, and leave identically.
        return !$article instanceof Article ? null : ArticleResource::from($article, $this->mediaUrl(...));
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return list<ArticleResource>
     */
    private function collection(array $context): array
    {
        $request = $context['request'] ?? null;
        $page = $request instanceof Request
            ? Paginator::pageNumberFrom($request->query->get('page'))
            : 1;

        $articles = $this->articles->findPublishedPage(
            $this->paginator->perPage(),
            $this->paginator->offsetFor($page),
        );

        return array_map(
            fn (Article $article): ArticleResource => ArticleResource::from($article, $this->mediaUrl(...)),
            $articles,
        );
    }

    private function mediaUrl(string $filename): string
    {
        return $this->urls->generate('media_show', ['filename' => $filename], UrlGeneratorInterface::ABSOLUTE_PATH);
    }
}
