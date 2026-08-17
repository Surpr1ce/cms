<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Full-text indexes for the site search.
 *
 * Hand-written rather than generated, and one of the few migrations in this
 * project that is: `doctrine:migrations:diff` describes tables and columns, and
 * an expression index over a weighted `tsvector` is neither.
 *
 * The expression has to match the one in {@see \App\Search\SiteSearch} exactly,
 * character for character, or PostgreSQL will not use the index and the search
 * will fall back to reading every row. Nothing enforces that agreement — it is
 * two places that have to be changed together, which is recorded in
 * `docs/status.md` rather than pretended away.
 */
final class Version20260817120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add full-text search indexes to article and page';
    }

    public function up(Schema $schema): void
    {
        foreach (['article', 'page'] as $table) {
            $this->addSql(<<<SQL
                CREATE INDEX idx_{$table}_search ON {$table} USING GIN ((
                    setweight(to_tsvector('english', {$table}.title), 'A')
                    || setweight(to_tsvector('english', coalesce({$table}.excerpt, '')), 'B')
                    || setweight(to_tsvector('english', regexp_replace({$table}.content, '<[^>]*>', ' ', 'g')), 'C')
                ))
                SQL);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_article_search');
        $this->addSql('DROP INDEX idx_page_search');
    }
}
