# syntax=docker/dockerfile:1
# uhifadhi production image — FrankenPHP (Caddy) + PHP 8.4. Single artifact: the
# PHP app, built and run from one image.
#
# This is the deploy shape every installation inherits. The core ships assets,
# so the image builds them: importmap:install fetches the vendor JavaScript and
# asset-map:compile writes public/assets — the one place assets are ever
# compiled; in development AssetMapper serves them from source. Capability that
# needs more from the image brings it as a layer of its own (raster/GIS tooling
# arrives with the module that ingests rasters).
FROM dunglas/frankenphp:1-php8.4 AS base

WORKDIR /app

# System libs + PHP extensions. pdo_pgsql is here from the start on purpose: the
# platform's database is PostgreSQL/PostGIS, and the seed is copied once and never
# updated — leaving it out would mean every installation editing this file the day
# it installs its first entity-bearing module.
# apcu is here because the reference's 10-app.ini sets apc.enable_cli, and a
# directive is copied with the extension that answers it or not at all.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libpq-dev libicu-dev libzip-dev ca-certificates \
    && install-php-extensions pdo_pgsql intl zip opcache apcu \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Production php.ini, then the application's own directives in their own scan
# directory — the reference's arrangement, so a numbered file is added without
# touching the distribution's conf.d.
# @see https://github.com/dunglas/symfony-docker/blob/main/Dockerfile
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
ENV PHP_INI_SCAN_DIR=":$PHP_INI_DIR/app.conf.d"
COPY .docker/conf.d/10-app.ini .docker/conf.d/30-uploads.ini .docker/conf.d/40-requests.ini $PHP_INI_DIR/app.conf.d/

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    SERVER_NAME=:80 \
    COMPOSER_ALLOW_SUPERUSER=1

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# 1) Dependency layer — cached until composer.{json,lock} change. Module bundles
#    install from private GitHub vcs repos, so composer needs a token once the
#    first one is added: COMPOSER_AUTH (auth.json JSON, read natively by composer)
#    comes in as a BuildKit secret — never baked into a layer. Absent, the build
#    carries on unauthenticated, which is all the bare seed needs.
COPY composer.json composer.lock symfony.lock ./
RUN --mount=type=secret,id=COMPOSER_AUTH \
    COMPOSER_AUTH="$(cat /run/secrets/COMPOSER_AUTH 2>/dev/null || true)" \
    composer install --no-dev --no-scripts --no-progress --prefer-dist --no-autoloader

# 2) Application source, and with it the production directives. 20-app.prod.ini
#    names config/preload.php, and 10-app.ini turns OPcache on for the CLI — so
#    unlike the reference, where preloading is a web-server-only concern because
#    the CLI never preloads, the directive here fires in every console process
#    and must not be in place before the file it requires is.
COPY . .
COPY .docker/conf.d/20-app.prod.ini $PHP_INI_DIR/app.conf.d/

# 3) Optimise the autoloader, warm the cache and build the assets. The warm-up
#    is a BUILD step, run the way the reference deployment runs it — through the
#    application's own Composer auto-scripts, which is where Symfony puts
#    `cache:clear` — so the image ships a warm prod cache and a warm-up that
#    cannot fit fails the build rather than the first container:
#      RUN <<-EOF
#        composer dump-autoload --classmap-authoritative --no-dev
#        composer dump-env prod
#        composer run-script --no-dev post-install-cmd
#        if [ -f importmap.php ]; then
#          php bin/console asset-map:compile
#        fi
#      EOF
#    @see https://github.com/dunglas/symfony-docker/blob/main/Dockerfile
#
#    No secrets and no database are needed for any of it: the auto-scripts end
#    with importmap:install, which fetches the vendor JavaScript because
#    assets/vendor is not committed, and asset-map:compile then writes
#    public/assets, which is not committed either. THE ORDER IS THE DOCUMENTED
#    ONE — compile can only write what install has fetched:
#      "All packages in importmap.php are downloaded into an assets/vendor/
#       directory, which should be ignored by git (the Flex recipe adds it to
#       .gitignore for you). You'll need to run the following command to
#       download the files on other computers if some are missing:
#       php bin/console importmap:install"
#      "In the dev environment, the URL /assets/images/duck-3c16d92m.png is
#       handled and returned by your Symfony app. For the prod environment,
#       before deploy, you'll run a command to write the final versioned files
#       into public/assets/ so that they're served directly by your web server."
#    which is why dev serves from source and only the image compiles.
#    @see https://symfony.com/doc/current/frontend/asset_mapper.html#deploying
#    @see vendor/symfony/asset-mapper/Command/AssetMapperCompileCommand.php
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && composer run-script --no-dev post-install-cmd \
    && php bin/console asset-map:compile \
    && mkdir -p var && chown -R www-data:www-data var \
    && chmod +x .docker/docker-entrypoint.sh

# The container start does what only a running container can: it waits for the
# database, migrates and syncs the catalogue. See the entrypoint.
ENTRYPOINT ["/app/.docker/docker-entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
