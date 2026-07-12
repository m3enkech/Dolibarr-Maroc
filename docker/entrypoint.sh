#!/bin/sh
set -e

# Port d'écoute (Cloud Run fournit $PORT ; 8080 par défaut).
: "${PORT:=8080}"
export PORT
envsubst '${PORT}' < /etc/nginx/nginx.conf.template > /etc/nginx/nginx.conf

# Découverte des packages + caches (bakés avec l'env runtime, pas au build).
php artisan package:discover --ansi || true
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Migrations optionnelles : mettre RUN_MIGRATIONS=true pour un déploiement
# simple (mono-instance). En multi-instances, préférer un job de migration
# séparé pour éviter les exécutions concurrentes.
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force
fi

exec supervisord -c /etc/supervisord.conf
