# Reprise des données Zoho Books

Import en **lecture seule** depuis Zoho Books vers Dolibarr. Rien n'est jamais
écrit côté Zoho.

## 1. Obtenir un jeton de rafraîchissement

Un code d'autorisation ne sert qu'une fois et expire en quelques minutes ; c'est
le **jeton de rafraîchissement** qu'il faut, et lui ne périme pas.

> ⚠️ **Le centre de données compte.** Un compte européen n'accepte ni
> `accounts.zoho.com` ni `zohoapis.com`. L'erreur renvoyée est alors
> `invalid_client`, ce qui laisse croire à tort que les identifiants sont faux.
> L'organisation Media Desk est européenne : tout passe par `.eu`.

### a. Créer une application « Self Client »

Sur <https://api-console.zoho.eu> → **Self Client** (et non « Server-based » :
il n'y a pas de navigateur dans une commande console, donc pas d'URL de retour).

Notez le **Client ID** et le **Client Secret**.

### b. Engendrer un code

Onglet **Generate Code**, avec :

| Champ | Valeur |
| --- | --- |
| Scope | `ZohoBooks.contacts.READ,ZohoBooks.invoices.READ,ZohoBooks.settings.READ` |
| Time Duration | 10 minutes |
| Scope Description | `Reprise Dolibarr` |

Choisissez l'organisation **Media Desk** si Zoho la demande.

### c. Échanger le code contre le jeton

Le code expire vite : enchaînez tout de suite. Depuis PowerShell, en
remplaçant les trois valeurs :

```powershell
$r = Invoke-RestMethod -Method POST -Uri "https://accounts.zoho.eu/oauth/v2/token" -Body @{
    grant_type    = "authorization_code"
    client_id     = "VOTRE_CLIENT_ID"
    client_secret = "VOTRE_CLIENT_SECRET"
    code          = "LE_CODE_QUE_VOUS_VENEZ_DE_GENERER"
}
$r.refresh_token
```

La réponse contient `refresh_token`. **Gardez-le pour vous** : il ouvre l'accès
à votre comptabilité, il n'a sa place que dans `.env`.

Si la réponse dit `invalid_code`, le code a expiré — régénérez-en un et
recommencez. S'il dit `invalid_client`, vérifiez que vous êtes bien sur `.eu`.

### d. Renseigner `.env`

```
ZOHO_CLIENT_ID=1000.xxxxxxxx
ZOHO_CLIENT_SECRET=xxxxxxxx
ZOHO_REFRESH_TOKEN=1000.xxxxxxxx
ZOHO_BOOKS_ORGANIZATION_ID=20080363566
```

`20080363566` est l'organisation **Media Desk** (MAD, Zoho One). Attention à ne
pas viser « Media Bookings Org », qui est en dollars.

## 2. Importer les tiers

**Toujours en simulation d'abord.** Elle lit tout, n'écrit rien, et rend
exactement le rapport de ce qui serait fait :

```bash
php artisan zoho:import-tiers admin@mediadesk.ma --simulation --journal=reprise-tiers.csv
```

Puis, une fois le journal relu :

```bash
php artisan zoho:import-tiers admin@mediadesk.ma --journal=reprise-tiers.csv
```

L'adresse est celle d'un utilisateur de l'entreprise de destination : sans
contexte d'entreprise, le cloisonnement multi-entreprises est fail-closed et
rien ne s'écrirait.

### Ce que fait le rapprochement

- **Sur l'ICE**, comparé sur ses chiffres seuls — espaces et tirets varient d'un
  système à l'autre. C'est l'identifiant officiel d'une entreprise marocaine :
  unique et stable, là où le nom produit un doublon à la première abréviation.
- **Un contact client ET fournisseur ne fait qu'un seul tiers**, portant les deux
  drapeaux. Books tient deux fiches, Dolibarr n'en veut qu'une.
- **Aucune donnée existante n'est écrasée.** Un tiers déjà saisi a pu être
  corrigé à la main ; on ne remplit que les champs vides et on lève les drapeaux
  de rôle. L'import est donc rejouable sans rien abîmer.
- **Sans ICE**, on retombe sur le nom normalisé (accents, casse et espaces
  neutralisés). Ces créations-là sont **signalées à part** : c'est là que
  d'éventuels doublons se cachent, et elles méritent un coup d'œil.
- Rien n'est écarté en silence : le journal CSV donne, ligne à ligne, l'action et
  sa raison.

## 3. Importer les articles

À jouer **après** les tiers et **avant** les factures : une ligne de facture qui
ne retrouve pas son article reste un simple libellé — elle s'affiche, mais elle
ne compte ni dans l'état du stock, ni dans le réappro fondé sur les ventes, ni
dans le détail par dépôt.

```bash
php artisan zoho:import-articles books@mediadesk.local --simulation --journal=reprise-articles.csv
php artisan zoho:import-articles books@mediadesk.local --journal=reprise-articles.csv
```

