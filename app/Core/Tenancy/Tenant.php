<?php

namespace App\Core\Tenancy;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'plan', 'settings'])]
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
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
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
