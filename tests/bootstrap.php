<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

new Dotenv()->bootEnv(dirname(__DIR__).'/.env');

if ($_SERVER['APP_DEBUG']) {
    umask(0o000);
}

/*
 * Throws away the compiled container for the *non-debug* test kernel.
 *
 * Symfony only checks whether a container is stale when it is running with
 * debug on. A handful of tests here deliberately boot with it off — the ones
 * asserting that a 404 is identical whatever address missed, because Symfony's
 * exception page is a development tool and not what a reader sees — and those
 * tests were running against a container built at some point in the past.
 *
 * That has now cost time three times, and each time it looked like something
 * else: an assertion about a security header failing for no reason (feature
 * 011), a class removed with a dependency still being referenced (016), and a
 * service whose constructor had gained an argument (017). None of them looked
 * like a cache.
 *
 * Deleting two files costs one container build per suite run. It is worth it to
 * never debug this again.
 */
foreach (glob(dirname(__DIR__).'/var/cache/test/*KernelTestContainer*') ?: [] as $stale) {
    if (is_file($stale)) {
        unlink($stale);
    }
}
