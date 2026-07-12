# Conteneur & déploiement — Dolibarr Maroc

Image de production : Laravel 13 (API + SPA React) servie par **nginx + php-fpm**
sur `$PORT` (8080 par défaut). Pensée pour **Cloud Run**, portable sur une VM.

## Build & test en local

```bash
docker build -t dolibarr-maroc .

# Lancer (base PostgreSQL joignable + APP_KEY obligatoires)
docker run --rm -p 8080:8080 \
  -e APP_KEY="base64:..." \
  -e APP_ENV=production -e APP_DEBUG=false \
  -e APP_URL=http://localhost:8080 \
  -e DB_CONNECTION=pgsql -e DB_HOST=... -e DB_PORT=5432 \
  -e DB_DATABASE=dolibarr -e DB_USERNAME=... -e DB_PASSWORD=... \
  -e RUN_MIGRATIONS=true \
  dolibarr-maroc
# -> http://localhost:8080  (santé : /up)
```

Générer une clé si besoin : `php artisan key:generate --show`.

## Variables d'environnement (prod)

| Variable | Rôle |
|---|---|
| `APP_KEY` | Clé de chiffrement (Secret Manager). **Obligatoire.** |
| `APP_ENV=production`, `APP_DEBUG=false` | Mode prod |
| `APP_URL` | URL publique (liens e-mail, reset mot de passe) |
| `DB_CONNECTION=pgsql`, `DB_HOST/PORT/DATABASE/USERNAME/PASSWORD` | Cloud SQL PostgreSQL |
| `MAIL_MAILER=smtp`, `MAIL_HOST/PORT/USERNAME/PASSWORD`, `MAIL_FROM_ADDRESS` | SMTP (Brevo/SendGrid…) |
| `FILESYSTEM_DISK=gcs` (+ creds GCS) | Fichiers persistants (le FS conteneur est éphémère) |
| `QUEUE_CONNECTION=database` | File d'attente (jobs) |
| `RUN_MIGRATIONS=true` | Lance `migrate --force` au démarrage (mono-instance) |

Les caches (`config`/`route`/`view`) et la découverte de packages sont régénérés
au démarrage (entrypoint), avec l'environnement runtime.

## Déploiement Cloud Run (europe-west9 / Paris)

```bash
PROJECT=mon-projet
REGION=europe-west9

# Build + push via Cloud Build vers Artifact Registry
gcloud builds submit --tag $REGION-docker.pkg.dev/$PROJECT/apps/dolibarr-maroc

# Déploiement (secrets via Secret Manager, connexion Cloud SQL)
gcloud run deploy dolibarr-maroc \
  --image $REGION-docker.pkg.dev/$PROJECT/apps/dolibarr-maroc \
  --region $REGION --allow-unauthenticated \
  --add-cloudsql-instances $PROJECT:$REGION:dolibarr-sql \
  --set-env-vars APP_ENV=production,APP_DEBUG=false,DB_CONNECTION=pgsql \
  --set-secrets APP_KEY=APP_KEY:latest,DB_PASSWORD=DB_PASSWORD:latest
```

### Migrations & jobs
- **Migrations** : soit `RUN_MIGRATIONS=true` (simple), soit un **Cloud Run Job**
  dédié exécutant `php artisan migrate --force` (recommandé en multi-instances).
- **File d'attente** : un 2e service Cloud Run `--min-instances=1` lançant
  `php artisan queue:work`, ou queue `database` + **Cloud Scheduler** qui déclenche
  le traitement. `schedule:run` (tâches planifiées) via **Cloud Scheduler**.

> Rappel : le code est validé compatible **PostgreSQL** (195/195 tests). Rejouer
> la suite sur Postgres : `php vendor/phpunit/phpunit/phpunit -c phpunit.pgsql.xml`.
