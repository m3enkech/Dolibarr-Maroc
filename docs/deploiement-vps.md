# Déploiement sur un VPS Hostinger — `crm.mediadesk.ma`

Second environnement, **indépendant de Cloud Run** qui reste en production.
Base PostgreSQL propre au VPS, données issues de la reprise Zoho Books.

## Ce que le terrain impose

Trois constats mesurés sur `85.31.237.190`, et chacun oriente la méthode :

| Constat | Conséquence |
| --- | --- |
| Le VPS tourne sous **FASTPANEL** | Le panneau possède nginx et les ports 80/443. On se met **derrière** lui, jamais en face. |
| `crm.mediadesk.ma` est en **DNS only** (nuage gris) | C'est le VPS qui doit présenter le certificat. Let's Encrypt via le panneau. |
| L'application fait confiance aux en-têtes de proxy | Le conteneur ne doit écouter **que** sur `127.0.0.1`. Voir l'avertissement plus bas. |

**Pourquoi Docker plutôt qu'un site PHP natif du panneau.** L'application exige
PHP 8.4 avec `pdo_pgsql`, `gd`, `zip`, `intl`, `bcmath` et `opcache`. `intl`
n'est pas décoratif : c'est lui qui écrit le montant en toutes lettres exigé par
l'article 145 du CGI sur chaque facture. Une extension manquante ne se voit pas
à l'installation — elle se voit le jour où un client demande sa facture. L'image
Docker porte exactement le même runtime que celui qu'on teste, donc la question
ne se pose pas.

---

## 1. Préparer le code (depuis votre poste)

Le VPS ira chercher le code sur GitHub ; il faut donc qu'il y soit.

```bash
cd "D:/Claude/Dolibarr Maroc"
git push origin master
```

## 2. Installer et démarrer — une seule commande

En SSH, **en root** (`ssh root@85.31.237.190`) :

```bash
git clone https://github.com/m3enkech/Dolibarr-Maroc.git /opt/dolibarr && bash /opt/dolibarr/scripts/deploy-vps.sh
```

Le script fait tout ce qui suit, s'arrête au moindre doute, et peut être
relancé sans risque — c'est aussi la commande de mise à jour (section 6) :

1. vérifie l'espace disque et les outils ;
2. installe Docker s'il est absent — **et refuse** de réinstaller un moteur
   déjà présent sans compose v2 : `get.docker.com` remplacerait le paquet
   `docker.io` d'Ubuntu et arrêterait tous les conteneurs de la machine ;
3. clone ou met à jour le code, et ferme `/opt/dolibarr` aux autres comptes
   locaux (`chmod 700`) : il contiendra les dumps complets de la base ;
4. engendre `APP_KEY` et `DB_PASSWORD` sur le serveur, **sans jamais les
   afficher**, et **sans jamais régénérer** une valeur déjà posée ;
5. construit l'image et démarre ;
6. attend que l'application réponde, et détecte un conteneur qui plante en
   boucle au lieu d'attendre dix minutes pour rien ;
7. vérifie que le port 8080 n'écoute **que** sur `127.0.0.1`, et que le montant
   en toutes lettres sort bien **en français**.

### Les pièges qu'il évite — à connaître si vous faites à la main

**`--env-file .env.vps` sur CHAQUE commande Compose.** Le fichier interpole
`${DB_PASSWORD}` à l'analyse, depuis le shell ou `--env-file` — jamais depuis
`env_file:`. Sans lui, Compose s'arrête sur « DB_PASSWORD manquant ».

**`needrestart` coupe les sites voisins.** Sous Ubuntu 22.04 il est branché sur
apt ; en mode non interactif il redémarre sans rien dire tous les services qui
chargent une bibliothèque mise à jour — PHP, nginx, MySQL, la messagerie. D'où :

```bash
curl -fsSL https://get.docker.com | NEEDRESTART_MODE=l NEEDRESTART_SUSPEND=1 sh
```

**`buildx` manque avec le Docker d'Ubuntu.** Le paquet `docker.io` n'embarque pas
le plugin de construction ; Compose ne peut alors pas construire l'image. Il
s'ajoute seul, sans toucher au moteur ni aux conteneurs en service :

```bash
NEEDRESTART_MODE=l NEEDRESTART_SUSPEND=1 apt-get install -y docker-buildx
```

**`.env.vps` en 0600.** Il contient la clé de chiffrement et le mot de passe
de la base : `chmod 600 .env.vps`.

**`APP_KEY` se pose une fois et ne se change plus.** Tout ce qui est chiffré en
base l'est avec elle ; la remplacer rend ces données illisibles, sans message
d'erreur au moment où on le fait. Pour l'engendrer à la main :
`echo "base64:$(openssl rand -base64 32)"`.

> ⚠️ `docker run … php artisan key:generate --show` **ne marche pas** : l'entrypoint
> de l'image ignore ses arguments et lance le serveur. La commande ne rend
> jamais de clé.

**Si `.env.vps` disparaît alors que la base existe**, n'en engendrez surtout
pas un neuf : ses secrets sont les seuls qui ouvrent cette base. Le script
s'arrête dans ce cas précis au lieu de fabriquer des secrets qui ne
correspondent plus à rien.

## 3. Publier le site dans FASTPANEL

**Par le panneau, jamais en éditant les fichiers.** FASTPANEL régénère la
configuration nginx de chaque site à partir de sa propre base — au
renouvellement du certificat, notamment. Une modification faite à la main dans
`/etc/nginx/fastpanel2-available/` fonctionne… jusqu'au premier renouvellement,
quatre-vingt-dix jours plus tard, où le site retombe sans prévenir sur la page
d'attente du panneau.

