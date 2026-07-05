# Dolibarr Maroc — ERP SaaS multi-entreprises

ERP/CRM SaaS moderne inspiré des fonctionnalités de Dolibarr, pensé pour le marché marocain
(ICE, IF, RC, Patente, CNSS, et à terme plan comptable PCGM/CGNC).

**Stack** : Laravel 13 (API REST, monolithe modulaire) · React 19 + TypeScript (SPA) ·
Tailwind CSS 4 · TanStack Query · Sanctum (auth par token) · SQLite en dev (PostgreSQL visé en prod).

## Démarrage

```bash
composer install
npm install
cp .env.example .env && php artisan key:generate   # déjà fait si projet cloné tel quel
php artisan migrate
npm run build        # ou `npm run dev` pour le HMR pendant le développement
php artisan serve    # http://127.0.0.1:8000
```

Créez votre entreprise via l'écran « Créer mon entreprise » (ou `POST /api/v1/auth/register`).

Tests : `php artisan test` · Typecheck front : `npx tsc --noEmit`

## Architecture

```
app/
├── Core/                    ← Noyau partagé, consommé par tous les modules
│   ├── Tenancy/             ← Multi-tenant : 1 BDD, scope automatique par tenant_id
│   │   ├── Tenant.php           (modèle entreprise)
│   │   ├── TenantContext.php    (tenant courant, singleton scoped par requête)
│   │   ├── TenantScope.php      (global scope Eloquent + résolution du tenant courant)
│   │   ├── BelongsToTenant.php  (trait à poser sur tout modèle métier)
│   │   └── SetTenantContext.php (middleware `tenant`, après auth:sanctum)
│   ├── Sequences/           ← Numérotation par tenant/année : CL-2026-00001…
│   └── Auth/                ← register (tenant + admin), login, me, logout
└── Modules/                 ← Un dossier autonome par module métier
    └── Tiers/
        ├── Models/          ← Tiers (clients/fournisseurs, identifiants marocains)
        ├── Http/            ← Controllers, Requests (validation), Resources (JSON)
        ├── Services/        ← Logique métier (jamais dans les contrôleurs)
        ├── routes.php       ← Routes du module, préfixées api/v1
        └── TiersServiceProvider.php  (enregistré dans bootstrap/providers.php)

resources/js/                ← SPA React (TypeScript)
├── app.tsx                  ← Entrée : router + React Query
├── lib/                     ← client axios (token + 401), contexte d'auth
├── components/Layout.tsx    ← Shell : sidebar modules + topbar
└── pages/                   ← Login, Register, Dashboard, tiers/…
```

### Règles de conception

1. **Modules découplés** : un module ne référence jamais les classes internes d'un autre
   (exception : les modèles Eloquent, qui sont l'API publique d'un module).
   La communication inter-modules passe par des événements Laravel : `FactureValidee`
   → le listener `DecrementerStockSurFacture` du module Stock sort la marchandise ;
   le module Compta s'y branchera de la même façon en phase 5.
2. **La logique métier vit dans `Services/`**, les contrôleurs ne font que
   valider (FormRequest) → appeler le service → sérialiser (Resource).
3. **Le Core fournit les transverses** (tenancy, séquences, auth) :
   aucun module ne les réimplémente. Les PDF sont générés par dompdf
   (template `resources/views/pdf/document-vente.blade.php`).
4. **Multi-tenant par scope global** : tout modèle métier utilise le trait `BelongsToTenant`.
   Le `tenant_id` est rempli automatiquement à la création et toutes les requêtes sont
   filtrées — y compris le route model binding (repli sur l'utilisateur authentifié,
   voir `TenantScope::currentTenantId()`).
5. **Ne jamais capturer `TenantContext` dans un constructeur de service** : les contrôleurs
   sont mis en cache par le routeur (et Octane réutilise les instances). Résoudre le tenant
   à l'appel, comme le fait `SequenceService`.

### Ajouter un module (ex. Ventes)

1. Créer `app/Modules/Ventes/` avec `Models/`, `Http/`, `Services/`, `routes.php`.
2. Les modèles utilisent le trait `BelongsToTenant` et une migration avec `tenant_id`
   (`foreignId('tenant_id')->constrained()->cascadeOnDelete()` + index/unique par tenant).
