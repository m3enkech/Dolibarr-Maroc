# Dolibarr Maroc — Documentation du projet

> ERP / CRM SaaS multi-entreprises inspiré de Dolibarr, conçu pour le marché
> marocain. Ce document retrace l'intégralité de ce qui a été construit :
> historique, architecture, modules, fonctionnalités et couverture de tests.

**Dépôt** : https://github.com/m3enkech/Dolibarr-Maroc
**Dernière mise à jour** : 2026-07-05

---

## 1. Vue d'ensemble

Dolibarr Maroc est un ERP SaaS où plusieurs entreprises (tenants) cohabitent sur
une même instance, chacune totalement isolée. Il couvre aujourd'hui le cycle
complet **achats → stock → ventes → comptabilité**, avec une comptabilité conforme
au Plan Comptable Général Marocain (CGNC).

### Chiffres clés

| Indicateur | Valeur |
|---|---|
| Modules métier | 6 (Tiers, Catalogue, Ventes, Achats, Stock, Comptabilité) |
| Migrations base de données | 25 |
| Endpoints API (v1) | 60 |
| Tests automatisés | 69 tests / 467 assertions (100 % au vert) |
| Frontend | SPA React 19 + TypeScript |

### Stack technique

| Couche | Technologie |
|---|---|
| Backend | Laravel 13 (PHP 8.4), API REST, monolithe modulaire |
| Authentification | Laravel Sanctum (tokens Bearer) |
| Frontend | React 19 + TypeScript, Vite, React Router, TanStack Query |
| Style | Tailwind CSS 4 |
| PDF | barryvdh/laravel-dompdf |
| Base de données | SQLite (dev) — PostgreSQL visé en production |

---

## 2. Architecture

### 2.1 Principe : monolithe modulaire

Plutôt que des microservices (inadaptés à un ERP où les modules sont fortement
couplés) ou le code procédural de Dolibarr, chaque domaine métier est un **module
autonome** sous `app/Modules/`, au-dessus d'un **noyau partagé** `app/Core/`.

```
app/
├── Core/
│   ├── Tenancy/          Multi-tenant : 1 BDD, scope automatique par tenant_id
│   │   ├── Tenant.php            (entreprise)
│   │   ├── TenantContext.php     (tenant courant, scoped par requête)
│   │   ├── TenantScope.php       (global scope Eloquent + résolution du tenant)
│   │   ├── BelongsToTenant.php   (trait posé sur tout modèle métier)
│   │   └── SetTenantContext.php  (middleware « tenant », après auth:sanctum)
│   ├── Sequences/        Numérotation par tenant et par année (CL-2026-00001…)
│   └── Auth/             register (tenant + admin), login, me, logout
└── Modules/
    ├── Tiers/            Clients et fournisseurs
    ├── Catalogue/        Produits et services
    ├── Ventes/           Devis → commande → facture, paiements, PDF
    ├── Achats/           Commande → réception → facture fournisseur
    ├── Stock/            Entrepôts, mouvements, niveaux
    └── Compta/           Plan PCGM, écritures, lettrage, clôture, immobilisations

resources/js/            SPA React (TypeScript)
├── app.tsx              Entrée : router + React Query
├── lib/                 client axios (token + 401), contexte d'auth, formatage MAD
├── components/Layout    Shell : sidebar modules + topbar
└── pages/               Une page (ou dossier) par module
```

Chaque module suit le même patron interne :
`Models/ · Http/{Controllers,Requests,Resources} · Services/ · Events/ · Listeners/ · routes.php · XxxServiceProvider.php`

### 2.2 Les 6 règles de conception

1. **Modules découplés** — un module ne référence jamais les classes internes d'un
   autre. Seuls les modèles Eloquent sont l'API publique d'un module.
2. **Communication par événements** — l'inter-module passe par des événements
   Laravel, jamais par appel direct (voir §7).
3. **La logique métier vit dans les `Services/`** — les contrôleurs ne font que
   valider (FormRequest) → appeler le service → sérialiser (Resource).
4. **Le Core fournit les transverses** (tenancy, séquences, auth) — aucun module ne
   les réimplémente.
5. **Multi-tenant par scope global** — tout modèle métier utilise `BelongsToTenant` ;
   `tenant_id` est rempli à la création et toutes les requêtes sont filtrées,
   y compris le route model binding.
6. **Ne jamais capturer `TenantContext` dans un constructeur de service** — les
   contrôleurs sont mis en cache par le routeur ; le tenant se résout à l'appel.

