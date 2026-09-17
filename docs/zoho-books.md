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

## 3. Points à traiter avant d'importer les factures

Trois obstacles identifiés dans le code, à régler avant le lot suivant.

**La clôture comptable.** `ComptaService::creerEcriture` refuse toute écriture
datée dans un exercice clos. Les factures Books vont de 2022 à 2026 : si un
exercice est clôturé côté Dolibarr, l'historique antérieur échouera à la
validation. En local sur une base neuve cela passe — c'est le genre de chose qui
marche à la répétition et casse en production.

**Le stock.** Valider une facture sort le stock, et une seule ligne observée fait
3 600 unités. Le levier propre : `StockService` ignore tout article dont le type
n'est pas `product`. Les articles que Books ne suit pas en stock (`stock_on_hand`
vide) doivent donc être créés en **service** dans Dolibarr — écritures
comptables générées, stock intact.

**La traçabilité.** `documents_vente` n'a aucun champ de référence externe,
seulement `notes`. Sans colonne dédiée (`source_externe` + `source_id`, avec
index unique), relancer l'import créerait des doublons.

## 4. Ce que ce module ne fait pas

- Aucune écriture vers Zoho : l'échange est à sens unique.
- Aucune synchronisation continue. C'est une **reprise d'historique**, déclenchée
  à la main. Une synchronisation courante demanderait une tâche planifiée et une
  stratégie de conflits, qui n'existent pas ici.
- Les articles et les factures ne sont pas encore repris.
