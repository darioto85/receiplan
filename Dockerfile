FROM php:8.3-cli

# =========================
# System deps + PHP extensions
# =========================
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

# =========================
# Composer
# =========================
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# =========================
# App files
# =========================
WORKDIR /app
COPY . .

# =========================
# Symfony prod env
# =========================
ENV APP_ENV=prod
ENV APP_DEBUG=0

# =========================
# Composer install (NO scripts)
# =========================
# Important: évite cache:clear pendant le build (R2_* pas encore présents)
RUN composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction \
    --prefer-dist \
    --no-scripts

# =========================
# Runtime directories (var/ ignoré par .dockerignore)
# =========================
RUN mkdir -p var/cache var/log

# =========================
# Permissions
# =========================
RUN chown -R www-data:www-data var public

# =========================
# Runtime
# =========================
EXPOSE 8000

# Cache + assets AU RUNTIME (env vars disponibles)
CMD ["sh", "-lc", "\
    php bin/console cache:clear --env=prod --no-debug && \
    php bin/console cache:warmup --env=prod --no-debug && \
    php bin/console asset-map:compile --env=prod && \
    php -S 0.0.0.0:8000 -t public \
"]
