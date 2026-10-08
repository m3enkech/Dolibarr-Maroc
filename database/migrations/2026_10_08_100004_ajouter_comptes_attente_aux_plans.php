<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pose 3497 et 4497 (comptes d'attente, contrepartie des soldes d'ouverture
 * saisis tiers par tiers) dans le plan de CHAQUE entreprise qui en a un.
 *
 * Le rattrapage d'initialiserPlanComptable les aurait ajoutés tout seul, mais
 * à la volée, pendant un GET — et juste après le déploiement, la fiche d'un
 * tiers lance en parallèle la liste, la synthèse et la vue d'ensemble : deux
 * requêtes inséraient le même 3497, la seconde butait sur unique(tenant_id,
 * code) et répondait 500. Posés ici, aucune lecture n'écrit plus rien.
 *
 * `insertOrIgnore` : une entreprise peut déjà avoir l'un de ces codes, créé à
 * la main ou par l'import d'une balance d'ouverture — on le laisse tel quel.
 * Seules les entreprises qui ont un plan : les autres le recevront en entier
 * à sa première utilisation, comme avant. Libellés recopiés de
 * PlanComptableMarocain et non lus chez lui : une migration ne doit pas
 * changer de sens le jour où la classe change.
 */
return new class extends Migration
{
    private const COMPTES = [
        ['3497', 'Comptes transitoires ou d\'attente — débiteurs', 3],
        ['4497', 'Comptes transitoires ou d\'attente — créditeurs', 4],
    ];

    public function up(): void
    {
        $maintenant = now();

        DB::table('comptes')->distinct()->orderBy('tenant_id')->pluck('tenant_id')
            ->chunk(500)
            ->each(function ($entreprises) use ($maintenant) {
                $lignes = [];

                foreach ($entreprises as $tenantId) {
                    foreach (self::COMPTES as [$code, $libelle, $classe]) {
                        $lignes[] = [
                            'tenant_id' => $tenantId,
                            'code' => $code,
                            'label' => $libelle,
                            'classe' => $classe,
                            'is_system' => true,
                            'is_active' => true,
                            'created_at' => $maintenant,
                            'updated_at' => $maintenant,
                        ];
                    }
                }

                DB::table('comptes')->insertOrIgnore($lignes);
            });
    }

    /**
     * Rien : ces comptes portent peut-être déjà des écritures, et les retirer
     * casserait le grand livre (ou la clé étrangère des lignes).
     */
    public function down(): void {}
};
