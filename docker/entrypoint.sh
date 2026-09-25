#!/bin/sh
set -e

# Port d'écoute (Cloud Run fournit $PORT ; 8080 par défaut).
: "${PORT:=8080}"
export PORT
envsubst '${PORT}' < /etc/nginx/nginx.conf.template > /etc/nginx/nginx.conf

# Le journal doit exister, au nom de www-data, AVANT la première commande
# artisan. Ce script tourne en root : sans cela, le premier message écrit
# (une migration qui échoue, typiquement) crée laravel.log en root:root 0644
# dans un volume PERSISTANT — et PHP-FPM, qui tourne en www-data, ne peut plus
# rien y journaliser, même après correction. Root qui écrit dans un fichier
# appartenant à www-data n'en change pas le propriétaire : c'est ce qui rend
# l'ordre important. Rejoué à chaque démarrage, cela répare aussi un volume
# déjà abîmé.
mkdir -p storage/logs
touch storage/logs/laravel.log
chown www-data:www-data storage/logs storage/logs/laravel.log

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

# Les caches et vues compilés ci-dessus l'ont été en root : on les rend à
# www-data, qui doit pouvoir les réécrire (une vue recompilée à la volée, un
# cache vidé). Sans cela l'application marche… jusqu'à la première écriture.
chown -R www-data:www-data storage/logs storage/framework bootstrap/cache

exec supervisord -c /etc/supervisord.conf
