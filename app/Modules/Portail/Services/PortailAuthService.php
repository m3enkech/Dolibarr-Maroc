<?php

namespace App\Modules\Portail\Services;

use App\Core\Tenancy\Tenant;
use App\Core\Tenancy\TenantContext;
use App\Modules\Portail\Models\Acheteur;
use App\Modules\Portail\Models\AcheteurTiers;
use App\Modules\Tiers\Models\Tiers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Comptes acheteurs : inscription, connexion, et demandes de rattachement aux
 * grossistes.
 */
class PortailAuthService
{
    /** Durée de vie d'un jeton de portail : un acheteur n'est pas un poste de travail. */
    private const JOURS_VALIDITE_JETON = 30;

    public function __construct(private TenantContext $context) {}

    public function inscrire(array $data): Acheteur
    {
        return Acheteur::create([
            'email' => strtolower(trim($data['email'])),
            'name' => $data['name'],
            'password' => $data['password'],
            'phone' => $data['phone'] ?? null,
        ]);
    }

    /** @return array{acheteur: Acheteur, token: string} */
    public function connecter(string $email, string $password): array
    {
        $acheteur = Acheteur::where('email', strtolower(trim($email)))->first();

        if ($acheteur === null || ! Hash::check($password, $acheteur->password)) {
            throw ValidationException::withMessages(['email' => __('Identifiants incorrects.')]);
        }

        if (! $acheteur->is_active) {
            throw ValidationException::withMessages(['email' => __('Ce compte est désactivé.')]);
        }

        $acheteur->update(['derniere_connexion_at' => now()]);

        // Le jeton ne porte QUE l'habilitation portail : même mal câblée, une
        // route de l'ERP ne l'accepterait pas.
        $token = $acheteur->createToken('portail', ['portail:*'], now()->addDays(self::JOURS_VALIDITE_JETON));

        return ['acheteur' => $acheteur, 'token' => $token->plainTextToken];
    }

    /**
     * Demande d'accès à un grossiste. Reste en attente jusqu'à l'approbation
     * par le grossiste, qui la relie à un compte client (Tiers).
     */
    public function demanderAcces(Acheteur $acheteur, string $slugGrossiste): AcheteurTiers
    {
        $tenant = Tenant::where('slug', $slugGrossiste)->first();

        if ($tenant === null) {
            throw ValidationException::withMessages(['grossiste' => __('Grossiste introuvable.')]);
        }

        $existant = AcheteurTiers::where('acheteur_id', $acheteur->id)
            ->where('tenant_id', $tenant->id)
            ->first();

        if ($existant !== null) {
            if ($existant->statut === AcheteurTiers::STATUT_REFUSE) {
                throw ValidationException::withMessages(['grossiste' => __('Votre demande a été refusée par ce grossiste.')]);
            }

            return $existant; // demande déjà en cours ou déjà approuvée
        }

        return AcheteurTiers::create([
            'acheteur_id' => $acheteur->id,
            'tenant_id' => $tenant->id,
            'statut' => AcheteurTiers::STATUT_EN_ATTENTE,
            'demande_at' => now(),
        ]);
    }

    /**
     * Approbation par le grossiste : relie l'acheteur à un compte client
     * existant, ou en crée un à son nom.
     */
    public function approuver(AcheteurTiers $rattachement, ?int $tiersId, int $approuveParUserId): AcheteurTiers
    {
        $tenant = Tenant::find($rattachement->tenant_id);

        if ($tenant === null) {
            throw ValidationException::withMessages(['grossiste' => __('Grossiste introuvable.')]);
        }

        return DB::transaction(fn () => $this->context->runAs($tenant, function () use ($rattachement, $tiersId, $approuveParUserId) {
            $tiers = $tiersId !== null
                ? Tiers::find($tiersId)
                : app(\App\Modules\Tiers\Services\TiersService::class)->create([
                    'name' => $rattachement->acheteur->name,
                    'is_client' => true,
                    'email' => $rattachement->acheteur->email,
                    'phone' => $rattachement->acheteur->phone,
                    'country' => 'MA',
                ]);

            if ($tiers === null) {
                throw ValidationException::withMessages(['tiers_id' => __('Ce client n\'existe pas.')]);
            }

            $rattachement->update([
                'tiers_id' => $tiers->id,
                'statut' => AcheteurTiers::STATUT_APPROUVE,
                'approuve_at' => now(),
                'approuve_par' => $approuveParUserId,
            ]);

            return $rattachement->refresh();
        }));
    }

    public function refuser(AcheteurTiers $rattachement): AcheteurTiers
    {
        $rattachement->update(['statut' => AcheteurTiers::STATUT_REFUSE]);

        return $rattachement->refresh();
    }

    public function revoquer(AcheteurTiers $rattachement): AcheteurTiers
    {
        $rattachement->update(['statut' => AcheteurTiers::STATUT_REVOQUE, 'revoque_at' => now()]);

        return $rattachement->refresh();
    }
}
