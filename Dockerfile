# syntax=docker/dockerfile:1
# crunch — FrankenPHP/Octane + transformers-php (ONNX via FFI). Multi-arch (arm64 target).

# --- Stage 1: build React/Inertia assets ---
FROM node:24-bookworm-slim AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build

# --- Stage 2: app runtime (FrankenPHP + Octane) ---
FROM dunglas/frankenphp:1-php8.4-bookworm AS app

# System deps — spike-proven: libffi for ONNX-via-FFI, libsndfile1 + ffmpeg for audio (Whisper).
RUN apt-get update && apt-get install -y --no-install-recommends \
      git unzip libffi-dev libsndfile1 ffmpeg \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions: ffi (ONNX Runtime), pdo_sqlite (data), pcntl (Octane), opcache.
RUN docker-php-ext-install -j"$(nproc)" ffi pdo_sqlite pcntl opcache \
    && { \
        echo "ffi.enable=1"; \
        echo "memory_limit=1024M"; \
        echo "opcache.enable=1"; \
        echo "opcache.enable_cli=1"; \
    } > /usr/local/etc/php/conf.d/crunch.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# App source first (artisan scripts need the skeleton), then PHP deps.
# composer install also pulls the arm64 ONNX Runtime via the transformers plugin.
COPY . .
RUN cp .env.example .env \
    && composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader

# Built frontend assets from stage 1.
COPY --from=assets /app/public/build ./public/build

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
