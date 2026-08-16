# Architecture

## Overview

The application is a Symfony monolith with a strict internal boundary between the
domain and the delivery mechanism. Twig templates and the JSON API are two
consumers of one domain layer, not two parallel implementations.

```
                    ┌──────────────────────────────┐
  browser  ────────▶│  Controller\  (public, admin)│──┐
                    └──────────────────────────────┘  │
                                                      ▼
                    ┌──────────────────────────────┐  ┌──────────────┐
  API client ──────▶│  API Platform (read-only)    │─▶│   Service\   │
                    └──────────────────────────────┘  └──────┬───────┘
                                                             ▼
                                                      ┌──────────────┐
                                                      │ Repository\  │
                                                      └──────┬───────┘
                                                             ▼
                                                      ┌──────────────┐
                                                      │   Entity\    │
                                                      └──────┬───────┘
                                                             ▼
                                                        PostgreSQL
```

Dependencies point downwards only. Nothing below `Controller\` may reference
`Request`, `Response`, Twig, or the session — this is the rule that keeps the
domain reusable by both delivery mechanisms, and it is checked in review by the
`symfony-reviewer` agent.

## Layers

### `src/Entity/`

Doctrine entities carrying domain state and the invariants that protect it. State
transitions are expressed as intention-revealing methods (`publish()`,
`archive()`) rather than generic setters, so an invalid transition is impossible
to express rather than merely discouraged.

Mapping is declared with PHP attributes. Entities know nothing about the database
beyond that mapping, and nothing at all about HTTP.

### `src/Repository/`

Query objects. Each repository returns typed results and never exposes a
`QueryBuilder` to its callers — otherwise query construction leaks into
controllers and the same query gets rebuilt in three places with three different
sets of joins.

### `src/Service/`

Application services holding logic that spans entities or requires
infrastructure: slug generation, the publishing workflow, media upload handling.
A service takes primitives or entities and returns primitives or entities.

### `src/Controller/`

The HTTP boundary. An action method resolves input, calls one service, and hands
the result to a template or a response. Business rules in a controller are a
review finding, not a style preference — they are the thing that cannot be reused
by the API.

`src/Controller/Admin/` holds the hand-written admin screens. EasyAdmin handles
the generic CRUD resources; see [ADR 5](adr/) for the split.

### `src/Security/`

Voters and authenticators. Authorisation decisions that depend on the object
being acted upon — "may this user edit *this* article" — belong in a voter, never
in a template condition and never in a controller `if`.

## Request flow

A public article page:

1. Router matches `/blog/{slug}` to `ArticleController::show`.
2. The controller asks `ArticleRepository` for the published article with that
   slug; a missing or unpublished article produces a 404.
3. The controller passes the entity to `public/article/show.html.twig`.
4. Twig renders, escaping all output by default.

The same article through the API:

1. API Platform matches `/api/articles/{id}`.
2. Its Doctrine state provider loads the entity, filtered by the same published
   scope.
3. The serializer emits the fields exposed by the configured serialization group.

Both paths read the same entity through the same published-content rule. That
rule exists in one place.

## Asset pipeline

AssetMapper serves assets directly, with no Node build step and no bundler.
Tailwind is compiled by the standalone binary that `symfonycasts/tailwind-bundle`
manages. JavaScript dependencies are declared in `importmap.php` and vendored
into the repository, so a checkout builds without a package manager.

## Data storage

PostgreSQL 16, accessed through Doctrine ORM 3. Schema changes are made by
generating a migration with `doctrine:migrations:diff` and committing it;
migrations are never edited after being committed, because someone else may have
already run them.

Uploaded media is stored on the filesystem under a path outside the document
root, and served through a controller that applies access rules — a public URL
that maps directly to an uploaded file would bypass authorisation entirely.
