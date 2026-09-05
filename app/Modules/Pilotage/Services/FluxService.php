<?php

namespace App\Modules\Pilotage\Services;

use App\Models\User;
use App\Modules\Achats\Models\DocumentAchat;
use App\Modules\Achats\Models\DocumentAchatLigne;
use App\Modules\Catalogue\Models\Produit;
use App\Modules\Stock\Models\Stock;
use App\Modules\Ventes\Models\DocumentVente;
use App\Modules\Ventes\Models\Paiement;
use Illuminate\Support\Facades\DB;

/**
 * Compteurs de l'écran de suivi : où en sont les flux, maintenant.
 *
 * TROIS PARTIS PRIS.
 *
 * 1. LES BLOCS INTERDITS SONT OMIS, PAS MIS À ZÉRO. Un caissier n'a pas accès
 *    aux ventes ; lui afficher « 0 facture impayée » alors qu'il y en a trente
 *    serait un mensonge, pas une restriction. La clé est absente et
 *    `capabilities` dit pourquoi. C'est le contrat déjà posé par le tableau de
 *    bord, repris à la lettre.
 *
 * 2. `etatLivraison()` N'EST JAMAIS APPELÉ. Cette méthode charge les lignes du
 *    document si la relation ne l'est pas : l'appeler sur une liste coûterait
 *    une requête par document. La question est donc posée UNE fois, en SQL.
 *
 * 3. ON N'AFFICHE PAS DE CHIFFRE QU'ON NE SAIT PAS CALCULER. Voir `indisponible`
 *    en bas de ce fichier : deux indicateurs qu'on refuse de publier, avec leur
 *    raison. Une tuile grise qui explique vaut mieux qu'un nombre faux.
 */
class FluxService
{
    /** Écart en deçà duquel un reliquat n'en est plus un (arrondis décimaux). */
    private const EPSILON = 0.0009;

    public function pour(User $user): array
    {
        $flux = [
            'capabilities' => [
                'ventes' => $user->hasPermission('ventes'),
                'achats' => $user->hasPermission('achats'),
                'stock' => $user->hasPermission('stock'),
            ],
            'genere_a' => now()->toIso8601String(),
            'indisponible' => $this->indisponible(),
        ];

        if ($user->hasPermission('ventes')) {
            $flux['ventes'] = $this->ventes();
        }

        if ($user->hasPermission('achats')) {
            $flux['achats'] = $this->achats();
        }

        if ($user->hasPermission('stock')) {
            $flux['stock'] = $this->stock();
        }

        return $flux;
    }

    /* ------------------------------------------------------------------ */

    private function ventes(): array
    {
        return [
            // Le carnet ferme : commandes validées, sans rien prétendre sur
            // l'avancement de la livraison — voir `indisponible`.
            'commandes_ouvertes' => $this->compte(DocumentVente::TYPE_COMMANDE, [
                DocumentVente::STATUT_VALIDE,
                DocumentVente::STATUT_ACCEPTE,
            ]),

            'commandes_reliquat' => ['count' => $this->commandesAvecReliquat()],

            'devis_en_attente' => $this->compte(DocumentVente::TYPE_DEVIS, [
                DocumentVente::STATUT_VALIDE,
            ]),

            'brouillons' => $this->compte(DocumentVente::TYPE_COMMANDE, [
                DocumentVente::STATUT_BROUILLON,
            ]),

            'bl_a_preparer' => $this->compte(DocumentVente::TYPE_BON_LIVRAISON, [
                DocumentVente::STATUT_BROUILLON,
            ]),

            // Une facture soldée bascule en `paye` : `valide` EST donc « impayée »,
            // sans avoir à joindre les règlements pour le savoir.
            'factures_impayees' => $this->impayees(false),
            'factures_echues' => $this->impayees(true),
        ];
    }

    private function achats(): array
    {
        // Côté achats, l'état de réception est un VRAI statut en base, recalculé
        // à chaque réception validée. C'est le bloc le plus fiable de l'écran.
        $ouvertes = DocumentAchat::query()
            ->where('type', DocumentAchat::TYPE_COMMANDE)
            ->whereIn('statut', [DocumentAchat::STATUT_VALIDE, DocumentAchat::STATUT_RECUE_PARTIELLE])
            ->selectRaw('COUNT(*) as nb, COALESCE(SUM(total_ttc), 0) as ttc')
            ->first();

        $partielles = DocumentAchat::query()
            ->where('type', DocumentAchat::TYPE_COMMANDE)
            ->where('statut', DocumentAchat::STATUT_RECUE_PARTIELLE)
            ->count();

        // Valeur de ce qui reste à recevoir : une seule requête agrégée.
        $reste = DocumentAchatLigne::query()
            ->whereHas('document', fn ($q) => $q
                ->where('type', DocumentAchat::TYPE_COMMANDE)
                ->whereIn('statut', [DocumentAchat::STATUT_VALIDE, DocumentAchat::STATUT_RECUE_PARTIELLE]))
            ->selectRaw('COALESCE(SUM((quantite - quantite_recue) * prix_unitaire), 0) as reste')
            ->value('reste');

        return [
            'commandes_ouvertes' => [
                'count' => (int) $ouvertes->nb,
                'montant_ttc' => $this->montant($ouvertes->ttc),
            ],
            'reception_partielle' => ['count' => $partielles],
            'reste_a_recevoir' => ['montant_ht' => $this->montant($reste)],
            'factures_a_payer' => $this->compteAchat(DocumentAchat::TYPE_FACTURE, [
                DocumentAchat::STATUT_VALIDE,
                DocumentAchat::STATUT_RECUE_PARTIELLE,
                DocumentAchat::STATUT_RECUE,
            ]),
        ];
    }

