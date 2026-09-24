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

## 2. Installer Docker sur le VPS

En SSH (`ssh root@85.31.237.190`, ou le compte que FASTPANEL vous a donné) :

```bash
curl -fsSL https://get.docker.com | sh
docker --version && docker compose version
```

FASTPANEL et Docker cohabitent sans problème tant que Docker ne réclame pas
80/443 — ce que notre configuration ne fait pas.

## 3. Déposer l'application

```bash
mkdir -p /opt/dolibarr && cd /opt/dolibarr
git clone https://github.com/m3enkech/Dolibarr-Maroc.git .
cp .env.vps.example .env.vps
```

Engendrer les deux secrets, puis les coller dans `.env.vps` :

```bash
openssl rand -base64 32                       # -> DB_PASSWORD
docker build -t dolibarr-maroc:vps . && docker run --rm dolibarr-maroc:vps php artisan key:generate --show   # -> APP_KEY
```

> **`APP_KEY` se pose une fois et ne se change plus.** Tout ce qui est chiffré
> en base l'est avec elle ; la remplacer rend ces données illisibles, sans
> message d'erreur au moment où on le fait.

Puis :

```bash
nano .env.vps      # APP_KEY, DB_PASSWORD, et le SMTP si vous l'avez
docker compose -f docker-compose.vps.yml up -d --build
docker compose -f docker-compose.vps.yml logs -f app     # Ctrl-C pour sortir
```

Vérifier que l'application répond **sur la boucle locale** :

```bash
curl -I http://127.0.0.1:8080/up      # attendu : HTTP 200
```

## 4. Publier le site dans FASTPANEL

Dans le panneau (les libellés varient selon la version) :

1. **Sites → Ajouter un site** → nom de domaine `crm.mediadesk.ma`.
2. Choisir un site **sans PHP** (site statique ou « proxy ») : c'est le
   conteneur qui exécute PHP, pas le panneau.
3. Ouvrir la **configuration nginx** du site et y placer :

```nginx
# EN PREMIER, et ce n'est pas un détail : la validation de Let's Encrypt dépose
# un fichier ici et vient le relire en HTTP. Si le proxy attrapait aussi cette
# adresse, la requête partirait vers l'application — qui répondrait 404. Le
# certificat s'émettrait quand même aujourd'hui (il est demandé AVANT que le
# proxy existe), puis le renouvellement échouerait EN SILENCE quatre-vingt-dix
# jours plus tard, et le site tomberait en « certificat expiré » un matin.
location ^~ /.well-known/acme-challenge/ {
    root /var/www/html;   # le chemin que FASTPANEL donne au site ; à vérifier
    allow all;
}

location / {
    proxy_pass http://127.0.0.1:8080;
    proxy_http_version 1.1;

    proxy_set_header Host              $host;
    proxy_set_header X-Real-IP         $remote_addr;
    proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
    # Sans cette ligne, Laravel se croit en http derrière le proxy et fabrique
    # des liens de réinitialisation en http — le mot de passe passerait en clair.
    proxy_set_header X-Forwarded-Proto $scheme;

    # Les imports et les PDF prennent leur temps ; 60 s par défaut coupe au
    # milieu et l'utilisateur voit une erreur de passerelle sans explication.
    proxy_read_timeout 300s;
    client_max_body_size 32m;
}
```

4. **SSL → Let's Encrypt**, émettre le certificat pour `crm.mediadesk.ma` et
   activer la redirection HTTP → HTTPS.

Le nuage gris de Cloudflare est ici une nécessité, pas un détail : en orange,
Let's Encrypt ne pourrait pas valider le domaine par HTTP.

## 5. Fermer la porte de derrière

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

## 6. Créer le compte et reprendre les données

```bash
cd /opt/dolibarr
alias art='docker compose -f docker-compose.vps.yml exec app php artisan'
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

## 7. Mettre à jour plus tard

```bash
cd /opt/dolibarr && git pull
docker compose -f docker-compose.vps.yml up -d --build
```

Les migrations passent au démarrage (`RUN_MIGRATIONS=true`), et les caches de
configuration, de routes et de vues sont refaits par l'entrypoint.

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
