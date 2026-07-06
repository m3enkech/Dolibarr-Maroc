<?php

namespace App\Modules\Stock\Services;

use App\Core\Sequences\SequenceService;
use App\Modules\Catalogue\Models\Produit;
use App\Modules\Stock\Models\Entrepot;
use App\Modules\Stock\Models\Inventaire;
use App\Modules\Stock\Models\InventaireLigne;
use App\Modules\Stock\Models\MouvementStock;
use App\Modules\Stock\Models\Stock;
use App\Modules\Ventes\Models\DocumentVente;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockService
{
    public function __construct(private SequenceService $sequences) {}

    /* ------------------------------------------------------------------ */
    /* Entrepôts                                                           */
    /* ------------------------------------------------------------------ */

    public function creerEntrepot(array $data): Entrepot
    {
        return DB::transaction(function () use ($data) {
            // Le premier entrepôt du tenant est forcément celui par défaut.
            $isFirst = ! Entrepot::exists();
            $isDefault = $isFirst || (bool) ($data['is_default'] ?? false);

            if ($isDefault) {
                Entrepot::where('is_default', true)->update(['is_default' => false]);
            }

            return Entrepot::create([
                'code' => $this->sequences->next('EN'),
                'name' => $data['name'],
                'address' => $data['address'] ?? null,
                'is_default' => $isDefault,
                'is_active' => $data['is_active'] ?? true,
            ]);
        });
    }

    public function modifierEntrepot(Entrepot $entrepot, array $data): Entrepot
    {
        return DB::transaction(function () use ($entrepot, $data) {
            if (! empty($data['is_default'])) {
                Entrepot::where('is_default', true)->whereKeyNot($entrepot->id)->update(['is_default' => false]);
            } else {
                // On ne peut pas retirer le défaut sans en désigner un autre.
                unset($data['is_default']);
            }

            $entrepot->update(collect($data)->only(['name', 'address', 'is_default', 'is_active'])->all());

            return $entrepot->refresh();
        });
    }

    public function supprimerEntrepot(Entrepot $entrepot): void
    {
        if ($entrepot->mouvements()->exists()) {
            throw ValidationException::withMessages([
                'entrepot' => 'Impossible de supprimer un entrepôt qui a des mouvements de stock.',
            ]);
        }

        DB::transaction(function () use ($entrepot) {
            $wasDefault = $entrepot->is_default;
            Stock::where('entrepot_id', $entrepot->id)->delete();
            $entrepot->delete();

            if ($wasDefault) {
                Entrepot::query()->orderBy('id')->first()?->update(['is_default' => true]);
            }
        });
    }

    /**
     * Entrepôt par défaut du tenant, créé à la volée si aucun n'existe
     * (première validation de facture d'un nouveau tenant, par exemple).
     */
    public function entrepotParDefaut(): Entrepot
    {
        return Entrepot::where('is_default', true)->first()
            ?? Entrepot::query()->orderBy('id')->first()
            ?? $this->creerEntrepot(['name' => 'Entrepôt principal']);
    }

    /* ------------------------------------------------------------------ */
    /* Mouvements                                                          */
    /* ------------------------------------------------------------------ */

    public function entree(Produit $produit, Entrepot $entrepot, float $quantite, ?string $note = null): MouvementStock
    {
        return $this->mouvement($produit, $entrepot, $quantite, MouvementStock::TYPE_ENTREE, note: $note);
    }

    public function sortie(Produit $produit, Entrepot $entrepot, float $quantite, ?string $note = null): MouvementStock
    {
        return $this->mouvement($produit, $entrepot, -$quantite, MouvementStock::TYPE_SORTIE, note: $note);
    }

    /** Inventaire : fixe la quantité cible, le delta est calculé. */
    public function ajuster(Produit $produit, Entrepot $entrepot, float $nouvelleQuantite, ?string $note = null): MouvementStock
    {
        return DB::transaction(function () use ($produit, $entrepot, $nouvelleQuantite, $note) {
            $stock = $this->stockRow($produit, $entrepot);
            $delta = round($nouvelleQuantite - (float) $stock->quantite, 3);

            if (abs($delta) < 0.0005) {
                throw ValidationException::withMessages([
                    'quantite' => 'Le stock est déjà à cette quantité.',
                ]);
            }

            return $this->mouvement($produit, $entrepot, $delta, MouvementStock::TYPE_AJUSTEMENT, note: $note);
        });
    }

    /**
     * Sortie de stock générée par la validation d'une facture : une ligne de
     * mouvement par ligne produit physique (les services ne bougent pas).
     * Le stock peut passer en négatif — signalé dans l'interface, pas bloquant.
     */
    public function sortieVente(DocumentVente $document): void
    {
        $entrepot = $this->entrepotParDefaut();

        foreach ($document->lignes as $ligne) {
            if ($ligne->produit_id === null) {
                continue;
            }

            $produit = Produit::find($ligne->produit_id);

            if ($produit === null || $produit->type !== 'product') {
                continue;
            }

            $this->mouvement(
                $produit,
                $entrepot,
                -(float) $ligne->quantite,
                MouvementStock::TYPE_VENTE,
                reference: $document->code,
                documentVenteId: $document->id,
            );
        }
    }

    /**
     * Entrée de stock générée par les achats : réception validée, ou facture
     * fournisseur directe (sans document source). Une ligne de mouvement par
     * ligne produit physique, vers l'entrepôt du document (défaut sinon).
     */
    public function entreeAchat(\App\Modules\Achats\Models\DocumentAchat $document): void
    {
        $entrepot = $document->entrepot ?? $this->entrepotParDefaut();

        foreach ($document->lignes as $ligne) {
            if ($ligne->produit_id === null) {
                continue;
            }

            $produit = Produit::find($ligne->produit_id);

            if ($produit === null || $produit->type !== 'product') {
                continue;
            }

            $this->mouvement(
                $produit,
                $entrepot,
                (float) $ligne->quantite,
                MouvementStock::TYPE_ACHAT,
                reference: $document->code,
            );
        }
    }

    public function mouvement(
        Produit $produit,
        Entrepot $entrepot,
        float $delta,
        string $type,
        ?string $reference = null,
        ?int $documentVenteId = null,
        ?string $note = null,
    ): MouvementStock {
        if ($produit->type !== 'product') {
            throw ValidationException::withMessages([
                'produit_id' => 'Un service n\'a pas de stock.',
            ]);
        }

        if (abs($delta) < 0.0005) {
            throw ValidationException::withMessages([
                'quantite' => 'La quantité du mouvement ne peut pas être nulle.',
            ]);
        }

        return DB::transaction(function () use ($produit, $entrepot, $delta, $type, $reference, $documentVenteId, $note) {
            $stock = $this->stockRow($produit, $entrepot, lock: true);
            $stock->update(['quantite' => round((float) $stock->quantite + $delta, 3)]);

            return MouvementStock::create([
                'produit_id' => $produit->id,
                'entrepot_id' => $entrepot->id,
                'document_vente_id' => $documentVenteId,
                'user_id' => auth()->id(),
                'type' => $type,
                'quantite' => $delta,
                'quantite_apres' => $stock->quantite,
                'reference' => $reference,
                'note' => $note,
            ]);
        });
    }

    /* ------------------------------------------------------------------ */
    /* Transferts inter-entrepôts                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Transfère une quantité d'un entrepôt vers un autre : une sortie sur la
     * source et une entrée sur la destination, liées par une même référence
     * TRF-. Contrairement aux ventes, un transfert ne peut pas rendre la source
     * négative (on ne déplace pas des marchandises qu'on n'a pas).
     *
     * @return array{reference: string, sortie: MouvementStock, entree: MouvementStock}
     */
    public function transferer(
        Produit $produit,
        Entrepot $source,
        Entrepot $destination,
        float $quantite,
        ?string $note = null,
    ): array {
        if ($produit->type !== 'product') {
            throw ValidationException::withMessages(['produit_id' => 'Un service n\'a pas de stock.']);
        }

        if ($source->id === $destination->id) {
            throw ValidationException::withMessages([
                'entrepot_dest_id' => 'L\'entrepôt de destination doit être différent de la source.',
            ]);
        }

        if ($quantite <= 0) {
            throw ValidationException::withMessages([
                'quantite' => 'La quantité à transférer doit être supérieure à zéro.',
            ]);
        }

        return DB::transaction(function () use ($produit, $source, $destination, $quantite, $note) {
            $stockSource = $this->stockRow($produit, $source, lock: true);

            if ((float) $stockSource->quantite < $quantite) {
                throw ValidationException::withMessages([
                    'quantite' => 'Stock insuffisant dans l\'entrepôt source ('
                        .number_format((float) $stockSource->quantite, 3, '.', '').' disponible).',
                ]);
            }

            $reference = $this->sequences->next('TRF');

            $sortie = $this->mouvement($produit, $source, -$quantite, MouvementStock::TYPE_TRANSFERT, reference: $reference, note: $note);
            $entree = $this->mouvement($produit, $destination, $quantite, MouvementStock::TYPE_TRANSFERT, reference: $reference, note: $note);

            return ['reference' => $reference, 'sortie' => $sortie, 'entree' => $entree];
        });
    }

    /* ------------------------------------------------------------------ */
    /* Inventaire physique                                                 */
    /* ------------------------------------------------------------------ */

    /**
     * Ouvre une session d'inventaire sur un entrepôt et fige les quantités
     * théoriques : une ligne par produit ayant du stock recensé dans cet
     * entrepôt (le comptage se saisit ensuite, produit par produit).
     */
    public function demarrerInventaire(Entrepot $entrepot, ?string $note = null): Inventaire
    {
        return DB::transaction(function () use ($entrepot, $note) {
            $inventaire = Inventaire::create([
                'entrepot_id' => $entrepot->id,
                'user_id' => auth()->id(),
                'code' => $this->sequences->next('INV'),
                'statut' => Inventaire::STATUT_BROUILLON,
                'note' => $note,
            ]);

            $stocks = Stock::where('entrepot_id', $entrepot->id)
                ->whereHas('produit', fn ($q) => $q->where('type', 'product'))
                ->get();

            foreach ($stocks as $stock) {
                InventaireLigne::create([
                    'inventaire_id' => $inventaire->id,
                    'produit_id' => $stock->produit_id,
                    'quantite_theorique' => $stock->quantite,
                    'quantite_comptee' => null,
                ]);
            }

            return $inventaire->load('lignes.produit');
        });
    }

    /**
     * Enregistre les quantités comptées. Chaque entrée = ['produit_id', 'quantite_comptee'].
     * Un produit non encore listé (stock trouvé mais jamais recensé) est ajouté,
     * avec une quantité théorique reprise du stock courant de l'entrepôt.
     *
     * @param array<int, array{produit_id: int, quantite_comptee: float|null}> $comptages
     */
    public function enregistrerComptages(Inventaire $inventaire, array $comptages): Inventaire
    {
        $this->assertBrouillon($inventaire);

        return DB::transaction(function () use ($inventaire, $comptages) {
            foreach ($comptages as $comptage) {
                $produit = Produit::find($comptage['produit_id']);

                if ($produit === null || $produit->type !== 'product') {
                    continue;
                }

                $ligne = $inventaire->lignes()->where('produit_id', $produit->id)->first();

                if ($ligne === null) {
                    $theorique = (float) (Stock::where('produit_id', $produit->id)
                        ->where('entrepot_id', $inventaire->entrepot_id)
                        ->value('quantite') ?? 0);

                    $ligne = new InventaireLigne([
                        'inventaire_id' => $inventaire->id,
                        'produit_id' => $produit->id,
                        'quantite_theorique' => $theorique,
                    ]);
                }

                $ligne->quantite_comptee = $comptage['quantite_comptee'];
                $ligne->save();
            }

            return $inventaire->load('lignes.produit');
        });
    }

    /**
     * Valide l'inventaire : chaque ligne comptée génère, si nécessaire, un
     * ajustement de stock pour amener l'entrepôt à la quantité comptée. Le delta
     * est calculé sur le stock courant (pas la théorique figée) afin de rester
     * juste même si des mouvements ont eu lieu pendant le comptage.
     */
    public function validerInventaire(Inventaire $inventaire): Inventaire
    {
        $this->assertBrouillon($inventaire);

        return DB::transaction(function () use ($inventaire) {
            $entrepot = $inventaire->entrepot;

            $lignes = $inventaire->lignes()
                ->whereNotNull('quantite_comptee')
                ->with('produit')
                ->get();

            foreach ($lignes as $ligne) {
                $produit = $ligne->produit;

                if ($produit === null || $produit->type !== 'product') {
                    continue;
                }

                $stock = $this->stockRow($produit, $entrepot, lock: true);
                $delta = round((float) $ligne->quantite_comptee - (float) $stock->quantite, 3);

                if (abs($delta) < 0.0005) {
                    continue;
                }

                $this->mouvement(
                    $produit,
                    $entrepot,
                    $delta,
                    MouvementStock::TYPE_AJUSTEMENT,
                    reference: $inventaire->code,
                    note: 'Inventaire '.$inventaire->code,
                );
            }

            $inventaire->update([
                'statut' => Inventaire::STATUT_VALIDE,
                'validated_at' => now(),
            ]);

            return $inventaire->refresh()->load('lignes.produit');
        });
    }

    public function supprimerInventaire(Inventaire $inventaire): void
    {
        $this->assertBrouillon($inventaire, 'Un inventaire validé ne peut pas être supprimé.');

        $inventaire->delete();
    }

    private function assertBrouillon(Inventaire $inventaire, string $message = 'Cet inventaire est déjà validé.'): void
    {
        if ($inventaire->statut !== Inventaire::STATUT_BROUILLON) {
            throw ValidationException::withMessages(['inventaire' => $message]);
        }
    }

    private function stockRow(Produit $produit, Entrepot $entrepot, bool $lock = false): Stock
    {
        $stock = Stock::firstOrCreate(
            ['produit_id' => $produit->id, 'entrepot_id' => $entrepot->id],
            ['quantite' => 0],
        );

        return $lock ? Stock::whereKey($stock->id)->lockForUpdate()->first() : $stock;
    }
}
