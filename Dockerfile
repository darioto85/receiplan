# Image de base PHP (CLI) récente
FROM php:8.3-cli

# Installer quelques dépendances système + extensions PHP utiles pour Symfony
RUN apt-get update && apt-get install -y \
        git unzip libicu-dev libzip-dev libonig-dev libxml2-dev \
    && docker-php-ext-install intl zip opcache \
    && rm -rf /var/lib/apt/lists/*

# Installer Composer (copié depuis l'image officielle)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Dossier de travail dans le conteneur
WORKDIR /app

# 👉 Copier TOUT le projet dans le conteneur
COPY . .

# 👉 Installer les dépendances maintenant que bin/console existe
RUN composer install --no-interaction --prefer-dist --no-progress

# (Optionnel) Générer le cache en prod
RUN php bin/console cache:clear --env=prod || true

# Exposer le port sur lequel on va servir Symfony
EXPOSE 8080

# Commande de démarrage : serveur PHP interne qui sert le dossier public/
CMD ["php", "-S", "0.0.0.0:8000", "-t", "public"]

