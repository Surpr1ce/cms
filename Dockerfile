# syntax=docker/dockerfile:1

#
# The whole application in one container, for demonstrating it and for anybody who
# would rather not install PHP 8.4 and PostgreSQL to look at this.
#
# It is **not** how this project is developed. `composer serve` against a native
# PostgreSQL stays the default, for the reasons in
# docs/adr/0007-docker-is-available-after-all.md — this image is the other path in
# that ADR, finally built rather than described.
#
# FrankenPHP rather than php-fpm behind nginx: one process, one container, and the
# same server Symfony ships as its own recommendation. It serves /app/public and
# expects the application at /app.
#
# The image installs development dependencies on purpose, which a production image
# would not. The demo fixtures are loaded by DoctrineFixturesBundle and Foundry,
# and config/bundles.php registers both for dev and test only — so seeding a demo
# database needs them present. That is the trade this image makes: it is a
# demonstration of the application, not a deployment of it.
#

FROM dunglas/frankenphp:php8.4 AS app

# intl and pdo_pgsql are what the CI workflow installs, and they are enough to run
# the test suite — which is why gd is easy to forget here and impossible to miss:
# the fixtures draw placeholder images and the media pipeline resizes uploads with
# it, in JPEG, PNG, GIF, WebP and AVIF. The first build of this image failed on
# `imagecreatetruecolor`, three seconds into loading the demo content.
#
# opcache is what makes the difference between a demonstration that feels finished
# and one that feels slow; zip lets Composer install from archives rather than
# cloning.
RUN install-php-extensions \
    intl \
    pdo_pgsql \
    gd \
    exif \
    opcache \
    zip

COPY --from=composer/composer:2-bin /composer /usr/bin/composer

WORKDIR /app

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    COMPOSER_ALLOW_SUPERUSER=1 \
    # FrankenPHP serves on this address; compose publishes it.
    SERVER_NAME=:8080

# Dependencies before the source, so that editing a controller does not reinstall
# the vendor directory. --no-scripts because Flex's auto-scripts want a warm
# environment that does not exist yet at this point in the build.
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-scripts --no-interaction --prefer-dist

COPY . .

# Everything that can be done without a database is done at build time, so the
# container starts in seconds rather than compiling on first request:
#
#   - the autoloader, optimised;
#   - the importmap's vendor files, which .gitignore keeps out of the repository
#     and .dockerignore therefore keeps out of the build context: they are
#     downloaded here rather than copied from whatever a developer's machine
#     happens to hold;
#   - the Tailwind stylesheet, built by the pinned standalone binary this project
#     uses instead of a Node toolchain;
#   - the asset map, compiled into public/assets — in production nothing serves
#     those files through the application, so they have to exist on disk;
#   - the production cache.
RUN composer dump-autoload --optimize \
    && php bin/console importmap:install \
    && php bin/console tailwind:build --minify \
    && php bin/console asset-map:compile \
    && php bin/console cache:warmup

# Uploads and the SQLite-free var directory belong to the running container, not
# to the image. compose mounts a volume over the uploads.
RUN mkdir -p var/uploads var/cache var/log && chmod -R 777 var

COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

ENTRYPOINT ["entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
