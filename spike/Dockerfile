# crunch v2 spike — arm64-linux, mirrors the production target (containerised Laravel).
FROM php:8.3-cli-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
      git unzip curl ca-certificates libffi-dev libsndfile1 ffmpeg \
    && rm -rf /var/lib/apt/lists/*

# FFI is the hard requirement for transformers-php (ONNX Runtime via FFI).
RUN docker-php-ext-install ffi \
 && echo "ffi.enable=1"     >  /usr/local/etc/php/conf.d/crunch.ini \
 && echo "memory_limit=-1" >>  /usr/local/etc/php/conf.d/crunch.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json ./
# Run install ON the arm64 target so transformers-libsloader pulls the aarch64 ONNX Runtime.
RUN composer install --no-interaction --no-progress

# Sample inputs (HF-hosted cats image; whisper.cpp jfk 16kHz mono wav).
RUN mkdir -p samples \
 && curl -fsSL -o samples/image.jpg https://huggingface.co/datasets/Xenova/transformers.js-docs/resolve/main/cats.jpg \
 && curl -fsSL -o samples/audio.wav https://github.com/ggerganov/whisper.cpp/raw/master/samples/jfk.wav

COPY probe.php ./
ENV HF_CACHE=/data/models
CMD ["php", "probe.php"]
