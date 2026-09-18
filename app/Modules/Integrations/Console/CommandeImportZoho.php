<?php

namespace App\Modules\Integrations\Console;

use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Integrations\Zoho\ZohoBooksClient;
use Illuminate\Console\Command;

/**
 * Le tronc commun des trois reprises Zoho (tiers, articles, factures).
 *
 * Toutes posent les mêmes préalables — la passerelle est-elle configurée, vers
 * QUELLE entreprise écrit-on, écrit-on vraiment — et rendent le même genre de
 * rapport. Les écrire trois fois, c'est se garantir que la troisième oubliera
 * le contexte d'entreprise : sans lui, le scope multi-entreprises est
 * fail-closed et l'import s'exécuterait en écrivant dans le vide.
 */
abstract class CommandeImportZoho extends Command
{
    /** Entêtes du journal CSV, dans l'ordre des colonnes de `details`. */
    abstract protected function colonnesJournal(): array;

    /**
     * Prépare le terrain : passerelle configurée, entreprise de destination
     * établie. Rend null quand quelque chose manque — le message est déjà
     * affiché.
     */
    protected function preparer(TenantContext $contexte, ZohoBooksClient $zoho): ?string
    {
        if (! $zoho->estConfigure()) {
            $this->error('Zoho Books n\'est pas configuré. Renseignez dans .env : ZOHO_CLIENT_ID, '
                .'ZOHO_CLIENT_SECRET, ZOHO_REFRESH_TOKEN et ZOHO_BOOKS_ORGANIZATION_ID.');

            return null;
        }

        $user = User::withoutGlobalScopes()->where('email', $this->argument('email'))->first();

        if ($user === null || $user->tenant === null) {
            $this->error("Utilisateur ou entreprise introuvable pour : {$this->argument('email')}");

            return null;
        }

        $contexte->set($user->tenant);

        $this->line(sprintf(
            '%s vers « %s ».',
            $this->simulation() ? 'SIMULATION — aucune écriture' : 'Import',
            $user->tenant->name,
        ));

        return $user->tenant->name;
    }

    protected function simulation(): bool
    {
        return (bool) $this->option('simulation');
    }

    /** Une ligne de progression toutes les cinquante pièces, pas une par pièce. */
    protected function compteur(string $unite): callable
    {
        $vus = 0;

        return function () use (&$vus, $unite) {
            $vus++;

            if ($vus % 50 === 0) {
                $this->line("  … {$vus} {$unite} examinés");
            }
        };
    }

    /** @param  array{crees: int, mis_a_jour: int, inchanges: int}  $rapport */
    protected function afficherTotaux(array $rapport): void
    {
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
    }

    /** @param  list<array<string, string>>  $details */
    protected function terminer(array $details): int
    {
        if ($chemin = $this->option('journal')) {
            $this->ecrireJournal($chemin, $details);
            $this->info("Détail ligne à ligne écrit dans {$chemin}");
        }

        if ($this->simulation()) {
            $this->newLine();
            $this->comment('Rien n\'a été écrit. Relancez sans --simulation pour appliquer.');
        }

        return self::SUCCESS;
    }

    /** @param  list<array<string, string>>  $details */
    private function ecrireJournal(string $chemin, array $details): void
    {
        $colonnes = $this->colonnesJournal();
        $fichier = fopen($chemin, 'w');

        // Le séparateur d'Excel en français est le point-virgule, et un BOM lui
        // évite de lire « Société » comme « SociÃ©tÃ© ». Un journal qu'on doit
        // réparer à la main avant de le lire ne sert à personne.
        fwrite($fichier, "\u{FEFF}");
        fputcsv($fichier, $colonnes, ';');

        foreach ($details as $ligne) {
            fputcsv($fichier, array_map(fn ($c) => $ligne[$c] ?? '', $colonnes), ';');
        }

        fclose($fichier);
    }
}
