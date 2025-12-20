# Image de base PHP (CLI) récente
FROM php:8.3-cli

# Installer dépendances système + PostgreSQL + extensions PHP
RUN apt-get update && apt-get install -y \
        git \
        unzip \
        libicu-dev \
        libzip-dev \
        libonig-dev \
        libxml2-dev \
        libpq-dev \
    && docker-php-ext-install \
        intl \
        zip \
        opcache \
        pdo_pgsql \
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

# install deps PHP (prod)
RUN composer install --no-dev --optimize-autoloader --no-interaction

# warmup/cache + assets
RUN php bin/console cache:clear --env=prod \
 && php bin/console asset-map:compile --env=prod

# permissions (selon ton image)
RUN chown -R www-data:www-data var public

# Port exposé
EXPOSE 8000

# Serveur PHP interne
CMD ["php", "-S", "0.0.0.0:8000", "-t", "public"]
