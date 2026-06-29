# syntax=docker/dockerfile:1
# crunch — FrankenPHP/Octane + transformers-php (ONNX via FFI). Multi-arch (arm64 target).
# Single stage: the Wayfinder Vite plugin shells out to `php artisan` during the asset
# build, so PHP + Node must coexist at build time. node_modules is pruned afterwards.

FROM dunglas/frankenphp:1-php8.4-bookworm AS app

# System deps — spike-proven: libffi for ONNX-via-FFI, libsndfile1 + ffmpeg for audio (Whisper).
# Node 24 (NodeSource) builds the React/Inertia + Wayfinder assets.
RUN apt-get update && apt-get install -y --no-install-recommends \
      git unzip curl ca-certificates gnupg libffi-dev libsqlite3-dev libsndfile1 ffmpeg \
    && curl -fsSL https://deb.nodesource.com/setup_24.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions: ffi (ONNX Runtime), pdo_sqlite (data), pcntl (Octane), opcache.
RUN docker-php-ext-install -j"$(nproc)" ffi pdo_sqlite pcntl opcache bcmath \
    && { \
        echo "ffi.enable=1"; \
        echo "memory_limit=1024M"; \
        echo "opcache.enable=1"; \
        echo "opcache.enable_cli=1"; \
        echo "upload_max_filesize=100M"; \
        echo "post_max_size=100M"; \
    } > /usr/local/etc/php/conf.d/crunch.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY . .
# composer install also pulls the arm64 ONNX Runtime via the transformers plugin.
# Retry composer: it fetches dist zips from codeload.github.com, which intermittently
# returns HTTP 400 on a handful of packages per build (transient, per-request). Without
# a retry a single flaky download fails the whole image build. 5 attempts clears it.
RUN cp .env.example .env \
    && for i in 1 2 3 4 5; do \
         echo "composer install (attempt $i/5)"; \
         composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader && break; \
         if [ "$i" = 5 ]; then echo "composer install failed after 5 attempts" && exit 1; fi; \
         echo "transient failure (likely a codeload 400) — retrying in 10s"; sleep 10; \
       done \
    && npm ci \
    && npm run build \
    && rm -rf node_modules

# Runtime dirs (model cache + sqlite live on persistent volumes in prod).
RUN mkdir -p /data/models database \
      storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
    && chown -R www-data:www-data storage bootstrap/cache /data /app/database

ENV CRUNCH_MODEL_CACHE=/data/models \
    OCTANE_SERVER=frankenphp \
    APP_ENV=production

COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

EXPOSE 8000
ENTRYPOINT ["/usr/local/bin/entrypoint"]
