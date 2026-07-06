<?php

namespace App\Modules\Equipe\Services;

use App\Core\Auth\Roles;
use App\Core\Tenancy\Tenant;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Gestion de l'équipe d'une entreprise : invitations, rôles, activation.
 * Concentre les garde-fous (dernier admin, limite de sièges, unicité email).
 */
class EquipeService
{
    public const INVITATION_TTL_JOURS = 7;

    /**
     * Crée une invitation à rejoindre le tenant. Vérifie qu'il reste un siège,
     * que l'email n'est pas déjà utilisé et qu'aucune invitation n'est en cours.
     */
    public function inviter(Tenant $tenant, User $auteur, string $email, string $role): Invitation
    {
        $email = Str::lower(trim($email));

        if (! Roles::existe($role)) {
            throw ValidationException::withMessages(['role' => 'Rôle inconnu.']);
        }

        if (! $tenant->canAddSeat()) {
            throw ValidationException::withMessages([
                'email' => "Limite de {$tenant->seatLimit()} utilisateur(s) atteinte pour votre plan. "
                    .'Ajoutez des sièges ou passez à un plan supérieur.',
            ]);
        }

        if (User::where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'Cet email est déjà associé à un compte.']);
        }

        $dejaInvite = $tenant->invitations()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->exists();

        if ($dejaInvite) {
            throw ValidationException::withMessages(['email' => 'Une invitation est déjà en attente pour cet email.']);
        }

        return $tenant->invitations()->create([
            'email' => $email,
            'role' => $role,
            'token' => Str::random(48),
            'invited_by' => $auteur->id,
            'expires_at' => now()->addDays(self::INVITATION_TTL_JOURS),
        ]);
    }

    public function revoquerInvitation(Invitation $invitation): void
    {
        if ($invitation->accepted_at !== null) {
            throw ValidationException::withMessages(['invitation' => 'Cette invitation a déjà été acceptée.']);
        }

        $invitation->delete();
    }

    /**
     * Accepte une invitation : crée l'utilisateur dans le tenant. Revérifie le
     * siège au moment de l'acceptation (une invitation peut avoir été émise
     * puis le quota atteint autrement).
     */
    public function accepter(Invitation $invitation, string $name, string $password): array
    {
        if (! $invitation->isPending()) {
            throw ValidationException::withMessages(['token' => 'Cette invitation est invalide ou expirée.']);
        }

        if (User::where('email', $invitation->email)->exists()) {
            throw ValidationException::withMessages(['token' => 'Un compte existe déjà pour cet email.']);
        }

        return DB::transaction(function () use ($invitation, $name, $password) {
            /** @var Tenant $tenant */
            $tenant = $invitation->tenant()->lockForUpdate()->first();

            if ($tenant->seatsUsed() >= $tenant->seatLimit()) {
                throw ValidationException::withMessages([
                    'token' => 'La limite d\'utilisateurs de cette entreprise est atteinte. Contactez son administrateur.',
                ]);
            }

            $user = User::create([
                'tenant_id' => $tenant->id,
                'name' => $name,
                'email' => $invitation->email,
                'password' => $password,
                'role' => $invitation->role,
                'is_active' => true,
            ]);

            $invitation->update(['accepted_at' => now()]);

            return [$user, $tenant];
        });
    }

    /**
     * Modifie le rôle et/ou l'état actif d'un collaborateur. Empêche de
     * dégrader/désactiver le dernier administrateur actif.
     */
    public function modifierUtilisateur(User $cible, array $data): User
    {
        $nouveauRole = $data['role'] ?? $cible->role;
        $actif = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : (bool) $cible->is_active;

        $perdAdmin = $cible->isAdmin() && ($nouveauRole !== Roles::ADMIN || ! $actif);

        if ($perdAdmin && $this->estDernierAdminActif($cible)) {
            throw ValidationException::withMessages([
                'role' => 'Impossible : il doit rester au moins un administrateur actif.',
            ]);
        }

        $cible->update([
            'role' => $nouveauRole,
            'is_active' => $actif,
        ]);

        return $cible->refresh();
    }

    /** Supprime un collaborateur (impossible sur soi-même ou le dernier admin). */
    public function supprimerUtilisateur(User $cible, User $auteur): void
    {
        if ($cible->id === $auteur->id) {
            throw ValidationException::withMessages(['user' => 'Vous ne pouvez pas supprimer votre propre compte.']);
        }

        if ($cible->isAdmin() && $this->estDernierAdminActif($cible)) {
            throw ValidationException::withMessages([
                'user' => 'Impossible : il doit rester au moins un administrateur actif.',
            ]);
        }

        $cible->tokens()->delete();
        $cible->delete();
    }

    private function estDernierAdminActif(User $cible): bool
    {
        $autresAdmins = User::where('tenant_id', $cible->tenant_id)
            ->where('id', '!=', $cible->id)
            ->where('role', Roles::ADMIN)
            ->where('is_active', true)
            ->count();

        return $autresAdmins === 0;
    }
}
