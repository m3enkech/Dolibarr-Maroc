<?php

namespace App\Core\Tenancy;

use App\Models\Invitation;
use App\Models\SubscriptionPayment;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name', 'slug', 'plan', 'extra_seats', 'suspended_at', 'settings',
    'billing_cycle', 'subscription_status', 'trial_ends_at', 'current_period_end', 'subscription_started_at',
])]
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
            'trial_ends_at' => 'datetime',
            'current_period_end' => 'date',
            'subscription_started_at' => 'datetime',
        ];
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class);
    }

    /** Cycle annuel ? (sinon mensuel). */
    public function isAnnual(): bool
    {
        return $this->billing_cycle === 'annuel';
    }

    /** Abonnement mensuel indicatif = prix du plan + sièges extra (MAD HT). */
    public function estimatedMonthly(): int
    {
        $plans = config('plans.plans');
        $prix = (int) ($plans[$this->plan]['price'] ?? 0);

        return $prix + (int) $this->extra_seats * (int) config('plans.extra_seat_price');
    }

    /** Montant facturé pour la période courante selon le cycle (mensuel ou annuel). */
    public function subscriptionAmount(): int
    {
        $plans = config('plans.plans');
        $extraPar = (int) config('plans.extra_seat_price');
        $extra = (int) $this->extra_seats;

        if ($this->isAnnual()) {
            $prix = (int) ($plans[$this->plan]['price_annual'] ?? 0);

            return $prix + $extra * $extraPar * 12;
        }

        return (int) ($plans[$this->plan]['price'] ?? 0) + $extra * $extraPar;
    }

    /** Revenu mensuel récurrent (annuel ramené au mois). */
    public function mrr(): float
    {
        return $this->isAnnual()
            ? round($this->subscriptionAmount() / 12, 2)
            : (float) $this->subscriptionAmount();
    }

    /** L'échéance est-elle dépassée sans paiement ? (essai expiré ou période échue). */
    public function isPastDue(): bool
    {
        if ($this->subscription_status === 'annule' || $this->isSuspended()) {
            return false;
        }
        if ($this->subscription_status === 'essai') {
            return $this->trial_ends_at !== null && $this->trial_ends_at->isPast();
        }

        return $this->current_period_end !== null && $this->current_period_end->isPast();
    }

    /**
     * Statut d'abonnement effectif pour l'affichage :
     * suspendu > annulé > en_retard > essai > actif.
     */
    public function effectiveStatus(): string
    {
        if ($this->isSuspended()) {
            return 'suspendu';
        }
        if ($this->subscription_status === 'annule') {
            return 'annule';
        }
        if ($this->isPastDue()) {
            return 'en_retard';
        }
        if ($this->subscription_status === 'essai') {
            return 'essai';
        }

        return 'actif';
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
