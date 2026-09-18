<?php

namespace App\Modules\Integrations\Console;

use App\Core\Tenancy\TenantContext;
use App\Modules\Integrations\Zoho\ImportFacturesZoho;
use App\Modules\Integrations\Zoho\ZohoBooksClient;

/**
 * Rapatrie les factures de vente de Zoho Books.
 *
 *   php artisan zoho:import-factures client@exemple.ma --simulation --journal=factures.csv
 *   php artisan zoho:import-factures client@exemple.ma
 *
 * À jouer EN DERNIER : les factures ont besoin de leurs clients (zoho:import-tiers)
 * et de leurs articles (zoho:import-articles).
 *
 * Comptez du temps : le détail de chaque facture demande un appel à Zoho, et
 * Books n'en donne pas les lignes autrement. L'import est rejouable — une
 * facture déjà reprise ne coûte même pas son appel — donc une interruption ne
 * fait perdre que la pièce en cours.
 */
class ImporterFacturesZohoCommand extends CommandeImportZoho
{
    protected $signature = 'zoho:import-factures
        {email : un utilisateur de l\'entreprise de destination}
        {--simulation : joue tout puis annule ; affiche le résultat exact sans rien garder}
        {--depuis= : ne reprend que les factures datées de ce jour ou après (AAAA-MM-JJ)}
        {--avec-stock : sort aussi la marchandise du stock (voir l\'avertissement)}
        {--sans-paiements : n\'enregistre aucun règlement, toutes les factures resteront dues}
        {--journal= : écrit le détail ligne à ligne dans ce fichier CSV}';

    protected $description = 'Importe les factures de vente depuis Zoho Books';

    public function handle(TenantContext $contexte, ZohoBooksClient $zoho, ImportFacturesZoho $import): int
    {
        if ($this->preparer($contexte, $zoho) === null) {
            return self::FAILURE;
        }

        $avecStock = (bool) $this->option('avec-stock');

        $this->expliquerLeStock($avecStock);

        $rapport = $import->executer([
            'simulation' => $this->simulation(),
            'avec_stock' => $avecStock,
            'avec_paiements' => ! $this->option('sans-paiements'),
            'depuis' => $this->option('depuis') ?: null,
        ], $this->compteur('factures'));

        $this->afficherLeBilan($rapport);

        return $this->terminer($rapport['details']);
    }

    protected function colonnesJournal(): array
    {
        return ['zoho_id', 'numero', 'date', 'client', 'total', 'action', 'raison'];
    }

    /* ------------------------------------------------------------------ */

    /**
     * Le point où la reprise peut abîmer des données, donc le seul qui mérite
     * d'être dit avant de commencer plutôt qu'après.
     */
    private function expliquerLeStock(bool $avecStock): void
    {
        if ($avecStock) {
            $this->warn('Le stock VA être décrémenté par chaque facture reprise. Sans historique d\'achats '
                .'en regard, les quantités descendront très bas — c\'est attendu, mais vérifiez que '
                .'c\'est bien ce que vous voulez.');

            return;
        }

        $this->line('Le stock ne bougera pas : Books n\'en suivait aucun, et rejouer quatre ans de ventes '
            .'sans les achats correspondants enfoncerait chaque article dans le négatif. Le stock de '
            .'départ s\'établit par un inventaire, à la date du jour. (--avec-stock pour passer outre.)');
    }

    /** @param  array<string, mixed>  $rapport */
    private function afficherLeBilan(array $rapport): void
    {
        $this->newLine();
        $this->table(
            ['Reprises', 'Déjà présentes', 'Ignorées', 'Refusées', 'CA HT repris'],
            [[
                $rapport['importees'],
                $rapport['deja_presentes'],
                $rapport['ignorees'],
                $rapport['refusees'],
                number_format($rapport['ca_ht'], 2, ',', ' ').' MAD',
            ]],
        );

        if ($rapport['exercice_clos'] !== null) {
            $this->warn(sprintf(
                'L\'exercice %d est clôturé : toute facture datée de %d ou avant a été refusée par le '
                .'verrou comptable. Rouvrez l\'exercice avant de reprendre ces années-là.',
                $rapport['exercice_clos'],
                $rapport['exercice_clos'],
            ));
        }

        if ($rapport['refusees'] > 0) {
            $this->error(sprintf(
                '%d factures refusées. Elles ne sont PAS dans Dolibarr : corrigez la cause puis '
                .'relancez — l\'import ne reprendra que ce qui manque.',
                $rapport['refusees'],
            ));

            $this->afficherQuelquesRefus($rapport['details']);
        }

        $crees = array_filter(
            $rapport['details'],
            fn ($d) => str_contains($d['raison'] ?? '', 'client créé au passage'),
        );

        if ($crees !== []) {
            $this->warn(sprintf(
                '%d clients ont été créés depuis une facture : ils n\'étaient pas dans la liste des '
                .'contacts de Books. À vérifier — ce sont les doublons potentiels.',
                count($crees),
            ));
        }
    }

    /** @param  list<array<string, string>>  $details */
    private function afficherQuelquesRefus(array $details): void
    {
        $refus = array_values(array_filter($details, fn ($d) => ($d['action'] ?? '') === 'refuse'));

        $this->newLine();
        $this->table(
            ['Facture', 'Date', 'Client', 'Cause'],
            array_map(
                fn ($d) => [$d['numero'] ?? '', $d['date'] ?? '', $d['client'] ?? '', $d['raison'] ?? ''],
                array_slice($refus, 0, 10),
            ),
        );

        if (count($refus) > 10) {
            $this->line(sprintf('  … et %d autres. Le journal CSV les porte toutes.', count($refus) - 10));
        }
    }
}
