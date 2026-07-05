<?php

namespace App\Core\Auth;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Désigne (ou révoque) un superadmin plateforme par email.
 *   php artisan superadmin:set contact@exemple.ma
 *   php artisan superadmin:set contact@exemple.ma --revoke
 */
class SetSuperadminCommand extends Command
{
    protected $signature = 'superadmin:set {email} {--revoke}';

    protected $description = 'Accorde ou révoque le statut de superadmin plateforme à un utilisateur';

    public function handle(): int
    {
        // Recherche hors scope tenant : un superadmin peut appartenir à n'importe quel tenant.
        $user = User::withoutGlobalScopes()->where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error("Utilisateur introuvable : {$this->argument('email')}");

            return self::FAILURE;
        }

        $user->update(['is_superadmin' => ! $this->option('revoke')]);

        $this->info($this->option('revoke')
            ? "Superadmin révoqué pour {$user->email}."
            : "Superadmin accordé à {$user->email}.");

        return self::SUCCESS;
    }
}
