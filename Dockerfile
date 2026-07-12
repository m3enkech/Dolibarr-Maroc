# syntax=docker/dockerfile:1
#
# Image de production Dolibarr Maroc (Laravel 13 API + SPA React).
# Multi-étapes : build des assets Vite -> dépendances PHP -> runtime nginx+php-fpm.
# Sert le SPA + l'API sur $PORT (8080 par défaut, comme attendu par Cloud Run).
# Voir docker/README.md pour build & déploiement.

# --- Étape 1 : build des assets front (Vite -> public/build) ---
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY resources ./resources
COPY vite.config.js tsconfig.json ./
RUN npm run build

# --- Étape 2 : dépendances PHP (sans dev, autoloader optimisé) ---
FROM composer:2 AS vendor
WORKDIR /app
# 1) Deps seules (couche mise en cache tant que composer.lock ne change pas).
#    --ignore-platform-reqs : l'image composer n'a pas gd/pgsql/... mais l'image
#    runtime (étape 3) les fournit ; ici on ne fait que télécharger les paquets.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --prefer-dist --no-interaction --no-autoloader --ignore-platform-reqs
# 2) Code source complet puis autoloader optimisé (classmap complet app/+database/).
COPY . .
RUN composer dump-autoload --no-dev --optimize

# --- Étape 3 : runtime ---
FROM php:8.4-fpm-alpine AS runtime

# Extensions PHP requises par l'app (PostgreSQL, PDF/dompdf, Excel/PhpSpreadsheet, i18n).
RUN set -eux; \
    apk add --no-cache nginx supervisor gettext libpq icu-libs libzip libpng freetype libjpeg-turbo oniguruma; \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev icu-dev libzip-dev libpng-dev freetype-dev libjpeg-turbo-dev oniguruma-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" pdo_pgsql gd zip intl bcmath mbstring opcache; \
    apk del .build-deps

# Config PHP (opcache prod + limites) et services.
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/nginx.conf.template /etc/nginx/nginx.conf.template
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html

# Code applicatif + dépendances + assets buildés.
COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build

# Structure storage garantie + purge d'un éventuel manifeste de cache périmé
# (packages.php/services.php avec des providers de dev) + droits d'écriture.
RUN rm -f bootstrap/cache/*.php \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rw storage bootstrap/cache

EXPOSE 8080
ENTRYPOINT ["entrypoint.sh"]