### 2.3 Multi-tenant

Modèle retenu : **une seule base de données**, colonne `tenant_id` sur chaque table
métier, filtrage automatique par un global scope Eloquent. L'inscription crée
l'entreprise **et** son premier utilisateur administrateur en une transaction.

L'isolation est vérifiée par des tests dédiés dans **chaque** module : un tenant ne
peut ni lister, ni lire par ID, ni référencer les données d'un autre — y compris à
travers les clés étrangères (une facture ne peut pas pointer un tiers d'un autre
tenant).

---

## 3. Historique de construction

Le projet a été bâti par incréments, chacun testé et commité séparément.

| # | Commit | Contenu |
|---|---|---|
| 1 | `3532fa8` | **Socle + phases 1 à 5** : tenancy, auth, séquences, Tiers, Catalogue, Ventes, Stock, Compta |
| 2 | `56bdaf5` | **Module Achats** : commandes, réceptions partielles, factures fournisseur |
| 3 | `9d2c0f8` | **Fix** : page Compta blanche après l'ajout du journal AC |
| 4 | `d2f955f` | **Lettrage** clients / fournisseurs |
| 5 | `eeb3ce8` | **Clôture d'exercice** : résultat, à-nouveaux, verrou |
| 6 | `6f1eb80` | **Immobilisations** : amortissement, dotations, cession |

### Détail des phases initiales

- **Phase 1 — Socle** : multi-tenant, authentification, séquences, module Tiers, SPA.
- **Phase 2 — Catalogue** : produits/services, TVA marocaine, prix TTC calculé serveur.
- **Phase 3 — Ventes** : devis → commande → facture, PDF, paiements, événements.
- **Phase 4 — Stock** : entrepôts, mouvements, décrément automatique sur facture.
- **Phase 5 — Comptabilité** : plan PCGM, écritures automatiques, balance, état TVA.
- **Module Achats** (post phase 5) : alimentation du stock et de la TVA récupérable.
- **Compléments compta** : lettrage, clôture d'exercice, immobilisations.

---

## 4. Modules métier

### 4.1 Tiers

Clients et fournisseurs, avec les **identifiants légaux marocains** : ICE (validé à
15 chiffres), IF (identifiant fiscal), RC (registre de commerce), Patente, CNSS.

- Codes automatiques : `CL-AAAA-NNNNN` (client), `FR-AAAA-NNNNN` (fournisseur).
- Recherche (nom / code / ICE), filtre client / fournisseur, pagination.
- Le code est immuable après création ; suppression logique (soft delete).

### 4.2 Catalogue

Produits **et** services dans un même modèle.

- Codes automatiques : `PR-AAAA-NNNNN` (produit), `SV-AAAA-NNNNN` (service).
- **TVA verrouillée aux taux marocains** : 0, 7, 10, 14, 20 % (tout autre taux → 422).
- **Prix TTC calculé côté serveur** (`sell_price_ttc`), jamais stocké.
- Prix d'achat, unité, code-barres, actif/inactif.
- Le type et le code sont immuables après création (le code en dépend).

### 4.3 Ventes

Cycle commercial complet sur un **modèle unique** `DocumentVente` portant les trois
types (devis / commande / facture) plutôt que trois tables dupliquées.

- Codes : devis `DE-`, commande `CO-`, facture provisoire `PROV-` puis **définitive
  `FA-` à la validation** (exigence de non-discontinuité des factures).
- **Workflow verrouillé** : un document validé n'est plus modifiable ni supprimable ;
  un devis validé peut être accepté / refusé ; seule une facture validée est encaissable.
- **Transformation** devis → commande → facture avec copie des lignes et traçabilité
  de la source (`source_document_id`).
- **Lignes** avec remise en pourcentage ; montants HT/TVA/TTC figés à la saisie.
- **Paiements** multi-modes (espèces, chèque, virement, carte, autre), partiels,
  dépassement rejeté ; passage automatique en « payée » au solde.
- **PDF** (dompdf) : en-tête entreprise, bloc client avec ICE/IF, **ventilation de la
  TVA par taux** (obligatoire au Maroc), mention « BROUILLON » sur les non-validés.

### 4.4 Achats

Miroir des ventes côté fournisseur, avec la **gestion fine des entrepôts et des
réceptions partielles**.

