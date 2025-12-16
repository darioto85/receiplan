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

# Installer les dépendances PHP
RUN composer install --no-interaction --prefer-dist --no-progress

# Générer le cache en prod (safe)
RUN php bin/console cache:clear --env=prod || true

# Port exposé
EXPOSE 8000

# Serveur PHP interne
CMD ["php", "-S", "0.0.0.0:8000", "-t", "public"]
