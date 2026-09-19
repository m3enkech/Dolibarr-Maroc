<?php

namespace App\Modules\Compta\Console;

use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Compta\Services\RenumerotationService;
use Illuminate\Console\Command;

/**
 * Remet les journaux en numérotation chronologique et continue.
 *
 *   php artisan compta:renumeroter client@exemple.ma --simulation
 *   php artisan compta:renumeroter client@exemple.ma
 *   php artisan compta:renumeroter client@exemple.ma --journal=VT
 *
 * À jouer après une reprise d'historique : les pièces antérieures y reçoivent
 * la série de l'année de SAISIE au lieu de celle de leur exercice.
 *
 * ⚠️ Les numéros d'écriture CHANGENT. S'ils ont déjà été imprimés, exportés ou
 * communiqués à un comptable, ils ne correspondront plus. La commande le dit
 * avant d'agir, et demande confirmation.
 */
class RenumeroterEcrituresCommand extends Command
{
    protected $signature = 'compta:renumeroter
        {email : un utilisateur de l\'entreprise concernée}
        {--journal=* : se limiter à ces journaux (VT, BQ, AC, OD, AN)}
        {--simulation : joue tout puis annule ; affiche le résultat exact sans rien garder}
        {--force : ne demande pas confirmation}';

    protected $description = 'Renumérote les écritures par journal et par exercice, dans l\'ordre des dates';

    public function handle(TenantContext $contexte, RenumerotationService $service): int
    {
        $user = User::withoutGlobalScopes()->where('email', $this->argument('email'))->first();

        if ($user === null || $user->tenant === null) {
            $this->error("Utilisateur ou entreprise introuvable pour : {$this->argument('email')}");

            return self::FAILURE;
        }

        $contexte->set($user->tenant);

        $simulation = (bool) $this->option('simulation');
        $journaux = array_filter((array) $this->option('journal'));

        $this->line(sprintf(
            '%s sur « %s »%s.',
            $simulation ? 'SIMULATION — rien ne sera conservé' : 'Renumérotation',
            $user->tenant->name,
            $journaux !== [] ? ' — journaux '.implode(', ', $journaux) : '',
        ));

        if (! $simulation && ! $this->option('force')) {
            $this->warn('Les numéros d\'écriture vont CHANGER. S\'ils ont déjà été imprimés, exportés ou '
                .'communiqués à votre comptable, ils ne correspondront plus.');

            if (! $this->confirm('Continuer ?', false)) {
                $this->line('Abandonné.');

                return self::SUCCESS;
            }
        }

        $rapport = $service->renumeroter($journaux, $simulation);

        if ($rapport['series'] === []) {
            $this->info('Rien à corriger : chaque série est déjà chronologique et continue.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->table(
            ['Journal', 'Exercice', 'Pièces', 'Renumérotées', 'Du', 'Au'],
            array_map(fn ($s) => [
                $s['journal'], $s['annee'], $s['pieces'], $s['changements'], $s['premier'], $s['dernier'],
            ], $rapport['series']),
        );

        $this->info(sprintf('%d écritures renumérotées.', $rapport['total']));

        if ($simulation) {
            $this->newLine();
            $this->comment('Rien n\'a été écrit. Relancez sans --simulation pour appliquer.');
        }

        return self::SUCCESS;
    }
}
