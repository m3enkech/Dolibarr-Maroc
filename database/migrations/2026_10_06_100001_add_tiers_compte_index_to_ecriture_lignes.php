<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le solde client se lit sur `ecriture_lignes` par (tiers, compte de créance).
 * La table n'avait d'index que sur `compte_id` et (compte_id, lettrage) :
 * `tiers_id` n'y portait qu'une clé étrangère, et PostgreSQL — la production —
 * ne crée PAS d'index derrière une clé étrangère.
 *
 * Chaque calcul d'encours (caisse, portail, /encours) relisait donc TOUT le
 * compte clients de l'entreprise pour n'en garder que quelques tiers. La liste
 * des tiers affiche désormais ce solde à chaque page : sans cet index, chaque
 * page paierait le balayage complet du compte le plus chargé du grand livre.
 *
 * `tiers_id` en tête : c'est lui qui trie, une page ne vise que quelques
 * dizaines de tiers parmi des milliers ; `compte_id` ne fait ensuite que
 * départager 3421 de 3425.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ecriture_lignes', function (Blueprint $table) {
            $table->index(['tiers_id', 'compte_id'], 'ecriture_lignes_tiers_compte_index');
        });
    }

    public function down(): void
    {
        Schema::table('ecriture_lignes', function (Blueprint $table) {
            $table->dropIndex('ecriture_lignes_tiers_compte_index');
        });
    }
};
