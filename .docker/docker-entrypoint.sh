#!/bin/sh
set -e

# What a container start is, and what it is not.
#
# It is NOT a build. The cache is warmed once, in the image, through the
# application's own Composer auto-scripts — the shape the reference deployment
# uses, and the reason a warm-up that cannot fit fails the build instead of the
# first boot. Nothing here clears or warms anything.
# @see https://github.com/dunglas/symfony-docker/blob/main/Dockerfile
#
# It IS the first moment the database exists: DATABASE_URL is a runtime value,
# so the two steps that need one run here. Neither is guarded — a failing step
# fails the container, because a container that answers with half a schema or an
# empty catalogue is worse than one that does not answer at all.

if [ "$1" = 'frankenphp' ] || [ "$1" = 'php' ] || [ "$1" = 'bin/console' ]; then
    php bin/console -V

    # Wait for the database, the reference's loop verbatim: 60 attempts a second
    # apart, an exit status of 255 from the Doctrine command taken as
    # unrecoverable and not retried.
    # @see https://github.com/dunglas/symfony-docker/blob/main/frankenphp/docker-entrypoint.sh
    echo 'Waiting for database to be ready...'
    ATTEMPTS_LEFT_TO_REACH_DATABASE=60
    until [ $ATTEMPTS_LEFT_TO_REACH_DATABASE -eq 0 ] || DATABASE_ERROR=$(php bin/console dbal:run-sql -q "SELECT 1" 2>&1); do
        if [ $? -eq 255 ]; then
            ATTEMPTS_LEFT_TO_REACH_DATABASE=0
            break
        fi
        sleep 1
        ATTEMPTS_LEFT_TO_REACH_DATABASE=$((ATTEMPTS_LEFT_TO_REACH_DATABASE - 1))
        echo "Still waiting for database to be ready... Or maybe the database is not reachable. $ATTEMPTS_LEFT_TO_REACH_DATABASE attempts left."
    done

    if [ $ATTEMPTS_LEFT_TO_REACH_DATABASE -eq 0 ]; then
        echo 'The database is not up or not reachable:'
        echo "$DATABASE_ERROR"
        exit 1
    fi
    echo 'The database is now ready and reachable'

    # The schema first, then the catalogue: registry:sync reads tables the
    # migrations create, and refuses before they exist.
    php bin/console doctrine:migrations:migrate --no-interaction --all-or-nothing
    php bin/console registry:sync --no-interaction
fi

# Hand off to the PHP base image entrypoint, which execs the CMD (frankenphp run …).
exec docker-php-entrypoint "$@"
