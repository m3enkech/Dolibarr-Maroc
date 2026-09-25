#!/usr/bin/env bash
#
# Déploiement de Dolibarr Maroc sur un VPS géré par un panneau (FASTPANEL…).
#
#   bash scripts/deploy-vps.sh          # en root
#
# REJOUABLE : le relancer met à jour le code et redémarre, sans jamais
# régénérer un secret déjà posé.
#
# CE QUE CE SCRIPT NE FAIT JAMAIS, et c'est délibéré — la machine porte
# d'autres sites en production :
#   - il ne touche à AUCUNE configuration nginx, ni au panneau ;
#   - il ne redémarre ni nginx, ni PHP, ni MySQL, ni la messagerie ;
#   - il n'ouvre aucun port : l'application n'écoute que sur 127.0.0.1 ;
#   - il ne modifie pas le pare-feu ;
#   - il ne réinstalle jamais un moteur Docker déjà en service.
# Le branchement du site dans le panneau reste une étape à part, faite à la
# main et gardée par `nginx -t` : c'est là, et seulement là, qu'une erreur
# pourrait faire tomber un autre site.
#
# Relu par une revue adversariale avant sa première exécution : voir l'historique
# git pour les défauts qu'elle a trouvés.

set -Eeuo pipefail

DEPOT="https://github.com/m3enkech/Dolibarr-Maroc.git"
CIBLE="/opt/dolibarr"
COMPOSE=(docker compose --env-file .env.vps -f docker-compose.vps.yml)
# Doit correspondre à `name:` dans docker-compose.vps.yml.
VOLUME_BASE="dolibarr-maroc_db-data"
PORT_LOCAL=8080
ESPACE_MIN_GO=10
ATTENTE_SANTE_S=600   # premier démarrage : construction de l'image + migrations

rouge()  { printf '\033[31m%s\033[0m\n' "$*"; }
vert()   { printf '\033[32m%s\033[0m\n' "$*"; }
jaune()  { printf '\033[33m%s\033[0m\n' "$*"; }
etape()  { printf '\n\033[1m== %s\033[0m\n' "$*"; }
arret()  { rouge "ARRÊT : $*"; exit 1; }

# $BASH_COMMAND est le texte SOURCE de la commande, variables non développées :
# un secret passé en "$cle" y apparaît comme « "$cle" », jamais en clair.
trap 'rouge "Échec à la ligne $LINENO : $BASH_COMMAND"' ERR

# ----------------------------------------------------------------------------
etape "1/7  Vérifications préalables — rien n'est encore modifié"

[ "$(id -u)" -eq 0 ] || arret "à lancer en root (installation de Docker, écriture dans /opt)."

grep -qiE 'ubuntu|debian' /etc/os-release \
    || arret "système non prévu — ce script est écrit pour Ubuntu/Debian."

libre_go=$(df -BG --output=avail / | tail -1 | tr -dc '0-9')
[ "${libre_go:-0}" -ge "$ESPACE_MIN_GO" ] \
    || arret "${libre_go} Go libres sur /, il en faut au moins ${ESPACE_MIN_GO} (image + base)."
vert "Espace disque : ${libre_go} Go libres."

for outil in curl git openssl ss; do
    command -v "$outil" >/dev/null || arret "outil manquant : $outil"
done

# ----------------------------------------------------------------------------
etape "2/7  Docker"

if ! command -v docker >/dev/null; then
    jaune "Absent — installation par le script officiel de Docker."
    jaune "Il ajoute son dépôt apt et installe le moteur ; il ne touche ni à nginx, ni à PHP, ni à MySQL."
    # needrestart est branché sur apt sous Ubuntu 22.04. En mode non interactif
    # il prend sa réponse par défaut — redémarrer TOUS les services qui
    # chargent une bibliothèque mise à jour : php-fpm, nginx, mysqld, la
    # messagerie des sites voisins, sans rien afficher. On le fait taire : il
    # se contente de lister, et ne redémarre rien.
    curl -fsSL https://get.docker.com | NEEDRESTART_MODE=l NEEDRESTART_SUSPEND=1 sh
    systemctl enable --now docker
    vert "Installé : $(docker --version)"
