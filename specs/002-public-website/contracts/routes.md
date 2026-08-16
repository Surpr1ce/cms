# Phase 1 Contract: Public address scheme

**Feature**: `002-public-website` | **Date**: 2026-08-17

The addresses this feature puts on the public internet. Once a link exists in the
world it cannot be taken back, so this is the contract that matters most in the
feature and the one that needs an ADR to reverse.

## The scheme

| Route name | Method | Path | Renders |
| --- | --- | --- | --- |
| `home` | GET | `/` | Published articles, newest first, paginated |
| `article_show` | GET | `/articles/{slug}` | One published article |
| `category_show` | GET | `/sections/{slug}` | A section, its subsections, its published articles |
| `tag_show` | GET | `/topics/{slug}` | A label and its published articles |
| `page_show` | GET | `/{slug}` | One published standalone page |

Every `{slug}` carries the requirement `[a-z0-9]+(?:-[a-z0-9]+)*`, which is the
same expression `App\Entity\Slug::PATTERN` enforces when content is created. An
address that cannot have been generated therefore never reaches a controller.

Listings accept `?page=` — a positive integer. Anything else is treated as page 1
rather than as an error (FR-022).

## Why `/{slug}` is last, and why it is safe

`page_show` matches almost anything, so it is declared after the others and
Symfony's router tries routes in declaration order. Three things keep it from
swallowing the rest:

1. **Declaration order.** `PageController` is loaded last. This is asserted by a
   test that requests `/articles/{slug}` for a real article and checks it renders
   the article, not a page.
2. **The slug requirement.** `/articles/some-slug` has a `/` in the middle and
   cannot match a single `{slug}` segment at all.
3. **Reserved first segments.** `articles`, `sections` and `topics` are refused as
   page addresses, so a page called "Articles" cannot shadow the article prefix.
   Enforced in the controller and tested.

The residual risk is a *future* prefix: adding `/search` later means no page may
be called `search`. That is the cost of root-level page addresses, it is accepted,
and the reserved list is the single place a future feature has to update.

## What the reader can and cannot reach

| Request | Response |
| --- | --- |
| Published article at its address | 200, the article |
| **Draft** article at its address | **404, the site's not-found page** |
| **Archived** article at its address | **404, the site's not-found page** |
| Address that never existed | **404, the site's not-found page** |
| Section that exists but has no published articles | 200, an empty listing |
| Label that exists but has no published articles | 200, an empty listing |
| Section or label that does not exist | 404 |
| `?page=` beyond the last page | 200, an empty listing |
| `?page=0`, `?page=-3`, `?page=abc` | 200, page 1 |

The three 404 rows must be indistinguishable from one another (FR-002, SC-002).
They are produced by the same `NotFoundHttpException` thrown from the same helper,
so there is no wording, status or structure that could differ.

## Repository methods this feature adds

All on classes that already exist. None reimplements the published scope; each
routes through the private one already there.

```php
ArticleRepository:
    /** @return list<Article> author and category join-fetched */
    public function findPublishedPage(int $limit, int $offset): array;
    /** @return list<Article> */
    public function findPublishedPageByCategory(Category $c, int $limit, int $offset): array;
    /** @return list<Article> */
    public function findPublishedPageByTag(Tag $t, int $limit, int $offset): array;

PageRepository:
    /** @return list<Page> every published page, for building the menu in one query */
    public function findPublishedForMenu(): array;
```

The three `findPublishedPage*` methods exist alongside the `findPublished*`
methods feature 001 added rather than replacing them: the older ones return a
plain page of results, these join-fetch what a listing renders. Keeping both
means the API consumer that arrives later is not forced to pay for joins it does
not use.

## Templates as a contract

`templates/public/layout.html.twig` defines the blocks every page fills:
`title`, `meta_description`, `body`, and `main`. A later feature adding a page
type extends the same layout and fills the same blocks.
