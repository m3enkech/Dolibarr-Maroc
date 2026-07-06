<?php

namespace App\Modules\Crm\Services;

use App\Modules\Crm\Models\Activite;

class ActiviteService
{
    public function creer(array $data): Activite
    {
        $fait = (bool) ($data['fait'] ?? false);

        return Activite::create([
            'tiers_id' => $data['tiers_id'],
            'opportunite_id' => $data['opportunite_id'] ?? null,
            'user_id' => auth()->id(),
            'type' => $data['type'],
            'sujet' => $data['sujet'],
            'note' => $data['note'] ?? null,
            'date_prevue' => $data['date_prevue'] ?? null,
            'fait' => $fait,
            'fait_at' => $fait ? now() : null,
        ]);
    }

    public function modifier(Activite $activite, array $data): Activite
    {
        $activite->update(collect($data)->only([
            'type', 'sujet', 'note', 'date_prevue', 'opportunite_id',
        ])->all());

        return $activite->refresh();
    }

    /** Bascule l'état « fait » (une tâche cochée / décochée). */
    public function basculerFait(Activite $activite): Activite
    {
        $fait = ! $activite->fait;
        $activite->update(['fait' => $fait, 'fait_at' => $fait ? now() : null]);

        return $activite->refresh();
    }

    public function supprimer(Activite $activite): void
    {
        $activite->delete();
    }
}
