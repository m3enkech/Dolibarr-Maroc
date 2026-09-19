<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Le `contact_name` de chaque tiers devient son premier contact.
 *
 * Sans cette reprise, ouvrir la nouvelle fiche donnerait « aucun contact » à
 * des tiers qui en portaient un depuis des mois : les gens concluraient que la
 * donnée a été perdue, et ils auraient raison de s'en inquiéter.
 *
 * La colonne `tiers.contact_name` est CONSERVÉE, volontairement. La supprimer
 * ici rendrait la migration irréversible en pratique — et surtout, les
 * formulaires, l'export et les documents la lisent encore. Elle deviendra
 * redondante, puis on la retirera dans un second temps, une fois tous ses
 * lecteurs déplacés. Une migration de données et une migration de schéma ne se
 * jouent pas le même jour.
 *
 * Écrit en SQL, sans modèle : un modèle porte `BelongsToTenant`, dont le scope
 * est fail-closed — hors requête HTTP il n'y a aucun tenant courant, et la
 * migration ne verrait donc AUCUNE ligne. Le piège classique de ce projet.
 */
return new class extends Migration
{
    public function up(): void
    {
        $maintenant = now();

        DB::table('tiers')
            ->whereNotNull('contact_name')
            ->where('contact_name', '!=', '')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->chunk(500, function ($tiers) use ($maintenant) {
                $lignes = [];

                foreach ($tiers as $t) {
                    $nom = trim((string) $t->contact_name);

                    if ($nom === '') {
                        continue;
                    }

                    $lignes[] = [
                        'tenant_id' => $t->tenant_id,
                        'tiers_id' => $t->id,
                        'nom' => mb_substr($nom, 0, 255),
                        // Seul contact du tiers, donc principal : c'est lui que
                        // les documents viseront.
                        'is_principal' => true,
                        'is_active' => true,
                        'created_at' => $maintenant,
                        'updated_at' => $maintenant,
                    ];
                }

                if ($lignes !== []) {
                    DB::table('contacts')->insert($lignes);
                }
            });
    }

    public function down(): void
    {
        // On ne retire que ce que la reprise a posé : un contact principal dont
        // le nom est encore exactement celui du tiers. Tout ce qui a été saisi
        // à la main depuis reste en place.
        DB::table('contacts')
            ->where('is_principal', true)
            ->whereIn('id', function ($q) {
                $q->select('c.id')
                    ->from('contacts as c')
                    ->join('tiers as t', 't.id', '=', 'c.tiers_id')
                    ->whereColumn('c.nom', 't.contact_name');
            })
            ->delete();
    }
};
