<?php

namespace App\Modules\Compta\Console;

use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Compta\Services\LettrageService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Diagnostic des groupes lettrés qui mêlent plusieurs tiers.
 *
 *   php artisan compta:verifier-lettrage client@exemple.ma
 *
 * Jusqu'au lot 4 de la fiche tiers, le lettrage ne contrôlait que le COMPTE :
 * la facture d'un client pouvait être lettrée avec le règlement d'un autre, ou
 * avec une ligne d'à-nouveau sans tiers. Le groupe s'annule sur le compte,
 * pas chez le client — la liste des tiers (lignes non lettrées) et son relevé
 * (toutes) ne disent alors plus le même solde. La règle refuse désormais ces
 * groupes ; cette commande recense ceux qui existaient déjà.
 *
 * LECTURE SEULE, sans option de correction : délettrer puis relettrer tiers
 * par tiers est un choix du comptable, qui sait ce que le groupe voulait dire.
 */
class VerifierLettrageCommand extends Command
{
    protected $signature = 'compta:verifier-lettrage
        {email : un utilisateur de l\'entreprise concernée}';

    protected $description = 'Lecture seule : groupes lettrés qui mêlent plusieurs tiers (ou un tiers et une ligne sans tiers)';

    public function handle(TenantContext $contexte, LettrageService $lettrage): int
    {
        $user = User::withoutGlobalScopes()->where('email', $this->argument('email'))->first();

        if ($user === null || $user->tenant === null) {
            $this->error("Utilisateur ou entreprise introuvable pour : {$this->argument('email')}");

            return self::FAILURE;
        }

        // Contexte posé EXPLICITEMENT : sans lui, le scope multi-entreprises
        // est fail-closed et la commande conclurait « rien à signaler » sur un
        // périmètre vide — le pire des faux rassurants.
        $groupes = $contexte->runAs($user->tenant, fn () => $lettrage->groupesMelanges());

        $this->line(sprintf('Lettrage de « %s » — lecture seule, rien ne sera modifié.', OutputFormatter::escape($user->tenant->name)));

        if ($groupes === []) {
            $this->info('Aucun groupe lettré ne mêle plusieurs tiers : la liste des tiers et les relevés disent le même solde.');

            return self::SUCCESS;
        }

        foreach ($groupes as $groupe) {
            $this->newLine();
            $this->line(sprintf('<options=bold>Compte %s, lettrage %s</> (%d lignes)', $groupe['compte'], $groupe['code'], $groupe['lignes']));

            foreach ($groupe['tiers'] as $tiers) {
                $this->line(sprintf(
                    '    %-40s %s',
                    $tiers['tiers_id'] === null ? '(sans tiers)' : OutputFormatter::escape(($tiers['nom'] ?? '?').' #'.$tiers['tiers_id']),
                    abs($tiers['ecart']) < 0.005
                        ? 'équilibré chez lui'
                        : sprintf('écart %s %s', number_format(abs($tiers['ecart']), 2, ',', ' '), $tiers['ecart'] > 0 ? 'débiteur' : 'créditeur'),
                ));
            }
        }

        $this->newLine();
        $this->warn(sprintf('%d groupe(s) mêlent plusieurs tiers.', count($groupes)));
        $this->line('Chez chaque tiers en écart, la liste (lignes non lettrées) et le relevé (toutes les lignes) divergent '
            .'de ce montant. À corriger par le comptable : délettrer le groupe (écran Lettrage), puis relettrer tiers par '
            .'tiers ; face à une ligne sans tiers, saisir d\'abord le solde d\'ouverture du tiers depuis sa fiche.');

        return self::SUCCESS;
    }
}
