<?php

namespace App\Modules\Integrations\Console;

use App\Core\Tenancy\TenantContext;
use App\Modules\Integrations\Zoho\ImportArticlesZoho;
use App\Modules\Integrations\Zoho\ZohoBooksClient;

/**
 * Rapatrie le catalogue de Zoho Books.
 *
 *   php artisan zoho:import-articles client@exemple.ma --simulation
 *   php artisan zoho:import-articles client@exemple.ma
 *
 * À jouer APRÈS les tiers et AVANT les factures : une ligne de facture qui ne
 * retrouve pas son article reste un simple libellé, invisible pour le stock et
 * pour le réappro.
 */
class ImporterArticlesZohoCommand extends CommandeImportZoho
{
    protected $signature = 'zoho:import-articles
        {email : un utilisateur de l\'entreprise de destination}
        {--simulation : lit Zoho et affiche le résultat sans rien écrire}
        {--journal= : écrit le détail ligne à ligne dans ce fichier CSV}';

    protected $description = 'Importe le catalogue (articles) depuis Zoho Books';

    public function handle(TenantContext $contexte, ZohoBooksClient $zoho, ImportArticlesZoho $import): int
    {
        if ($this->preparer($contexte, $zoho) === null) {
            return self::FAILURE;
        }

        $rapport = $import->executer($this->simulation(), $this->compteur('articles'));

        $this->afficherTotaux($rapport);

        // Une référence attribuée par la séquence, c'est un SKU que les équipes
        // ne retrouveront pas : elles chercheront « T-TN211 » et liront
        // « PR-0042 ». Ça se corrige à la main, encore faut-il le savoir.
        $sansSku = array_filter(
            $rapport['details'],
            fn ($d) => str_starts_with($d['raison'] ?? '', 'référence attribuée'),
        );

        if ($sansSku !== []) {
            $this->warn(sprintf(
                '%d articles n\'ont pas gardé leur SKU comme référence (voir la colonne « raison » du journal).',
                count($sansSku),
            ));
        }

        return $this->terminer($rapport['details']);
    }

    protected function colonnesJournal(): array
    {
        return ['zoho_id', 'sku', 'nom', 'action', 'raison'];
    }
}
