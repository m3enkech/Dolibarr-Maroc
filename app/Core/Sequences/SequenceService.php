<?php

namespace App\Core\Sequences;

use App\Core\Tenancy\TenantScope;
use Illuminate\Support\Facades\DB;

/**
 * Numérotation de documents par tenant et par année : CL-2026-00001, FA-2026-00042…
 * Chaque module consomme ce service, personne ne réimplémente sa propre séquence.
 *
 * Le tenant est résolu à l'appel (jamais capturé dans le constructeur) :
 * les contrôleurs étant mis en cache par le routeur, un contexte injecté
 * à la construction resterait figé sur le premier tenant servi.
 */
class SequenceService
{
    public function next(string $code, ?int $year = null): string
    {
        $year ??= now()->year;
        $tenantId = TenantScope::currentTenantId();

        if ($tenantId === null) {
            throw new \RuntimeException('SequenceService requiert un tenant courant.');
        }

        return DB::transaction(function () use ($code, $year, $tenantId) {
            $sequence = DB::table('sequences')
                ->where('tenant_id', $tenantId)
                ->where('code', $code)
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            if ($sequence === null) {
                $counter = 1;
                DB::table('sequences')->insert([
                    'tenant_id' => $tenantId,
                    'code' => $code,
                    'year' => $year,
                    'counter' => $counter,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $counter = $sequence->counter + 1;
                DB::table('sequences')
                    ->where('id', $sequence->id)
                    ->update(['counter' => $counter, 'updated_at' => now()]);
            }

            return sprintf('%s-%d-%05d', $code, $year, $counter);
        });
    }
}
