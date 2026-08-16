<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Category;
use App\Entity\Slug;
use App\Repository\ArticleRepository;
use App\Repository\CategoryRepository;
use App\Service\Pagination\Paginator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CategoryController extends AbstractController
{
    public function __construct(
        private readonly CategoryRepository $categories,
        private readonly ArticleRepository $articles,
        private readonly Paginator $paginator,
    ) {
    }

    #[Route(
        '/sections/{slug}',
        name: 'category_show',
        requirements: ['slug' => Slug::ROUTE_PATTERN],
        methods: ['GET'],
    )]
    public function show(string $slug, Request $request): Response
    {
        $category = $this->categories->findOneBySlug($slug);

        if (!$category instanceof Category) {
            throw $this->createNotFoundException();
        }

        // A section with nothing published in it renders empty, not missing
        // (FR-015). Returning 404 here would tell an outsider the difference
        // between a section that does not exist and one holding only drafts,
        // which is exactly the disclosure US2 is about.
        $number = Paginator::pageNumberFrom($request->query->get('page'));

        $fetched = $this->articles->findPublishedPageByCategory(
            $category,
            $this->paginator->fetchLimitFor(),
            $this->paginator->offsetFor($number),
        );

        return $this->render('public/category/show.html.twig', [
            'category' => $category,
            'children' => $this->categories->findChildrenOf($category),
            'page' => $this->paginator->paginate($fetched, $number),
        ]);
    }
}
