<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marque l'écriture qui porte le SOLDE D'OUVERTURE d'un tiers.
 *
 * La marque vit sur l'ÉCRITURE, pas sur le tiers : c'est un fait comptable,
 * et elle disparaît avec l'écriture si celle-ci est un jour supprimée — un
 * pointeur posé sur le tiers survivrait à son écriture et interdirait d'en
 * ressaisir une.
 *
 * L'index UNIQUE est la vraie garde du « un seul solde d'ouverture par
 * tiers » : le contrôle préalable du service donne le message lisible, mais
 * deux clics simultanés passent tous deux un contrôle applicatif. SQLite et
 * PostgreSQL admettent tous deux plusieurs NULL sous un index unique : les
 * autres écritures, qui n'ont pas de marque, ne se gênent pas. Les ids de
 * tiers étant globaux, l'unicité n'a pas besoin de l'entreprise.
 *
 * `nullOnDelete` par principe : un tiers qui a des écritures ne se supprime
 * pas (TiersController::destroy), et sa suppression est douce.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ecritures', function (Blueprint $table) {
            $table->foreignId('solde_ouverture_tiers_id')->nullable()->after('document_vente_id')
                ->constrained('tiers')->nullOnDelete();
            $table->unique('solde_ouverture_tiers_id', 'ecritures_solde_ouverture_tiers_unique');
        });
    }

    public function down(): void
    {
        Schema::table('ecritures', function (Blueprint $table) {
            $table->dropUnique('ecritures_solde_ouverture_tiers_unique');
            $table->dropConstrainedForeignId('solde_ouverture_tiers_id');
        });
    }
};
