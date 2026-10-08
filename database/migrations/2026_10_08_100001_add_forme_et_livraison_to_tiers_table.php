<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deux informations que la fiche d'un tiers ne savait pas porter.
 *
 * LA FORME : entreprise ou particulier. Tout l'existant devient « entreprise »
 * par le défaut de la colonne, et RIEN n'est déduit de l'ICE : un tiers sans
 * ICE peut être une société dont on n'a pas encore l'identifiant (c'est le cas
 * des reprises Zoho dont l'ICE n'avait pas 15 chiffres), et un auto-entrepreneur
 * en a un. Deviner la forme aurait rangé des sociétés parmi les particuliers
 * sans que personne ne l'ait décidé.
 *
 * L'ADRESSE DE LIVRAISON, distincte de la facturation : des COLONNES sur le
 * tiers, pas une table. Une seule adresse de livraison par tiers, comme chez
 * Zoho : les pièces de vente n'ont aucune colonne d'adresse, et plusieurs
 * adresses n'auraient de sens qu'avec un choix de l'adresse SUR CHAQUE PIÈCE
 * (le bon de livraison de tel magasin) — un autre chantier. Une table vide
 * de ce choix ne servirait qu'à afficher une liste.
 *
 * `livraison_identique` vrai par défaut : l'existant se livre où il se facture,
 * ce qu'on faisait déjà implicitement. Les trois champs ne sont lus que lorsque
 * le drapeau est faux (TiersService les vide sinon). Pas de pays : celui de la
 * facturation n'est pas saisissable non plus, et la livraison est locale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tiers', function (Blueprint $table) {
            $table->string('forme', 12)->default('entreprise')->after('name');

            $table->boolean('livraison_identique')->default(true)->after('country');
            $table->string('adresse_livraison')->nullable()->after('livraison_identique');
            $table->string('ville_livraison', 100)->nullable()->after('adresse_livraison');
            $table->string('code_postal_livraison', 10)->nullable()->after('ville_livraison');
        });
    }

    public function down(): void
    {
        Schema::table('tiers', function (Blueprint $table) {
            $table->dropColumn(['forme', 'livraison_identique', 'adresse_livraison', 'ville_livraison', 'code_postal_livraison']);
        });
    }
};