- Codes : commande `CF-`, réception `RE-`, facture fournisseur `FF-`.
- Contrôle : le tiers doit être un **fournisseur** (`is_supplier`).
- **Réceptions partielles** : chaque ligne de commande suit sa `quantite_recue` face
  à la quantité commandée ; une réception ne porte par défaut que le **reste à
  recevoir** ; la commande passe automatiquement « reçue partielle » puis « reçue ».
- **Sur-réception bloquée** (on ne reçoit pas plus que commandé).
- **Entrepôt de destination** : souhaité sur la commande, effectif sur la réception.
- `ref_fournisseur` : numéro de la facture chez le fournisseur.
- La validation d'une facture met à jour le **prix d'achat** des produits (dernier prix).

### 4.5 Stock

- **Entrepôts** : le premier créé devient celui par défaut (défaut unique garanti) ;
  création à la volée « Entrepôt principal » si un mouvement survient avant tout
  paramétrage ; suppression bloquée si des mouvements existent.
- **Niveaux** : une table `stocks` par produit et par entrepôt, tenue à jour dans la
  **même transaction** que chaque mouvement (jamais recalculée à la volée), avec
  valorisation au prix d'achat.
- **Mouvements** typés (entrée, sortie, ajustement, vente, achat) avec **stock après
  mouvement** figé dans l'historique.
- **Ajustement d'inventaire** par quantité cible (on saisit 92, le delta est calculé).
- **Automatismes inter-modules** :
  - facture de vente validée → **sortie** (produits physiques uniquement) ;
  - réception fournisseur validée → **entrée** ;
  - facture fournisseur directe (sans réception) → **entrée implicite**.
  - Règle « le stock bouge une seule fois » : une facture issue d'une réception ne
    refait pas l'entrée.
- Le stock négatif est autorisé (vendre sans réception préalable) et signalé en rouge.

### 4.6 Comptabilité

Voir §5 pour le détail — c'est le module le plus riche.

---

## 5. Le moteur comptable

### 5.1 Plan comptable marocain (CGNC)

Un sous-ensemble PME du Plan Comptable Général Marocain (~70 comptes, 7 classes) est
**pré-chargé automatiquement au premier usage** (`PlanComptableMarocain.php`), sans
aucune configuration demandée à l'entreprise. Le comptable peut ajouter ses propres
sous-comptes (code CGNC valide : classe 1-7 + 4 à 8 chiffres).

### 5.2 La méthode « masquer la complexité »

Le CGNC compte des milliers de comptes ; aucune PME ne veut les affronter. La
solution en quatre couches :

1. **Pré-chargé, jamais paramétré** — plan seedé à la volée.
2. **L'utilisateur ne choisit jamais un compte** — les écritures naissent des
   événements métier (validation de facture, encaissement…).
3. **11 « comptes par défaut » remappables** — l'entreprise manipule des concepts
   simples (clients, ventes, TVA, banque, caisse, fournisseurs, achats…) ; le
   comptable adapte le mapping en deux clics (ex. ventiler les ventes de conseil sur
   un sous-compte 71141) sans toucher au code.
4. **La partie double est garantie en un seul endroit** (`ComptaService::creerEcriture`) :
   toute écriture déséquilibrée est rejetée, qu'elle soit automatique ou manuelle.

### 5.3 Journaux et écritures automatiques

| Journal | Code | Générateur |
|---|---|---|
| Ventes | VT | Validation d'une facture client |
| Achats | AC | Validation d'une facture fournisseur |
| Trésorerie | BQ | Encaissement client / décaissement fournisseur |
| Opérations diverses | OD | Saisie manuelle, dotations, cession, résultat de clôture |
| À-nouveaux | AN | Ouverture d'exercice après clôture |

**Écritures types générées automatiquement** (conformes aux modèles CGNC) :

- **Facture de vente** (VT) : débit `3411 Clients` (TTC) / crédit `7111`
  marchandises + `7114` services (ventilation selon le type de produit) + `4441 TVA
  facturée`.
- **Encaissement client** (BQ) : débit `5141`/`5161`/`5111` selon le mode / crédit `3411`.
- **Facture d'achat** (AC) : débit `6111`/`6117` + `3442 TVA récupérable` / crédit `4411`.
- **Décaissement fournisseur** (BQ) : débit `4411` / crédit trésorerie selon le mode.

### 5.4 Lettrage

Rapprochement des débits et crédits sur les comptes de tiers (3411, 4411).

- Les lignes d'écriture portent le **tiers**, propagé automatiquement par toutes les
  écritures VT/BQ/AC (et saisissable en OD).
