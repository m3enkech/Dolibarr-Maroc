<?php

namespace App\Modules\Integrations\Console;

use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Integrations\Zoho\ImportTiersZoho;
use App\Modules\Integrations\Zoho\ZohoBooksClient;
use Illuminate\Console\Command;

/**
 * Rapatrie les clients et fournisseurs de Zoho Books.
 *
 *   php artisan zoho:import-tiers client@exemple.ma --simulation
 *   php artisan zoho:import-tiers client@exemple.ma
 *
 * L'entreprise de destination est celle de l'utilisateur donné : sans contexte
 * d'entreprise, le scope multi-entreprises est fail-closed et rien ne s'écrirait.
 *
 * `--simulation` lit tout et n'écrit rien. C'est le mode à jouer en premier :
 * il montre exactement ce qui serait créé, complété ou écarté.
 */
class ImporterTiersZohoCommand extends Command
{
    protected $signature = 'zoho:import-tiers
        {email : un utilisateur de l\'entreprise de destination}
        {--simulation : lit Zoho et affiche le résultat sans rien écrire}
        {--journal= : écrit le détail ligne à ligne dans ce fichier CSV}';

    protected $description = 'Importe les clients et fournisseurs depuis Zoho Books';

    public function handle(TenantContext $contexte, ZohoBooksClient $zoho, ImportTiersZoho $import): int
    {
        if (! $zoho->estConfigure()) {
            $this->error('Zoho Books n\'est pas configuré. Renseignez dans .env : ZOHO_CLIENT_ID, '
                .'ZOHO_CLIENT_SECRET, ZOHO_REFRESH_TOKEN et ZOHO_BOOKS_ORGANIZATION_ID.');

            return self::FAILURE;
        }

        $user = User::withoutGlobalScopes()->where('email', $this->argument('email'))->first();

        if ($user === null || $user->tenant === null) {
            $this->error("Utilisateur ou entreprise introuvable pour : {$this->argument('email')}");

            return self::FAILURE;
        }

        $contexte->set($user->tenant);

        $simulation = (bool) $this->option('simulation');

        $this->line(sprintf(
            '%s vers « %s ».',
            $simulation ? 'SIMULATION — aucune écriture' : 'Import',
            $user->tenant->name,
        ));

        $vus = 0;
        $rapport = $import->executer($simulation, function () use (&$vus) {
            $vus++;
            if ($vus % 50 === 0) {
                $this->line("  … {$vus} contacts examinés");
            }
        });

        $this->newLine();
        $this->table(
            ['Créés', 'Complétés', 'Inchangés', 'Total'],
            [[
                $rapport['crees'],
                $rapport['mis_a_jour'],
                $rapport['inchanges'],
                $rapport['crees'] + $rapport['mis_a_jour'] + $rapport['inchanges'],
            ]],
        );

        // Ce qui est entré SANS ICE mérite un coup d'œil : le rapprochement s'est
        // fait sur le nom, donc c'est là que d'éventuels doublons se cachent.
        $sansIce = array_filter($rapport['details'], fn ($d) => ($d['raison'] ?? '') === 'nouveau, SANS ICE');

        if ($sansIce !== []) {
            $this->warn(sprintf(
                '%d tiers créés SANS ICE : rapprochés sur le nom, à vérifier.',
                count($sansIce),
            ));
        }

        if ($chemin = $this->option('journal')) {
            $this->ecrireJournal($chemin, $rapport['details']);
            $this->info("Détail ligne à ligne écrit dans {$chemin}");
        }

        if ($simulation) {
            $this->newLine();
            $this->comment('Rien n\'a été écrit. Relancez sans --simulation pour appliquer.');
        }

        return self::SUCCESS;
    }

    /** @param  list<array<string, string>>  $details */
    private function ecrireJournal(string $chemin, array $details): void
    {
        $fichier = fopen($chemin, 'w');
        fputcsv($fichier, ['zoho_id', 'nom', 'ice', 'action', 'raison']);

        foreach ($details as $ligne) {
            fputcsv($fichier, [
                $ligne['zoho_id'] ?? '',
                $ligne['nom'] ?? '',
                $ligne['ice'] ?? '',
                $ligne['action'] ?? '',
                $ligne['raison'] ?? '',
            ]);
        }

        fclose($fichier);
    }
}
