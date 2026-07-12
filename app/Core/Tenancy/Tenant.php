<?php

namespace App\Core\Tenancy;

use App\Models\Invitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'plan', 'extra_seats', 'suspended_at', 'settings'])]
class Tenant extends Model
{
    /**
     * Modules activables par l'entreprise (feature flags dans settings.features).
     * Défauts d'un nouveau tenant : relances actives (universel), effets/traites
     * inactives (métier B2B spécifique). Toute feature future s'ajoute ici.
     */
    public const FEATURES_DEFAUT = [
        'relances' => true,
        'effets' => false,
        'crm' => false,
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'suspended_at' => 'datetime',
        ];
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    /** Abonnement mensuel indicatif = prix du plan + sièges extra (MAD HT). */
    public function estimatedMonthly(): int
    {
        $plans = config('plans.plans');
        $prix = (int) ($plans[$this->plan]['price'] ?? 0);

        return $prix + (int) $this->extra_seats * (int) config('plans.extra_seat_price');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    /** Sièges inclus par le plan (config/plans.php, repli sur 'free'). */
    public function includedSeats(): int
    {
        $plans = config('plans.plans');

        return (int) ($plans[$this->plan]['included_seats'] ?? $plans['free']['included_seats'] ?? 1);
    }

    /** Limite d'utilisateurs actifs = inclus + sièges additionnels achetés. */
    public function seatLimit(): int
    {
        return $this->includedSeats() + (int) $this->extra_seats;
    }

    /** Utilisateurs actifs (les désactivés ne consomment pas de siège). */
    public function seatsUsed(): int
    {
        return $this->users()->where('is_active', true)->count();
    }

    /** Invitations en attente : chacune réserve un siège d'avance. */
    public function pendingInvitationsCount(): int
    {
        return $this->invitations()
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->count();
    }

    /** Sièges consommés = actifs + invitations en attente. */
    public function seatsConsumed(): int
    {
        return $this->seatsUsed() + $this->pendingInvitationsCount();
    }

    /** Reste-t-il un siège pour ajouter un collaborateur ? */
    public function canAddSeat(): bool
    {
        return $this->seatsConsumed() < $this->seatLimit();
    }

    /** État des modules (défauts fusionnés avec settings.features). */
    public function features(): array
    {
        $stored = $this->settings['features'] ?? [];

        return collect(self::FEATURES_DEFAUT)
            ->map(fn ($defaut, $cle) => (bool) ($stored[$cle] ?? $defaut))
            ->all();
    }

    public function hasFeature(string $cle): bool
    {
        return $this->features()[$cle] ?? false;
    }
}