    /**
     * Résumé du stock en UN seul balayage.
     *
     * `fromSub` sur un builder Eloquent : la sous-requête emporte le filtre
     * d'entreprise et la corbeille. `CASE WHEN` plutôt que `FILTER (WHERE …)`,
     * qui dépend de la version de SQLite.
     */
    private function stock(): array
    {
        $sousRequete = Produit::query()
            ->where('type', 'product')
            ->where('is_active', true)
            ->select('produits.id', 'produits.stock_min', 'produits.buy_price')
            ->addSelect(['q' => Stock::query()
                ->selectRaw('COALESCE(SUM(quantite), 0)')
                ->whereColumn('produit_id', 'produits.id'),
            ]);

        $resume = DB::query()->fromSub($sousRequete, 't')->selectRaw(
            'COUNT(*) as total,
             COALESCE(SUM(CASE WHEN q <= 0 THEN 1 ELSE 0 END), 0) as en_rupture,
             COALESCE(SUM(CASE WHEN stock_min IS NOT NULL AND q <= stock_min THEN 1 ELSE 0 END), 0) as sous_seuil,
             COALESCE(SUM(q * COALESCE(buy_price, 0)), 0) as valeur'
        )->first();

        return [
            'references_actives' => (int) $resume->total,
            'en_rupture' => (int) $resume->en_rupture,
            'sous_seuil' => (int) $resume->sous_seuil,
            'valeur_achat' => $this->montant($resume->valeur),
        ];
    }

    /* ------------------------------------------------------------------ */

    /** @param  list<string>  $statuts */
    private function compte(string $type, array $statuts): array
    {
        $ligne = DocumentVente::query()
            ->where('type', $type)
            ->whereIn('statut', $statuts)
            ->selectRaw('COUNT(*) as nb, COALESCE(SUM(total_ttc), 0) as ttc')
            ->first();

        return ['count' => (int) $ligne->nb, 'montant_ttc' => $this->montant($ligne->ttc)];
    }

    /** @param  list<string>  $statuts */
    private function compteAchat(string $type, array $statuts): array
    {
        $ligne = DocumentAchat::query()
            ->where('type', $type)
            ->whereIn('statut', $statuts)
            ->selectRaw('COUNT(*) as nb, COALESCE(SUM(total_ttc), 0) as ttc')
            ->first();

        return ['count' => (int) $ligne->nb, 'montant_ttc' => $this->montant($ligne->ttc)];
    }

    /**
     * Factures non soldées, et ce qu'il reste réellement à encaisser.
     *
     * Deux requêtes seulement : le total facturé, puis le total déjà encaissé
     * sur ces mêmes factures. Le tableau de bord, lui, charge chaque facture et
     * appelle `resteAPayer()` ligne à ligne — on ne reproduit pas ce N+1.
     */
    private function impayees(bool $echuesSeulement): array
    {
        $filtre = fn ($query) => $query
            ->where('type', DocumentVente::TYPE_FACTURE)
            ->where('statut', DocumentVente::STATUT_VALIDE)
            ->when($echuesSeulement, fn ($q) => $q
                ->whereNotNull('date_echeance')
                ->whereDate('date_echeance', '<', now()->toDateString()));

        $facture = DocumentVente::query()
            ->tap($filtre)
            ->selectRaw('COUNT(*) as nb, COALESCE(SUM(total_ttc), 0) as ttc')
            ->first();

        $encaisse = Paiement::query()
            ->whereHas('document', $filtre)
            ->sum('montant');

        return [
            'count' => (int) $facture->nb,
            'reste' => $this->montant(max(0, (float) $facture->ttc - (float) $encaisse)),
        ];
    }

    /**
     * Le nombre de commandes qui gardent un reliquat, posé en UNE requête.
     *
     * ⚠️ Ce chiffre porte une réserve, et son libellé doit la refléter :
     * `quantite_livree` n'est alimenté que lorsqu'un bon de livraison est créé
     * DEPUIS la commande. Une commande transformée directement en facture
     * apparaîtra donc éternellement « avec reliquat ». C'est pourquoi on ne dit
     * pas « non livrées » — voir `indisponible`.
     */
    private function commandesAvecReliquat(): int
    {
        return DocumentVente::query()
            ->where('type', DocumentVente::TYPE_COMMANDE)
            ->whereIn('statut', [DocumentVente::STATUT_VALIDE, DocumentVente::STATUT_ACCEPTE])
            ->whereHas('lignes', fn ($q) => $q->whereRaw('quantite - quantite_livree > ?', [self::EPSILON]))
            ->count();
    }

    /**
     * Ce qu'on refuse de publier, et pourquoi.
     *
     * L'écran les rend en tuile grise. C'est la voix du projet : le bloc
     * `hypotheses` du réappro affiche déjà ses paramètres, les badges d'origine
     * disent d'où sort chaque suggestion. On n'invente pas un chiffre pour
     * remplir une case.
     */
    private function indisponible(): array
    {
        return [
            [
                'cle' => 'commandes_facturees',
                'libelle' => 'Commandes facturées',
                'raison' => "Aucun lien de facturation n'est enregistré en base : il faudrait "
                    ."remonter toute la chaîne des documents ET rapprocher les quantités. Le chiffre serait une supposition.",
            ],
            [
                'cle' => 'livraisons_en_transit',
                'libelle' => 'Livraisons en cours de route',
                'raison' => "Un bon de livraison est brouillon ou validé : il n'existe pas d'état "
                    .'de transport entre les deux.',
            ],
        ];
    }

    private function montant(mixed $valeur): string
    {
        return number_format((float) $valeur, 2, '.', '');
    }
}