### Deux décisions qui ne se rattrapent pas

**Le SKU devient la référence Dolibarr** quand il tient dans la colonne (20
caractères) et qu'il est libre. « T-TN211 » est ce que les équipes cherchent et
dictent au téléphone ; leur servir « PR-0042 » à la place leur ferait perdre leur
catalogue. À défaut, la séquence maison reprend la main et le rapport le dit.

**Le type suit `product_type`, jamais `track_inventory`.** Books ne suit *aucun*
stock dans cette organisation : les 1 698 articles y sont tous à
`track_inventory: false`. S'y fier ferait de tout le catalogue des *services*,
c'est-à-dire des articles sans stock — et le type d'un produit ne se change plus
ensuite. `product_type` dit ce que l'article **est** (`goods` ou `service`),
indépendamment de ce que Books comptait.

Un taux de TVA hors barème marocain (0, 7, 10, 14, 20) est ramené à 20 % et
signalé : un taux exotique passerait à l'import mais rendrait la fiche produit
impossible à enregistrer ensuite.

## 4. Importer les factures

À jouer **en dernier**. Comptez du temps : Books ne donne pas les lignes dans la
liste, il faut un appel par facture.

```bash
php artisan zoho:import-factures books@mediadesk.local --simulation --journal=reprise-factures.csv
php artisan zoho:import-factures books@mediadesk.local --journal=reprise-factures.csv
```

| Option | Effet |
| --- | --- |
| `--simulation` | joue tout puis **annule** : le résultat annoncé est le vrai |
| `--depuis=2024-01-01` | ne reprend que les factures de ce jour ou après |
| `--avec-stock` | sort aussi la marchandise du stock (voir ci-dessous) |
| `--sans-paiements` | n'enregistre aucun règlement |

### Les partis pris

**La facture garde son numéro.** « MDK24-00110 » est imprimé sur le papier que le
client détient ; renuméroter en « FA-0001 » rendrait l'archive introuvable le
jour d'un contrôle.

**Le montant de Books fait foi, et ce qui ne tombe pas juste est refusé.** Après
écriture, le total du document est comparé à celui de Books ; au-delà d'un
centime d'arrondi par ligne, la facture est annulée et signalée. Un trou qu'on
voit vaut mieux qu'un chiffre d'affaires faux qu'on ne voit pas — et comme
l'import est rejouable, la pièce corrigée rentrera au passage suivant.

**La remise est déduite, pas lue.** Le champ `discount` de Books vaut tantôt un
pourcentage, tantôt une somme, selon un réglage d'organisation. Le rapport entre
le brut (quantité × prix) et le net (`item_total`) ne dépend, lui, d'aucun
réglage. Frais de port, ajustement et arrondi deviennent des lignes à part —
sans quoi le total ne tomberait jamais juste.

**La reprise ne passe pas par `valider()`.** Valider une facture vivante
déclenche des effets destinés au présent — sortie de stock, et demain
télédéclaration ou relance. Une reprise écrit la comptabilité et *rien d'autre*,
sauf demande explicite.

**Le stock ne bouge pas par défaut.** Books n'en suivait aucun : sortir quatre
ans de ventes sans le moindre achat en regard enfoncerait chaque article à des
milliers d'unités négatives. Le stock de départ s'établit par un **inventaire**,
à la date du jour — pas en rejouant l'histoire. `--avec-stock` pour passer outre.

**Les règlements suivent les factures.** Sans eux, les factures soldées depuis
des années s'afficheraient comme impayées et la balance âgée ne voudrait plus
rien dire. Le mode n'est pas dans Books : tout passe en « autre » (banque).

**Une facture déjà reprise ne coûte même pas son appel réseau.** Un import
interrompu se relance sans tout refaire.

### L'obstacle qui reste : la clôture comptable

`ComptaService::creerEcriture` refuse toute écriture datée dans un exercice clos,
et les factures Books remontent à **2021**. La commande lit la dernière année
clôturée *avant* de commencer et l'affiche : si un exercice est clos, toutes les
factures de cette année-là et d'avant seront refusées, une à une et sans
interrompre le reste. Il faut alors rouvrir l'exercice pour reprendre ces
années-là.

### La traçabilité

`tiers`, `produits` et `documents_vente` portent `source_systeme` + `source_id`,
sous index unique par entreprise. C'est ce qui rend les trois imports rejouables :
au second passage, le rapprochement ne devine plus — il reconnaît.

## 5. Ce que ce module ne fait pas

- Aucune écriture vers Zoho : l'échange est à sens unique.
- Aucune synchronisation continue. C'est une **reprise d'historique**, déclenchée
  à la main. Une synchronisation courante demanderait une tâche planifiée et une
  stratégie de conflits, qui n'existent pas ici.
- Les **factures d'achat** (`bills`), les devis et les commandes ne sont pas
  repris : seules les factures de vente le sont.
- Le **stock de départ** n'est pas repris — Books n'en tenait pas. Il s'établit
  par un inventaire.
