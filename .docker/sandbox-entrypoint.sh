#!/bin/sh
set -eu

SANDBOX_DIR=/var/www/sandbox
PACKAGE_DIR=/var/www/package

DEMO_REPO="${DEMO_PROJECT_REPO:-https://github.com/moonshine-software/demo-project.git}"
DEMO_REF="${DEMO_PROJECT_REF:-4.0}"
SANDBOX_USER="admin@admin.com"
SANDBOX_USER_NAME="Admin"
SANDBOX_USER_PASSWORD="$(head -c 32 /dev/urandom | md5sum | cut -d' ' -f1)"

sync_demo() {
    mkdir -p "$SANDBOX_DIR/app/MoonShine/Pages" \
             "$SANDBOX_DIR/app/MoonShine/Layouts" \
             "$SANDBOX_DIR/app/Http/Middleware"

    cp "$PACKAGE_DIR/.docker/demo/ChunkUploadPlaygroundPage.php" \
        "$SANDBOX_DIR/app/MoonShine/Pages/ChunkUploadPlaygroundPage.php"
    cp "$PACKAGE_DIR/.docker/demo/PlaygroundLayout.php" \
        "$SANDBOX_DIR/app/MoonShine/Layouts/PlaygroundLayout.php"
    cp "$PACKAGE_DIR/.docker/demo/AutoLoginMiddleware.php" \
        "$SANDBOX_DIR/app/Http/Middleware/AutoLoginMiddleware.php"
    cp "$PACKAGE_DIR/.docker/demo/playground-routes.php" \
        "$SANDBOX_DIR/routes/playground.php"

    sed -i \
        -e "s/'enabled' => false,/'enabled' => true,/" \
        -e "s/'dashboard' => Dashboard::class,/'dashboard' => \\\\App\\\\MoonShine\\\\Pages\\\\ChunkUploadPlaygroundPage::class,/" \
        -e "s/            Authenticate::class,/            \\\\App\\\\Http\\\\Middleware\\\\AutoLoginMiddleware::class,/" \
        "$SANDBOX_DIR/config/moonshine.php"

    grep -q "playground.php" "$SANDBOX_DIR/routes/web.php" \
        || printf "\nrequire __DIR__ . '/playground.php';\n" >> "$SANDBOX_DIR/routes/web.php"

    (cd "$SANDBOX_DIR" && composer dump-autoload --no-interaction --quiet --ignore-platform-req=php)
    php artisan moonshine:optimize-clear || true
}

if [ ! -f "$SANDBOX_DIR/artisan" ]; then
    echo "==> [1/7] Cloning the MoonShine demo project (${DEMO_REPO}#${DEMO_REF})..."
    git clone --branch "$DEMO_REF" --depth 1 "$DEMO_REPO" "$SANDBOX_DIR"
    cd "$SANDBOX_DIR"

    echo "==> [2/7] Preparing .env for sqlite..."
    cp .env.example .env
    sed -i \
        -e 's/^DB_CONNECTION=.*/DB_CONNECTION=sqlite/' \
        -e '/^DB_HOST=/d' -e '/^DB_PORT=/d' -e '/^DB_DATABASE=/d' -e '/^DB_USERNAME=/d' -e '/^DB_PASSWORD=/d' \
        -e 's#^APP_URL=.*#APP_URL=http://localhost:8000#' \
        .env
    mkdir -p database
    touch database/database.sqlite

    echo "==> [3/7] Wiring the local package as a composer path repository..."
    composer config repositories.moonshine-chunk-upload \
        '{"type": "path", "url": "'"$PACKAGE_DIR"'", "options": {"symlink": true}}'
    composer require "w33bvgl/moonshine-chunk-upload:*" --no-interaction --no-audit --with-all-dependencies --ignore-platform-req=php

    echo "==> [4/7] Bootstrapping Laravel..."
    php artisan key:generate --force
    php artisan storage:link || true

    echo "==> [5/7] Publishing package assets and config, running migrations..."
    php artisan vendor:publish --tag=moonshine-chunk-upload-assets --force
    php artisan vendor:publish --tag=moonshine-chunk-upload-config --force
    php artisan migrate --force --seed || php artisan migrate --force

    echo "==> [6/7] Creating a MoonShine admin user..."
    php artisan moonshine:user \
        --username="$SANDBOX_USER" \
        --name="$SANDBOX_USER_NAME" \
        --password="$SANDBOX_USER_PASSWORD" \
        --no-interaction || true

    echo "==> [7/7] Dropping in the upload playground..."
    sync_demo
else
    cd "$SANDBOX_DIR"
    echo "==> Sandbox already bootstrapped, re-syncing package changes..."
    composer update w33bvgl/moonshine-chunk-upload --no-interaction --no-audit --ignore-platform-req=php
    php artisan vendor:publish --tag=moonshine-chunk-upload-assets --force
    sync_demo
    php artisan optimize:clear
fi

echo ""
echo "==> MoonShine sandbox ready at http://localhost:8000/admin"
echo "==> Auto-login is enabled — the chunk upload playground opens directly, no login screen"
echo "==> Prune the staging directories: docker compose exec sandbox php artisan chunk-upload:prune --dry-run"
echo ""

exec php artisan serve --host=0.0.0.0 --port=8000