1. **Sites → Ajouter un site** → nom de domaine `crm.mediadesk.ma`, puis
   **SSL → Let's Encrypt** et redirection HTTP → HTTPS. *Sans* le `www.` :
   `www.crm.mediadesk.ma` n'a pas d'enregistrement DNS, et sa validation fait
   échouer tout le certificat.
2. Dans les **paramètres du site**, section **Handler / Gestionnaire**, choisir
   **Reverse Proxy** et donner l'adresse `http://127.0.0.1:8080`. C'est le mode
   qu'utilise déjà `api.mediadesk.ma` sur ce serveur.
3. Décocher **« Use Nginx for static files »** si l'option est proposée : c'est
   le conteneur qui sert les fichiers de l'application, pas le dossier du site.

Ce qu'on n'a **pas** à faire, et qui casserait tout :

- **Ne pas ajouter de bloc `/.well-known/acme-challenge/`.** FASTPANEL l'inclut
  déjà dans chaque site (`/etc/nginx/fastpanel2-includes/letsencrypt.conf`, en
  `^~`, donc prioritaire sur le proxy). Un second bloc identique fait échouer
  `nginx -t` sur « duplicate location ».
- **Ne pas se contenter de remplacer `location /`.** Le modèle de site PHP porte
  aussi un bloc pour les `.js`, `.css`, `.pdf`… qui passe AVANT `location /` et
  renvoie la page d'attente du panneau : l'application s'afficherait sans style
  ni JavaScript. Le mode Reverse Proxy, lui, renvoie ces fichiers au conteneur.

Les en-têtes de proxy sont déjà justes : le mode Reverse Proxy inclut
`/etc/nginx/proxy_params`, qui transmet `X-Forwarded-Proto`. Sans lui, Laravel
se croirait en http et fabriquerait des liens de réinitialisation en http.

Le nuage de Cloudflare reste **gris** : c'est le VPS qui présente son propre
certificat, et nginx voit l'adresse réelle des visiteurs — celle dont dépend la
limitation de débit.

## 4. Fermer la porte de derrière

> ⚠️ **Le point à ne pas rater.** L'application fait confiance aux en-têtes de
> proxy (`trustProxies` dans `bootstrap/app.php`) — c'est ce qui lui permet de
> voir la vraie adresse du visiteur derrière nginx. Si le port 8080 est
> joignable de l'extérieur, n'importe qui peut la contourner et **annoncer
> l'adresse IP de son choix**. Il videra alors les compteurs de la limitation de
> débit, ou s'en servira pour enfermer dehors un utilisateur légitime.
>
> `docker-compose.vps.yml` publie `127.0.0.1:8080:8080` et non `8080:8080` :
> c'est ce qui l'interdit. Vérifiez-le **depuis votre poste**, pas depuis le VPS :

```bash
curl -m 10 http://85.31.237.190:8080/up     # attendu : connexion refusée
```

Si ça répond, le port est ouvert : corrigez la publication, ou fermez 8080 au
pare-feu.

## 5. Créer le compte et reprendre les données

```bash
cd /opt/dolibarr
alias art='docker compose --env-file .env.vps -f docker-compose.vps.yml exec -u www-data app php artisan'
```

Créer l'entreprise et son administrateur depuis l'interface
(`https://crm.mediadesk.ma` → *Créer un compte*), puis rejouer la reprise —
**dans cet ordre**, les factures ayant besoin de leurs clients et de leurs
articles :

```bash
art zoho:import-tiers    votre@email.ma --simulation
art zoho:import-tiers    votre@email.ma
art zoho:import-articles votre@email.ma
art zoho:import-factures votre@email.ma --simulation
art zoho:import-factures votre@email.ma
```

> L'import des factures demande **un appel réseau par pièce** : comptez une
> heure pour 1 300 factures. Lancez-le dans un `screen` ou un `tmux`, sinon une
> coupure SSH l'interrompt. Ce n'est pas grave — il est rejouable et ne
> recrée jamais ce qu'il a déjà importé — mais c'est une heure à refaire.

Puis remettre les journaux comptables en ordre, la reprise datant les pièces de
leur exercice mais les numérotant dans celui de la saisie :

```bash
art compta:renumeroter votre@email.ma --simulation
art compta:renumeroter votre@email.ma --force
```

## 6. Mettre à jour plus tard

La même commande qu'à l'installation — elle est faite pour être rejouée :

```bash
bash /opt/dolibarr/scripts/deploy-vps.sh
```

Elle récupère le code, reconstruit l'image, redémarre, et refait toutes les
vérifications. Les migrations passent au démarrage (`RUN_MIGRATIONS=true`). Elle
refuse de s'exécuter si des fichiers suivis ont été modifiés à la main sur le
serveur, plutôt que de les écraser en silence.

## Ce qu'il reste à surveiller

- **Les sauvegardes existent, mais restent sur le VPS.** Un dump par jour dans
  `/opt/dolibarr/sauvegardes`, gardé quatorze jours. Une sauvegarde qui vit sur
  la machine qu'elle protège ne protège de rien : recopiez-la ailleurs
  (`rclone`, `scp` depuis votre poste, ou les snapshots Hostinger).
- **`MAIL_MAILER=log` ne fait rien partir.** La récupération de mot de passe et
  les invitations échouent alors **en silence** : l'utilisateur lit
  « e-mail envoyé » et n'attendra jamais rien. À régler avant d'ouvrir le
  service à quelqu'un d'autre que vous.
- **Deux productions qui divergent.** Cloud Run continue de tourner avec ses
  propres données. Décidez laquelle fait foi, et dites-le à ceux qui s'en
  servent — sinon deux personnes saisiront la même facture à deux endroits.
