FROM php:8.3-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libicu-dev \
        libzip-dev \
        libonig-dev \
        libxml2-dev \
        libpq-dev \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        intl \
        zip \
        opcache \
        pdo_pgsql \
        gd \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . .

ENV APP_ENV=prod
ENV APP_DEBUG=0

# composer sans scripts (évite cache:clear au build)
RUN composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction \
    --prefer-dist \
    --no-scripts

# var/ est ignoré par .dockerignore => on le recrée
RUN mkdir -p var/cache var/log assets/vendor

# permissions
RUN chown -R www-data:www-data var public assets

EXPOSE 8000

# ✅ Runtime: cache + importmap + asset-map, puis serveur
CMD ["sh", "-lc", "\
    php bin/console cache:clear --env=prod --no-debug && \
    php bin/console cache:warmup --env=prod --no-debug && \
    php bin/console importmap:install --no-interaction && \
    php bin/console asset-map:compile --env=prod && \
    php -S 0.0.0.0:8000 -t public \
"]
