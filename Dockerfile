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
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libpq-dev libicu-dev libzip-dev ca-certificates \
    && install-php-extensions pdo_pgsql intl zip opcache \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Production php.ini + opcache tuning.
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY .docker/opcache.ini $PHP_INI_DIR/conf.d/zz-opcache.ini
COPY .docker/uploads.ini $PHP_INI_DIR/conf.d/zz-uploads.ini

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

# 2) Application source.
COPY . .

# 3) Optimise the autoloader and build the assets. No secrets and no database are
#    needed for either: importmap:install fetches the vendor JavaScript because
#    assets/vendor is not committed; asset-map:compile writes public/assets, which
#    is not committed either. THE ORDER IS THE DOCUMENTED ONE — compile can only
#    write what install has fetched:
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
    && php bin/console importmap:install \
    && php bin/console asset-map:compile \
    && mkdir -p var && chown -R www-data:www-data var \
    && chmod +x .docker/docker-entrypoint.sh

# Cache is warmed at container start (runtime env available). See entrypoint.
ENTRYPOINT ["/app/.docker/docker-entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
