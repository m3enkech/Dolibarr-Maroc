<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D'où vient cet enregistrement, et quel était son identifiant là-bas.
 *
 * Sans ces deux colonnes, une reprise n'est jouable QU'UNE FOIS. Le premier
 * import des tiers l'a montré : il fallait rapprocher sur l'ICE puis sur le nom
 * normalisé, faute de mieux — deux heuristiques, donc deux façons de se
 * tromper. Pour les factures, le problème devient bloquant : mille trois cents
 * pièces à rapatrier une par une, un réseau qui lâche au milieu, et il faut
 * pouvoir relancer sans créer mille doublons.
 *
 * L'index unique porte le tenant : deux entreprises peuvent très bien reprendre
 * chacune leur propre Zoho Books, avec des identifiants qui se ressemblent.
 *
 * Les valeurs nulles ne se gênent pas entre elles — PostgreSQL comme SQLite
 * tiennent NULL pour distinct de NULL dans un index unique. Les enregistrements
 * saisis à la main (l'immense majorité) restent donc libres de toute contrainte.
 */
return new class extends Migration
{
    /** Tables qui peuvent venir d'ailleurs. */
    private const TABLES = ['tiers', 'produits', 'documents_vente'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                // « zoho_books » aujourd'hui ; d'autres passerelles viendront.
                $blueprint->string('source_systeme', 20)->nullable();
                $blueprint->string('source_id', 64)->nullable();

                $blueprint->unique(
                    ['tenant_id', 'source_systeme', 'source_id'],
                    $this->nomIndex($table),
                );
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropUnique($this->nomIndex($table));
                $blueprint->dropColumn(['source_systeme', 'source_id']);
            });
        }
    }

    /** Nom explicite : celui que Laravel dériverait dépasse la limite d'Oracle-like. */
    private function nomIndex(string $table): string
    {
        return $table.'_source_unique';
    }
};
