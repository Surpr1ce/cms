<?php

declare(strict_types=1);

namespace App\Search;

use App\Entity\ContentStatus;
use App\Service\Seo\PlainText;

use function array_map;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;

use function is_numeric;
use function is_string;
use function sprintf;

/**
 * The one query that answers a search.
 *
 * A query object rather than a method on `ArticleRepository`, because a result
 * list holds both kinds of content ranked against each other. Ranking them
 * separately and merging afterwards would be wrong the moment paging is
 * involved: page two of a merged list is not page two of either half.
 *
 * **This is the first delivery that does not read through a published-only
 * repository method**, and that is the whole risk. Every earlier one —
 * `findPublishedPage()`, `findOnePublishedBySlug()`, the API providers, the feed
 * — is safe structurally, because the method it calls cannot return anything
 * else. Here the guarantee is one `WHERE` clause per half, written below, and
 * the tests assert the consequence rather than the clause: a word that only a
 * draft contains produces a response identical to a word nothing contains.
 *
 * The reader's words are a bound parameter and become a query through
 * `plainto_tsquery`, which reads operators and punctuation as words. Nothing
 * here builds a query expression by concatenation.
 */
final readonly class SiteSearch
{
    /**
     * English, matching the language the constitution requires everything to be
     * written in. Stemming is the reason this uses full-text search rather than
     * a `LIKE` scan: a reader searching for "publishing" finds "published", and
     * cannot be expected to guess an author's grammar.
     */
    private const string CONFIGURATION = 'english';

    /**
     * The searchable text of a row, weighted.
     *
     * A title match counts for more than a passing mention, which is what makes
     * searching for a headline find the headline rather than the twelve articles
     * that refer to it.
     *
     * Markup is stripped first. Bodies are stored as HTML, and without this the
     * index would hold `p`, `strong` and `href` as words — a search for "strong"
     * would match most of the site.
     */
    private const string DOCUMENT = <<<'SQL'
        setweight(to_tsvector('english', %1$s.title), 'A')
        || setweight(to_tsvector('english', coalesce(%1$s.excerpt, '')), 'B')
        || setweight(to_tsvector('english', regexp_replace(%1$s.content, '<[^>]*>', ' ', 'g')), 'C')
        SQL;

    public function __construct(
        private Connection $connection,
        private PlainText $plainText,
    ) {
    }

    /**
     * One extra row is fetched beyond the page, the same trick the rest of the
     * site's paging uses: it answers "is there another page" without a second
     * `COUNT` over a full-text match, which is the expensive half of a search.
     *
     * @return list<SearchHit>
     */
    public function search(SearchQuery $query, int $limit, int $offset): array
    {
        if (!$query->isWorthRunning()) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative($this->sql(), [
            'query' => $query->text,
            'status' => ContentStatus::Published->value,
            'limit' => $limit,
            'offset' => $offset,
        ]);

        return array_map($this->toHit(...), $rows);
    }

    private function sql(): string
    {
        $article = sprintf(self::DOCUMENT, 'a');
        $page = sprintf(self::DOCUMENT, 'p');
        $configuration = self::CONFIGURATION;

        // A UNION rather than two queries, so that an article and a page compete
        // for the same place in the list and paging cuts the combined order.
        //
        // `published_at DESC` after the rank so that two equally relevant
        // results are in a stable, meaningful order rather than whichever the
        // planner happened to produce.
        return <<<SQL
            SELECT kind, title, slug, excerpt, content, published_at, rank
            FROM (
                SELECT 'article' AS kind,
                       a.title,
                       a.slug,
                       a.excerpt,
                       a.content,
                       a.published_at,
                       ts_rank($article, plainto_tsquery('$configuration', :query)) AS rank
                FROM article a
                WHERE a.status = :status
                  AND ($article) @@ plainto_tsquery('$configuration', :query)

                UNION ALL

                SELECT 'page' AS kind,
                       p.title,
                       p.slug,
                       p.excerpt,
                       p.content,
                       p.published_at,
                       ts_rank($page, plainto_tsquery('$configuration', :query)) AS rank
                FROM page p
                WHERE p.status = :status
                  AND ($page) @@ plainto_tsquery('$configuration', :query)
            ) AS results
            ORDER BY rank DESC, published_at DESC NULLS LAST, title ASC
            LIMIT :limit OFFSET :offset
            SQL;
    }

    /**
     * A row of the result set, made into something typed.
     *
     * Every column is read through a guard rather than cast. A driver returns
     * `mixed` and always will, and a cast would turn a column that stopped
     * existing — renamed, dropped, misspelled in the query above — into an empty
     * string that renders as a blank result. Reading it this way means a change
     * to the schema shows up as an empty page rather than as a page of nothing.
     *
     * @param array<string, mixed> $row
     */
    private function toHit(array $row): SearchHit
    {
        $excerpt = $this->text($row, 'excerpt');
        $content = $this->text($row, 'content');
        $publishedAt = $this->text($row, 'published_at');

        return new SearchHit(
            kind: $this->text($row, 'kind'),
            title: $this->text($row, 'title'),
            slug: $this->text($row, 'slug'),
            // The same one-line summary the previews and the feed show, so a
            // result reads as the same thing a reader will meet.
            summary: $this->plainText->summarise('' !== $excerpt ? $excerpt : $content),
            publishedAt: '' === $publishedAt ? null : new DateTimeImmutable($publishedAt),
            rank: is_numeric($row['rank'] ?? null) ? (float) $row['rank'] : 0.0,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function text(array $row, string $column): string
    {
        $value = $row[$column] ?? null;

        return is_string($value) ? $value : '';
    }
}
