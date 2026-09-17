#!/bin/sh
set -e

if [ ! -f vendor/autoload.php ]; then
    echo "[dev-entrypoint] installing composer dependencies (first run)..."
    composer install --no-interaction --no-progress
fi

if [ ! -f .env.test.local ]; then
    echo "[dev-entrypoint] creating .env.test.local from .env.test..."
    cp .env.test .env.test.local
fi

if [ ! -f data/cert.pem ] || [ ! -f data/logo.png ]; then
    echo "[dev-entrypoint] copying test cert/logo into data/ (SUNAT beta sandbox cert, not for prod)..."
    cp tests/Resources/cert.pem tests/Resources/logo.png data/
fi

exec "$@"
