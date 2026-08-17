<?php

declare(strict_types=1);

/*
 * Exits 0 when the database holds no accounts, which is this container's
 * definition of "empty and worth seeding".
 *
 * Asked through PDO rather than through a console command for two reasons: the
 * fixtures live in the development environment while the site runs in production,
 * so a console call would have to pick one; and a missing table has to be an
 * answer here ("nothing yet") rather than an error, which `doctrine:query:sql`
 * would make it.
 *
 * Accounts rather than articles: a demonstration database with content and nobody
 * able to sign in is the state that looks fine and is useless.
 */

$url = getenv('DATABASE_URL');

if (!is_string($url) || '' === $url) {
    fwrite(\STDERR, "DATABASE_URL is not set.\n");

    exit(2);
}

$parts = parse_url($url);

if (!is_array($parts) || !isset($parts['host'], $parts['path'])) {
    fwrite(\STDERR, "DATABASE_URL could not be read.\n");

    exit(2);
}

$dsn = sprintf(
    'pgsql:host=%s;port=%d;dbname=%s',
    $parts['host'],
    $parts['port'] ?? 5432,
    ltrim($parts['path'], '/'),
);

try {
    $connection = new PDO(
        $dsn,
        urldecode($parts['user'] ?? 'app'),
        urldecode($parts['pass'] ?? ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );

    // `app_user`, not `user`: the entity renames it because "user" is reserved in
    // PostgreSQL, and src/Entity/User.php says so at line 25. The first version of
    // this file guessed `user`, which threw, which was read as "empty" — so every
    // restart purged the database and reloaded the fixtures. Anything written
    // during a demonstration would have disappeared on the next `docker compose
    // restart`.
    $accounts = $connection->query('SELECT COUNT(*) FROM app_user')?->fetchColumn();
} catch (PDOException) {
    // No table, no connection, nothing there. Either way: seed.
    exit(0);
}

exit(0 === (int) $accounts ? 0 : 1);