3. Les codes documents (devis, factures) s'obtiennent via `SequenceService::next('DE')`.
4. Créer `VentesServiceProvider` (copier celui de Tiers) et l'ajouter à `bootstrap/providers.php`.
5. Côté front : ajouter les pages dans `resources/js/pages/ventes/` et les routes dans `app.tsx`,
   puis activer l'entrée dans `components/Layout.tsx`.

## API (v1)

| Méthode | Route | Description |
|---|---|---|
| POST | `/api/v1/auth/register` | Crée l'entreprise (tenant) + utilisateur admin, renvoie un token |
| POST | `/api/v1/auth/login` | Authentification, renvoie un token Bearer |
| GET | `/api/v1/auth/me` | Utilisateur + tenant courants |
| POST | `/api/v1/auth/logout` | Révoque le token courant |
| GET | `/api/v1/tiers` | Liste paginée — `?search=` (nom/code/ICE), `?type=client\|fournisseur`, `?page=` |
| POST | `/api/v1/tiers` | Création (code auto CL-/FR-AAAA-NNNNN, ICE validé à 15 chiffres) |
| GET/PUT/DELETE | `/api/v1/tiers/{id}` | Détail / mise à jour (code immuable) / suppression (soft delete) |
| GET | `/api/v1/produits` | Liste paginée — `?search=` (nom/code/code-barres), `?type=product\|service`, `?page=` |
| POST | `/api/v1/produits` | Création (code auto PR-/SV-AAAA-NNNNN, TVA limitée aux taux marocains) |
| GET/PUT/DELETE | `/api/v1/produits/{id}` | Détail (TTC calculé) / mise à jour (code et type immuables) / soft delete |
| GET | `/api/v1/ventes/documents` | Liste paginée — `?type=devis\|commande\|facture`, `?statut=`, `?search=` (code/client) |
| POST | `/api/v1/ventes/documents` | Création brouillon avec lignes (devis DE-, commande CO-, facture PROV-) |
| GET/PUT/DELETE | `/api/v1/ventes/documents/{id}` | Détail avec lignes+paiements / modification / suppression (brouillon uniquement) |
| POST | `…/{id}/valider` | Valide le document ; une facture reçoit alors son numéro définitif FA- |
| POST | `…/{id}/statut` | Accepte ou refuse un devis validé (`{statut: accepte\|refuse}`) |
| POST | `…/{id}/transformer` | Devis → commande/facture, commande → facture (lignes copiées, source liée) |
| POST | `…/{id}/paiements` | Encaissement (facture validée) ; passe en "paye" quand le solde atteint 0 |
| GET | `…/{id}/pdf` | PDF du document (ventilation TVA par taux, mentions ICE/IF/RC) |
| GET | `/api/v1/stock/niveaux` | Stock agrégé par produit — `?search=`, `?entrepot_id=` (valeur d'achat incluse) |
| GET/POST | `/api/v1/stock/mouvements` | Historique / mouvement manuel (`entree`, `sortie`, `ajustement` = quantité cible) |
| GET/POST/PUT/DELETE | `/api/v1/stock/entrepots` | Entrepôts — le premier créé devient celui par défaut, défaut unique garanti |
| GET/POST | `/api/v1/compta/comptes` | Plan comptable PCGM (seedé automatiquement) / ajout de sous-comptes CGNC |
| GET/PUT | `/api/v1/compta/mappings` | Comptes par défaut (clients, ventes, TVA, banque, caisse…) — l'adaptation sans saisie |
| GET/POST | `/api/v1/compta/ecritures` | Journal (VT/BQ/OD) / écriture manuelle OD — partie double vérifiée serveur |
| GET | `/api/v1/compta/balance` | Balance générale — `?du=`, `?au=` |
| GET/POST | `/api/v1/compta/lettrage` | Lignes lettrables d'un compte (`?compte_id=`, `?statut=`) / lettrage manuel équilibré |
| POST | `/api/v1/compta/lettrage/auto` | Lettrage automatique par référence (FA-/FF- partagée entre facture et règlements) |
| POST | `/api/v1/compta/lettrage/delettrer` | Supprime un groupe de lettrage (`{compte_id, code}`) |
| GET | `/api/v1/compta/tva` | État TVA du mois : facturée (4441) − récupérable (3441+3442) = due (ou crédit) |
| GET/POST | `/api/v1/achats/documents` | Commandes CF- / réceptions RE- / factures FF- fournisseur — `?type=`, `?search=` |
| GET/PUT/DELETE | `…/achats/documents/{id}` | Détail (reste à recevoir par ligne) / modification / suppression (brouillon) |
| POST | `…/achats/documents/{id}/valider` | Réception : contrôle sur-réception + entrée de stock ; facture : écriture AC + maj prix d'achat |
| POST | `…/achats/documents/{id}/transformer` | Commande → réception (du reste) ou facture ; réception → facture |
| POST | `…/achats/documents/{id}/paiements` | Règlement fournisseur (écriture BQ, statut "payée" au solde) |

Toutes les routes métier exigent `Authorization: Bearer <token>` et sont isolées par tenant.

## Feuille de route

- [x] **Phase 1 — Socle** : multi-tenant, auth, séquences, module Tiers, SPA
- [x] **Phase 2 — Catalogue** : produits/services, prix HT/TTC, TVA marocaine (20 %, 14 %, 10 %, 7 %, exonéré)
- [x] **Phase 3 — Ventes** : devis → commande → facture (transformation avec traçabilité), lignes avec remises, numérotation définitive des factures à la validation (PROV- → FA-), paiements multi-modes, PDF dompdf, événements `DevisValide`/`CommandeValidee`/`FactureValidee`
- [x] **Phase 4 — Stock** : entrepôts (défaut unique, création lazy), niveaux par produit/entrepôt tenus transactionnellement, mouvements avec `quantite_apres`, ajustement d'inventaire par quantité cible, **sortie automatique à la validation de facture** (listener sur `FactureValidee`, services ignorés, stock négatif autorisé et signalé)
- [x] **Phase 5 — Comptabilité** : plan comptable PCGM/CGNC pré-chargé (~70 comptes PME, 7 classes, lazy), **écritures générées automatiquement** (facture validée → VT `3411/7111/7114/4441`, encaissement → BQ `5141|5161|5111/3411` selon le mode), comptes par défaut remappables, écritures manuelles OD équilibrées, balance générale, état de TVA mensuel

### La méthode PCGM : masquer la complexité

Le CGNC impose des milliers de comptes — aucune PME ne veut les affronter. La méthode ici :
1. **Sous-ensemble PME pré-chargé** (`PlanComptableMarocain.php`) : ~70 comptes essentiels,
   créés au premier usage, zéro configuration.
2. **L'utilisateur ne choisit jamais un compte** : les écritures naissent des événements
   métier (validation de facture, encaissement) via les listeners du module Compta.
3. **7 « comptes par défaut »** (clients, ventes marchandises/services, TVA facturée,
   banque, caisse, chèques) : le comptable adapte le mapping en 2 clics — par exemple
   ventiler les ventes de conseil sur un sous-compte 71141 — sans toucher au code.
4. **La partie double est garantie dans un seul endroit** (`ComptaService::creerEcriture`) :
   toute écriture déséquilibrée est rejetée, qu'elle soit automatique ou manuelle.
- [x] **Lettrage** clients/fournisseurs : codes AAA/AAB par compte, groupes strictement équilibrés, tiers porté par les lignes d'écriture, lettrage automatique par référence, délettrage — prérequis du rapprochement bancaire et du régime TVA des encaissements
- [x] **Module Achats** : commandes fournisseurs (CF-) → **réceptions partielles** (RE-, cumul reçu/commandé, sur-réception bloquée, entrepôt par réception) → factures fournisseur (FF-, réf. externe). Le stock entre **une seule fois** (réception, ou facture directe sans source), colonne « en commande » dans les niveaux, prix d'achat mis à jour à la facture, écritures AC (6111/6117 + 3442 / 4411) et décaissements BQ — l'état TVA est complet (facturée − récupérable)
- [ ] **Phase 6 — RH & Projets** : congés, notes de frais, temps passé
- [ ] Passage PostgreSQL + rôles/permissions fins (spatie/laravel-permission) + facturation SaaS
