<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rang d'une pièce dans le rejeu du stock de sa famille de documents.
 *
 * La sortie de stock d'un BL ou d'une facture se déduit en REJOUANT les pièces
 * déjà validées de sa famille, dans l'ordre où elles l'ont été : un avoir suivi
 * d'un BL ne dit pas la même chose qu'un BL suivi d'un avoir. Trier par
 * `validated_at` ne suffit pas — la colonne est à la seconde, horodatée AVANT
 * l'attente des verrous de séquence et de famille, et l'égalité se départageait
 * par l'identifiant, c'est-à-dire par l'ordre de création des BROUILLONS. Deux
 * validations successives dans la même seconde suffisaient à faire sortir le
 * stock deux fois, ou pas du tout.
 *
 * Le rang est attribué sous le verrou de la famille (1 + le plus grand rang de
 * ses pièces) : il suit l'ordre réel de sérialisation. NULL pour les pièces
 * d'avant cette colonne et pour la racine, toujours validée la première.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents_vente', function (Blueprint $table) {
            $table->unsignedInteger('rang_stock')->nullable()->after('validated_at');
        });
    }

    public function down(): void
    {
        Schema::table('documents_vente', function (Blueprint $table) {
            $table->dropColumn('rang_stock');
        });
    }
};
