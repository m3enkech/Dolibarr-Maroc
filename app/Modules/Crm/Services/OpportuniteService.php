<?php

namespace App\Modules\Crm\Services;

use App\Core\Sequences\SequenceService;
use App\Modules\Crm\Models\Opportunite;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OpportuniteService
{
    public function __construct(private SequenceService $sequences) {}

    public function creer(array $data): Opportunite
    {
        $etape = $data['etape'] ?? Opportunite::ETAPES[0];

        return Opportunite::create([
            'tiers_id' => $data['tiers_id'],
            'user_id' => auth()->id(),
            'code' => $this->sequences->next('OPP'),
            'titre' => $data['titre'],
            'montant_estime' => $data['montant_estime'] ?? 0,
            'probabilite' => $data['probabilite'] ?? 50,
            'etape' => $etape,
            'statut' => Opportunite::STATUT_OUVERTE,
            'position' => $this->prochainePosition($etape),
            'date_cloture_prevue' => $data['date_cloture_prevue'] ?? null,
            'note' => $data['note'] ?? null,
        ]);
    }

    public function modifier(Opportunite $opportunite, array $data): Opportunite
    {
        $opportunite->update(collect($data)->only([
            'tiers_id', 'titre', 'montant_estime', 'probabilite', 'date_cloture_prevue', 'note',
        ])->all());

        return $opportunite->refresh();
    }

    /** Déplacement dans le pipeline (glisser-déposer d'une colonne à l'autre). */
    public function deplacer(Opportunite $opportunite, string $etape): Opportunite
    {
        if (! in_array($etape, Opportunite::ETAPES, true)) {
            throw ValidationException::withMessages(['etape' => 'Étape de pipeline invalide.']);
        }

        if (! $opportunite->isOuverte()) {
            throw ValidationException::withMessages(['statut' => 'Une opportunité clôturée ne peut plus être déplacée.']);
        }

        $opportunite->update(['etape' => $etape, 'position' => $this->prochainePosition($etape)]);

        return $opportunite->refresh();
    }

    public function cloturer(Opportunite $opportunite, string $statut): Opportunite
    {
        if (! in_array($statut, [Opportunite::STATUT_GAGNEE, Opportunite::STATUT_PERDUE], true)) {
            throw ValidationException::withMessages(['statut' => 'Statut de clôture invalide.']);
        }

        $opportunite->update([
            'statut' => $statut,
            'probabilite' => $statut === Opportunite::STATUT_GAGNEE ? 100 : 0,
            'close_at' => now(),
        ]);

        return $opportunite->refresh();
    }

    public function rouvrir(Opportunite $opportunite): Opportunite
    {
        $opportunite->update([
            'statut' => Opportunite::STATUT_OUVERTE,
            'close_at' => null,
            'position' => $this->prochainePosition($opportunite->etape),
        ]);

        return $opportunite->refresh();
    }

    public function supprimer(Opportunite $opportunite): void
    {
        $opportunite->delete();
    }

    private function prochainePosition(string $etape): int
    {
        return (int) Opportunite::where('etape', $etape)
            ->where('statut', Opportunite::STATUT_OUVERTE)
            ->max('position') + 1;
    }
}
