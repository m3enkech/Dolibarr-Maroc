<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le fil de commentaires d'un tiers : ce que l'équipe doit savoir et qui ne
 * tient dans aucun champ — « ne livre que le matin », « remise promise de 5 %
 * sur la prochaine commande », « le gérant ne répond qu'au mobile ».
 *
 * UNE TABLE À ELLE, PAS LES ACTIVITÉS DU CRM. Le module CRM est désactivé par
 * défaut, et ses routes répondent 403 au comptable et au caissier : y ranger
 * les commentaires les aurait cachés à la moitié de l'équipe. Ils vivent sous
 * la garde `tiers`, comme la fiche.
 *
 * `auteur_nom` est une COPIE, prise à l'écriture. Retirer un membre de
 * l'équipe SUPPRIME sa ligne `users` (EquipeService) : `user_id` passe alors
 * à NULL, et sans cette copie chaque note d'un commercial parti deviendrait
 * anonyme — l'historique même que le fil doit garder. Tant que l'auteur
 * existe, c'est son nom actuel qui s'affiche.
 *
 * SUPPRIMER EFFACE LE TEXTE, PAS LA TRACE. La raison d'être du droit de
 * l'administrateur est la donnée personnelle collée par erreur (« CIN
 * BE123456, RIB … ») : la garder en base, puis dans chaque sauvegarde, sans
 * route ni purge pour l'en retirer, c'est ne pas tenir le droit d'effacement
 * de la loi 09-08. Le contenu est donc VIDÉ. Ce qui reste — la ligne, son
 * auteur, sa date, et QUI l'a supprimée et quand (`supprime_par_*`,
 * `deleted_at`) — garde la mémoire que le fil doit avoir : un administrateur
 * qui retire la promesse d'un collègue laisse son nom. `supprime_par_nom` est
 * une copie pour la même raison qu'`auteur_nom`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tiers_commentaires', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tiers_id')->constrained('tiers')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('auteur_nom');
            $table->text('contenu');
            $table->foreignId('supprime_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('supprime_par_nom')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // La seule lecture : les commentaires d'un tiers, du plus récent
            // au plus ancien, page par page (curseur sur l'id). La même clé
            // sert le compteur de l'onglet. PostgreSQL n'indexe pas une clé
            // étrangère tout seul.
            $table->index(['tenant_id', 'tiers_id', 'id']);

            // Retirer un membre de l'équipe supprime sa ligne `users`, et ON
            // DELETE SET NULL cherche alors SES commentaires — sans index,
            // tout le fil de toutes les entreprises, dans la transaction de
            // suppression. tiers_id seul ne sert qu'à la suppression
            // définitive d'un tiers, rare : pas d'index pour lui.
            $table->index('user_id');
            $table->index('supprime_par_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiers_commentaires');
    }
};
