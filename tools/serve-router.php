<?php

declare(strict_types=1);

/*
 * The router PHP's built-in server needs in front of this application.
 *
 * `php -S ... -t public` alone cannot serve a site with pretty addresses: there is
 * no file at `/articles/something`, so the server answers 404 before Symfony sees
 * it. Naming `public/index.php` as the router fixes that and breaks the opposite
 * case — *every* request then goes through Symfony, including the compiled
 * JavaScript under `/assets/`.
 *
 * That second half is invisible in the development environment, because
 * AssetMapper serves those files through a controller there. Compile the assets
 * for production and they become ordinary files on disk with no route behind
 * them, so every one of them answers 500 and the site loads with no JavaScript at
 * all — which is exactly what happened the first time `composer serve:prod` was
 * run, and what `tools/browser-check.mjs` then reported as sixteen broken
 * features.
 *
 * So: an existing file is handed back to the server to send as-is, and everything
 * else goes to the application. `return false` is the built-in server's documented
 * way of saying "serve this yourself".
 *
 * This is a development and demonstration convenience. A real deployment puts a
 * web server in front, and that web server does this job.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if (is_string($path) && '/' !== $path) {
    $file = __DIR__.'/../public'.$path;

    // realpath() before the prefix check: a request for `/../config/secrets` must
    // not be answered with a file from outside the document root.
    $resolved = realpath($file);
    $root = realpath(__DIR__.'/../public');

    if (false !== $resolved && false !== $root && str_starts_with($resolved, $root) && is_file($resolved)) {
        return false;
    }
}

require __DIR__.'/../public/index.php';
