<?php

namespace App\Modules\Compta\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EcritureResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'journal' => $this->journal,
            'numero' => $this->numero,
            'date_ecriture' => $this->date_ecriture?->format('Y-m-d'),
            'libelle' => $this->libelle,
            'reference' => $this->reference,
            'is_auto' => $this->is_auto,
            'lignes' => $this->whenLoaded('lignes', fn () => $this->lignes->map(fn ($ligne) => [
                'id' => $ligne->id,
                'compte_code' => $ligne->compte?->code,
                'compte_label' => $ligne->compte?->label,
                'libelle' => $ligne->libelle,
                'debit' => $ligne->debit,
                'credit' => $ligne->credit,
            ])),
            'total_debit' => $this->whenLoaded('lignes', fn () => number_format(
                (float) $this->lignes->sum('debit'), 2, '.', '',
            )),
            'created_at' => $this->created_at,
        ];
    }
}
