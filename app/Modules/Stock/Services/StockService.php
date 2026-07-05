<?php

namespace App\Modules\Stock\Services;

use App\Core\Sequences\SequenceService;
use App\Modules\Catalogue\Models\Produit;
use App\Modules\Stock\Models\Entrepot;
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

    private function stockRow(Produit $produit, Entrepot $entrepot, bool $lock = false): Stock
    {
        $stock = Stock::firstOrCreate(
            ['produit_id' => $produit->id, 'entrepot_id' => $entrepot->id],
            ['quantite' => 0],
        );

        return $lock ? Stock::whereKey($stock->id)->lockForUpdate()->first() : $stock;
    }
}
