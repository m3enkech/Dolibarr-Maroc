<?php

namespace App\Modules\Integrations\Console;

use App\Core\Tenancy\TenantContext;
use App\Modules\Integrations\Zoho\ImportTiersZoho;
use App\Modules\Integrations\Zoho\ZohoBooksClient;

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
 *
 * C'est la PREMIÈRE reprise à jouer : les factures ont besoin de leurs clients.
 */
class ImporterTiersZohoCommand extends CommandeImportZoho
{
    protected $signature = 'zoho:import-tiers
        {email : un utilisateur de l\'entreprise de destination}
        {--simulation : lit Zoho et affiche le résultat sans rien écrire}
        {--journal= : écrit le détail ligne à ligne dans ce fichier CSV}';

    protected $description = 'Importe les clients et fournisseurs depuis Zoho Books';

    public function handle(TenantContext $contexte, ZohoBooksClient $zoho, ImportTiersZoho $import): int
    {
        if ($this->preparer($contexte, $zoho) === null) {
            return self::FAILURE;
        }

        $rapport = $import->executer($this->simulation(), $this->compteur('contacts'));

        $this->afficherTotaux($rapport);

        // Ce qui est entré SANS ICE mérite un coup d'œil : le rapprochement s'est
        // fait sur le nom, donc c'est là que d'éventuels doublons se cachent.
        $sansIce = array_filter($rapport['details'], fn ($d) => ($d['raison'] ?? '') === 'nouveau, SANS ICE');

        if ($sansIce !== []) {
            $this->warn(sprintf(
                '%d tiers créés SANS ICE : rapprochés sur le nom, à vérifier.',
                count($sansIce),
            ));
        }

        return $this->terminer($rapport['details']);
    }

    protected function colonnesJournal(): array
    {
        return ['zoho_id', 'nom', 'ice', 'action', 'raison'];
    }
}
