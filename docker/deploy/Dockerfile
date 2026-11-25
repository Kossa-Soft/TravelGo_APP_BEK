# syntax=docker/dockerfile:1
FROM composer:2 AS composer-bin

FROM node:22-bookworm-slim AS assets
WORKDIR /app
COPY package.json package-lock.json .npmrc ./
RUN --mount=type=cache,target=/root/.npm npm ci --ignore-scripts --no-audit --no-fund
COPY resources resources
COPY vite.config.js ./
RUN npm run build

FROM php:8.5-fpm-bookworm AS php-runtime
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libjpeg62-turbo-dev libpng-dev libwebp-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pcntl gd exif \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && printf '%s\n' 'upload_max_filesize=10M' 'post_max_size=16M' > "$PHP_INI_DIR/conf.d/travelgo-uploads.ini" \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www/html

FROM php-runtime AS dependencies
COPY --from=composer-bin /usr/bin/composer /usr/local/bin/composer
RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/tmp/composer \
    COMPOSER_CACHE_DIR=/tmp/composer composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --no-autoloader
COPY app app
COPY database database
RUN composer dump-autoload --no-dev --classmap-authoritative --no-interaction --no-scripts

FROM php-runtime AS app
COPY --from=dependencies /var/www/html/vendor vendor
COPY app app
COPY bootstrap bootstrap
COPY config config
COPY database database
COPY public public
COPY resources resources
COPY routes routes
COPY storage storage
COPY artisan composer.json ./
COPY --from=assets /app/public/build public/build
COPY --chmod=0755 docker/deploy/start-app /usr/local/bin/start-travelgo
RUN mkdir -p storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views \
    && chmod -R a+rX app bootstrap config database public resources routes vendor \
    && chmod 0755 artisan \
    && chmod 0644 composer.json \
    && chown -R www-data:www-data storage bootstrap/cache
USER www-data
ENTRYPOINT ["start-travelgo"]
CMD ["php-fpm"]

FROM caddy:2-alpine AS web
COPY docker/deploy/Caddyfile /etc/caddy/Caddyfile
COPY --from=app /var/www/html/public /var/www/html/public