elif ! docker compose version >/dev/null 2>&1; then
    # Docker est là mais sans compose v2. Relancer get.docker.com remplacerait
    # le paquet `docker.io` par `docker-ce` : apt désinstalle l'ancien moteur,
    # ce qui ARRÊTE tous les conteneurs de la machine — ceux des autres aussi.
    arret "Docker est installé mais sans le plugin compose v2. Installez-le à la main :
    apt-get install docker-compose-plugin     (moteur docker-ce)
    apt-get install docker-compose-v2         (moteur docker.io d'Ubuntu)
  Je ne réinstalle pas un moteur qui est peut-être déjà en service."
else
    vert "Déjà installé : $(docker --version)"
fi

docker compose version >/dev/null || arret "le plugin compose est absent."
docker info >/dev/null 2>&1 \
    || arret "le démon Docker ne répond pas — voir : systemctl status docker"

# État du pare-feu, À TITRE D'INFORMATION : on n'y touche pas.
if command -v ufw >/dev/null; then
    jaune "Pare-feu ufw : $(ufw status 2>/dev/null | head -1)"
fi

# ----------------------------------------------------------------------------
etape "3/7  Code source"

if [ -d "$CIBLE/.git" ]; then
    cd "$CIBLE"
    # Une modification faite à la main sur le serveur ne doit pas être écrasée
    # en silence : on s'arrête et on le dit.
    if ! git diff --quiet || ! git diff --cached --quiet; then
        arret "des fichiers suivis ont été modifiés sur le serveur ($CIBLE) — à régler à la main avant de mettre à jour."
    fi
    git fetch --quiet origin
    git merge --ff-only --quiet "origin/$(git rev-parse --abbrev-ref HEAD)" \
        || arret "la mise à jour n'est pas une avance rapide — historique divergent sur le serveur."
    vert "Mis à jour : $(git log -1 --format='%h %s')"
else
    if [ -e "$CIBLE" ] && [ -n "$(ls -A "$CIBLE" 2>/dev/null)" ]; then
        arret "$CIBLE existe et n'est pas vide, sans être un dépôt git — je ne l'écrase pas."
    fi
    git clone --quiet "$DEPOT" "$CIBLE"
    cd "$CIBLE"
    vert "Cloné : $(git log -1 --format='%h %s')"
fi

# Le dossier contient .env.vps et, surtout, les dumps COMPLETS de la base. En
# 0755, n'importe quel compte local — dont l'utilisateur PHP des sites voisins —
# pouvait y entrer : une seule faille WordPress suffisait à emporter la base.
#
# ⚠️ On ferme le DOSSIER, pas ses fichiers. Un `umask 077` global serait une
# erreur grave : `COPY . .` recopie les MODES dans l'image, et un code en 0600
# deviendrait illisible pour www-data — erreur 500 sur toutes les pages. Le mode
# du dossier racine, lui, n'est pas recopié.
chmod 700 "$CIBLE"
mkdir -p "$CIBLE/sauvegardes"
chmod 700 "$CIBLE/sauvegardes"

# ----------------------------------------------------------------------------
etape "4/7  Secrets (.env.vps)"

env_neuf=0
if [ ! -f .env.vps ]; then
    cp .env.vps.example .env.vps
    env_neuf=1
fi
chmod 600 .env.vps

# LE piège de ce script : .env.vps a disparu, mais la base existe encore. En
# engendrer un neuf fabriquerait un APP_KEY et un mot de passe qui ne
# correspondent plus à rien — la base refuserait la connexion, et tout ce qui
# est chiffré deviendrait illisible. On s'arrête AVANT d'écrire quoi que ce soit.
if [ "$env_neuf" -eq 1 ] && docker volume inspect "$VOLUME_BASE" >/dev/null 2>&1; then
    rm -f .env.vps
    arret "la base existe déjà (volume $VOLUME_BASE) mais .env.vps a disparu.
  N'en engendrez PAS un nouveau : restaurez l'ancien. Ses secrets sont les seuls
  qui ouvrent cette base et déchiffrent ses données."
fi

# On ne remplit QUE les valeurs vides. Un APP_KEY déjà posé n'est JAMAIS
# régénéré : tout ce qui est chiffré en base l'a été avec lui.
#
# Le script sed passe par l'ENTRÉE STANDARD, écrit par `printf` — une commande
# interne du shell, qui ne crée pas de processus. En argument de `sed`, le
# secret serait lisible par tout compte local dans /proc/<pid>/cmdline le temps
# de l'exécution.
if grep -qE '^APP_KEY=$' .env.vps; then
    # Même format que `php artisan key:generate` : 32 octets aléatoires en base64.
    cle="base64:$(openssl rand -base64 32)"
    printf 's|^APP_KEY=$|APP_KEY=%s|\n' "$cle" | sed -i -f - .env.vps
    unset cle
    vert "APP_KEY engendrée (non affichée)."
else
    vert "APP_KEY déjà présente — conservée."
fi

if grep -qE '^DB_PASSWORD=$' .env.vps; then
    # Hexadécimal : ni « / », ni « + », ni « = », qui cassent une chaîne de
    # connexion.
    mdp="$(openssl rand -hex 24)"
    printf 's|^DB_PASSWORD=$|DB_PASSWORD=%s|\n' "$mdp" | sed -i -f - .env.vps
    unset mdp
    vert "DB_PASSWORD engendré (non affiché)."
else
    vert "DB_PASSWORD déjà présent — conservé."
fi

chmod 600 .env.vps

grep -qE '^APP_KEY=base64:.{40,}$' .env.vps || arret "APP_KEY invalide dans .env.vps."
grep -qE '^DB_PASSWORD=.{16,}$'     .env.vps || arret "DB_PASSWORD absent ou trop court dans .env.vps."

if grep -qE '^MAIL_MAILER=log$' .env.vps; then
    jaune "ATTENTION : MAIL_MAILER=log — aucun courriel ne partira."
    jaune "  La récupération de mot de passe échouera EN SILENCE. À régler avant d'ouvrir le service."
fi

# ----------------------------------------------------------------------------
etape "5/7  Configuration et port local"

# La configuration d'abord : si elle est fausse, tout ce qui suit échouerait
# avec un message qui ne dirait pas pourquoi.
"${COMPOSE[@]}" config --quiet || arret "docker-compose.vps.yml invalide avec ce .env.vps."

# Au premier lancement, rien d'autre ne doit occuper le port. Aux suivants,
# c'est notre propre conteneur qui l'occupe — ce n'est pas un conflit.
nos_conteneurs=$("${COMPOSE[@]}" ps -q | wc -l)
if [ "$nos_conteneurs" -eq 0 ] && ss -ltn "sport = :$PORT_LOCAL" | grep -q LISTEN; then
    arret "le port $PORT_LOCAL est déjà pris par autre chose : $(ss -ltnp "sport = :$PORT_LOCAL" | tail -1)"
fi
vert "Port $PORT_LOCAL disponible."

# ----------------------------------------------------------------------------
etape "6/7  Construction et démarrage — le premier passage prend plusieurs minutes"

"${COMPOSE[@]}" up -d --build --remove-orphans

# ----------------------------------------------------------------------------
etape "7/7  Santé de l'application"

id_app=$("${COMPOSE[@]}" ps -aq app)
[ -n "$id_app" ] || arret "le conteneur app n'a pas été créé."

# Point de départ du compteur de redémarrages : une hausse pendant l'attente
# veut dire que l'entrypoint plante (une migration, le plus souvent) et que le
# conteneur tourne en boucle — inutile d'attendre dix minutes pour le savoir.
redemarrages_initiaux=$(docker inspect -f '{{.RestartCount}}' "$id_app")

# Les migrations tournent au démarrage du conteneur, avant le serveur web :
# `up -d` rend la main avant que l'application réponde.
debut=$(date +%s)
until curl -fsS -o /dev/null --max-time 5 "http://127.0.0.1:$PORT_LOCAL/up"; do
    statut=$(docker inspect -f '{{.State.Status}}' "$id_app" 2>/dev/null || echo inconnu)
    redemarrages=$(docker inspect -f '{{.RestartCount}}' "$id_app" 2>/dev/null || echo 0)

    if [ "$statut" = "exited" ] || [ "$redemarrages" -gt "$redemarrages_initiaux" ]; then
        printf '\n'
        rouge "Le conteneur app a planté pendant son démarrage (statut : $statut, redémarrages : $redemarrages)."
        rouge "Cause la plus probable : une migration. Derniers journaux :"
        "${COMPOSE[@]}" logs --tail=80 app || true
        exit 1
    fi

    if [ $(( $(date +%s) - debut )) -ge "$ATTENTE_SANTE_S" ]; then
        printf '\n'
        rouge "L'application ne répond pas après ${ATTENTE_SANTE_S} s. Derniers journaux :"
        "${COMPOSE[@]}" logs --tail=80 app || true
        exit 1
    fi

    printf '.'
    sleep 5
done
printf '\n'
vert "L'application répond sur http://127.0.0.1:$PORT_LOCAL/up"

# LE contrôle qui compte : le port ne doit écouter QUE sur la boucle locale.
# Ouvert sur toutes les interfaces, n'importe qui contournerait nginx et
# forgerait son adresse IP — la limitation de débit ne servirait plus à rien.
if ss -ltn "sport = :$PORT_LOCAL" | awk 'NR>1 {print $4}' | grep -vqE '^(127\.0\.0\.1|\[::1\]):'; then
    rouge "DANGER : le port $PORT_LOCAL écoute AU-DELÀ de la boucle locale :"
    ss -ltn "sport = :$PORT_LOCAL"
    exit 1
fi
vert "Le port $PORT_LOCAL n'écoute que sur 127.0.0.1 — nginx est le seul chemin d'entrée."

# Le montant en toutes lettres de chaque facture (article 145 du CGI) repose sur
# les données ICU françaises. Sur Alpine, sans `icu-data-full`, il retombe sur
# l'anglais SANS AUCUNE ERREUR : on le vérifie ici, sur l'image réellement
# construite, plutôt que de le découvrir sur la facture d'un client.
en_lettres=$("${COMPOSE[@]}" exec -T app php -r \
    'echo (new NumberFormatter("fr", NumberFormatter::SPELLOUT))->format(720);' 2>&1 || true)
if [ "$en_lettres" != "sept cent vingt" ]; then
    rouge "DANGER : le montant en lettres ne sort pas en français."
    rouge "  Attendu « sept cent vingt », obtenu « $en_lettres »."
    rouge "  Les factures porteraient une mention légale en anglais. Vérifiez icu-data-full dans le Dockerfile."
    exit 1
fi
vert "Montant en lettres : « $en_lettres » — les données ICU françaises sont bien là."

"${COMPOSE[@]}" ps

cat <<'SUITE'

== Reste à faire, À LA MAIN — ce script n'y touche volontairement pas

1. Dans FASTPANEL, sur le site crm.mediadesk.ma, remplacer la configuration
   nginx par le bloc de docs/deploiement-vps.md (proxy vers 127.0.0.1:8080,
   AVEC l'exception /.well-known/acme-challenge/ placée avant).

2. Tester AVANT de recharger — si le test échoue, rien n'est rechargé et les
   autres sites continuent de tourner :

       nginx -t && systemctl reload nginx

3. Créer le compte sur https://crm.mediadesk.ma, puis rejouer la reprise
   Zoho (voir docs/deploiement-vps.md, section 6).
SUITE
