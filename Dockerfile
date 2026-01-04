# Image de base PHP (CLI) récente
FROM php:8.3-cli

# Installer dépendances système + extensions PHP
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libicu-dev \
        libzip-dev \
        libonig-dev \
        libxml2-dev \
        libpq-dev \
        # ✅ GD deps (pour imagecreatefromstring + resize)
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

# Installer Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Dossier de travail
WORKDIR /app

# Copier le projet
COPY . .

# prod env
ENV APP_ENV=prod
ENV APP_DEBUG=0

# install deps PHP (prod) - IMPORTANT: no scripts to avoid cache:clear during build
RUN composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist --no-scripts

# permissions
RUN chown -R www-data:www-data var public

# Port exposé
EXPOSE 8000

# Au démarrage, on génère le cache quand les env vars (R2_*) existent
CMD ["sh", "-lc", "php bin/console cache:clear --env=prod --no-debug && php bin/console cache:warmup --env=prod --no-debug && php bin/console asset-map:compile --env=prod && php -S 0.0.0.0:8000 -t public"]