- **Lettrage automatique par référence** : les écritures automatiques partageant le
  même code (FA-/FF-) entre facture et règlements sont rapprochées si le groupe est
  équilibré. Un paiement partiel n'est pas lettré tant que le solde n'est pas atteint.
- **Lettrage manuel** : sélection de lignes du même compte, strictement équilibrée.
- **Codes** AAA, AAB… incrémentés par compte (AAAA après ZZZ), comme Sage.
- **Délettrage** possible ; **solde non lettré** affiché en permanence.

### 5.5 Clôture d'exercice

Le verrou légal de la comptabilité (piste d'audit).

1. **Détermination du résultat** : les classes 6 et 7 sont soldées vers `1161`
   (bénéfice) ou `1162` (perte) par une écriture OD au 31/12.
2. **À-nouveaux** : les soldes de bilan (classes 1-5) sont reportés au 01/01 suivant
   (journal AN).
3. **Verrou irréversible** : aucune écriture ni facture ne peut plus être datée dans
   un exercice clos — le contrôle vit dans le point unique de création d'écriture,
   donc tout est bloqué d'un coup.
4. **Clôture chronologique** imposée (on ne clôture pas N avant N-1).

Après clôture, la balance et tous les calculs de soldes se bornent à la période
ouverte (les à-nouveaux portent déjà l'historique) ; l'état TVA exclut le journal AN.

### 5.6 Immobilisations

Cycle de vie des biens durables.

- **Registre** : 8 catégories (matériel informatique, transport, mobilier,
  constructions, installations, agencements, logiciels…) pré-remplissant les comptes
  CGNC (23xx / 28xx) et la durée fiscale usuelle — tout reste modifiable.
- **Plan d'amortissement linéaire** avec prorata temporis mensuel la première année ;
  la dernière année absorbe l'arrondi pour que la VNA finale soit exactement 0.
- **Dotations annuelles automatiques** : écriture OD (débit `6161` / crédits `28xx`
  regroupés), **idempotente** par année et par bien.
- **Cession** : sortie de l'actif (reprise des amortissements `28xx` + VNA en charge
  `6511` / annulation de la valeur brute `23xx`) et produit de cession (`5141`/`7511`) ;
  la plus ou moins-value ressort naturellement dans le résultat. Mise au rebut à 0 gérée.
- L'acquisition **n'est pas re-comptabilisée** (déjà passée via la facture fournisseur
  ou une OD), pour éviter de doubler l'actif.

### 5.7 États

- **Balance générale** (filtrable par période), équilibre vérifié.
- **État de TVA mensuel** : TVA facturée (4441) − TVA récupérable (3441+3442) = TVA due,
  prêt pour la télédéclaration SIMPL-TVA.

---

## 6. Rattrapage des tenants existants

Le seed du plan comptable est **idempotent et rétro-compatible** : quand une nouvelle
version ajoute des comptes système (ex. 2832/6511/7511 pour les immobilisations) ou
des comptes par défaut (ex. fournisseurs/achats), `initialiserPlanComptable()` les
installe chez les tenants déjà créés, sans dupliquer l'existant. Aucune migration de
données manuelle n'est nécessaire.

---

## 7. Communication inter-modules (événements)

Le cœur du découplage. Un module émet un événement ; les autres y réagissent sans le
connaître.

| Événement (émetteur) | Écouteur | Effet |
|---|---|---|
| `FactureValidee` (Ventes) | Stock | Sortie de stock des produits |
| `FactureValidee` (Ventes) | Compta | Écriture de vente (VT) |
| `PaiementEnregistre` (Ventes) | Compta | Écriture d'encaissement (BQ) |
| `ReceptionValidee` (Achats) | Stock | Entrée de stock |
| `FactureAchatValidee` (Achats) | Stock | Entrée implicite (si facture directe) |
| `FactureAchatValidee` (Achats) | Compta | Écriture d'achat (AC) |
| `PaiementFournisseurEnregistre` (Achats) | Compta | Écriture de décaissement (BQ) |
| `CommandeValidee`, `DevisValide`, `CommandeFournisseurValidee` | — | Réservés à des usages futurs |

C'est ce mécanisme qui permet à une simple validation de facture de déclencher, en
cascade et automatiquement, la sortie de stock, l'écriture de vente, puis (à
l'encaissement) l'écriture de trésorerie — sans qu'aucun module n'appelle un autre.

---

## 8. Tests

Couverture : **69 tests, 467 assertions, 100 % au vert**.

| Fichier | Portée |
|---|---|
| `TenancyIsolationTest` | Inscription tenant+admin, séquences par tenant, isolation stricte |
| `CatalogueTest` | Séquences PR/SV, TVA marocaine, TTC calculé, immuabilité |
| `VentesTest` | Totaux avec remise, PROV→FA, workflow, transformation, paiements, PDF, événements |
| `AchatsTest` | Cycle CF→RE→FF, réceptions partielles, sur-réception, prix d'achat |
| `StockTest` | Entrées/sorties, ajustement, décrément auto, entrepôt par défaut |
| `ComptaTest` | Seed lazy, écritures VT/BQ, OD équilibrée, mapping, balance, TVA |
| `LettrageTest` | Auto par référence, manuel équilibré, codes, délettrage |
| `ClotureTest` | Résultat, à-nouveaux, verrou, chronologie, double clôture |
| `ImmobilisationTest` | Plan prorata, dotation idempotente, VNA, cession, verrou clôture |

Chaque module teste systématiquement l'**isolation multi-tenant** et le **rejet des
cas invalides** (déséquilibre, dépassement, sur-réception, exercice clos…).

---

## 9. Démarrage

```bash
composer install
npm install
php artisan migrate
npm run build        # ou `npm run dev` pour le HMR
php artisan serve    # http://127.0.0.1:8000
```

Créer son entreprise via l'écran « Créer mon entreprise » (ou
`POST /api/v1/auth/register`).

**Vérifications** : `php artisan test` · `npx tsc --noEmit`

> Environnement de développement (Windows) : PHP 8.4 est installé dans
> `%LOCALAPPDATA%\php`. Si `php` n'est pas dans le PATH d'un terminal déjà ouvert,
> utiliser `& "$env:LOCALAPPDATA\php\php.exe" artisan …`.

---

## 10. Référentiel des comptes clés utilisés

| Compte | Intitulé | Usage |
|---|---|---|
| 3411 | Clients | Créances de vente |
| 4411 | Fournisseurs | Dettes d'achat |
| 4441 | État — TVA facturée | TVA collectée sur ventes |
| 3442 | État — TVA récupérable sur charges | TVA déductible sur achats |
| 5141 / 5161 / 5111 | Banque / Caisse / Chèques | Trésorerie selon le mode |
| 7111 / 7114 | Ventes de marchandises / services | Produits d'exploitation |
| 6111 / 6117 | Achats de marchandises / prestations | Charges d'exploitation |
| 6161 | Dotations aux amortissements | Amortissement des immos |
| 28xx | Amortissements des immobilisations | Contrepartie des dotations |
| 6511 / 7511 | VNA cédée / Produit de cession | Cession d'immobilisation |
| 1161 / 1162 | Résultat bénéfice / perte | Détermination du résultat (clôture) |

---

## 11. Feuille de route

### Fait

- [x] Socle multi-tenant, auth, séquences
- [x] Tiers (identifiants marocains)
- [x] Catalogue (TVA marocaine)
- [x] Ventes (devis → facture, PDF, paiements)
- [x] Stock (mouvements automatiques)
- [x] Comptabilité PCGM (écritures automatiques, balance, TVA)
- [x] Achats (réceptions partielles, TVA récupérable)
- [x] Lettrage clients / fournisseurs
- [x] Clôture d'exercice (résultat, à-nouveaux, verrou)
- [x] Immobilisations (amortissement, dotations, cession)

### À venir (issu du benchmark Sage 100 / X3)

- [ ] Rapprochement bancaire (nécessite un format de relevé bancaire marocain)
- [ ] Comptabilité analytique (axes, ventilation)
- [ ] États financiers : bilan, CPC générés depuis la balance ; liasse / ETIC
- [ ] Export SIMPL-TVA
- [ ] **Facturation électronique DGI** — obligatoire au Maroc en 2026 (modèle
      « clearance », formats UBL 2.1 / CII). Palier 10-200 M DH de CA entré en vigueur
      le 01/07/2026 ; modalités précisées par décret d'application à surveiller.
- [ ] Phase 6 — RH & Projets (congés, notes de frais, temps passé)
- [ ] PDF bon de commande fournisseur, transferts inter-entrepôts, stock minimum
- [ ] Passage PostgreSQL, rôles/permissions fins, facturation SaaS des abonnements

---

*Document généré dans le cadre du développement du projet Dolibarr Maroc.*
