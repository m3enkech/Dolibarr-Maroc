<?php

namespace App\Modules\Tiers\Models;

use App\Core\Tenancy\BelongsToTenant;
use Database\Factories\TiersFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'code', 'name', 'forme', 'is_client', 'is_supplier', 'is_prospect', 'lead_source', 'converti_at',
    'categorie_tarifaire_id', 'plafond_credit', 'delai_paiement_jours',
    'ice', 'if_number', 'rc', 'patente', 'cnss',
    'address', 'city', 'postal_code', 'country',
    'livraison_identique', 'adresse_livraison', 'ville_livraison', 'code_postal_livraison',
    'phone', 'email', 'website', 'contact_name',
    'notes', 'is_active',
    'source_systeme', 'source_id',
])]
class Tiers extends Model
{
    /** @use HasFactory<TiersFactory> */
    use BelongsToTenant, HasFactory, SoftDeletes;

    /** Origines de lead d'un prospect (colonne libre validée par Rule::in). */
    public const LEAD_SOURCES = [
        'site_web', 'salon', 'recommandation', 'appel_entrant',
        'prospection', 'reseaux_sociaux', 'autre',
    ];

    /**
     * Forme juridique, au sens où elle change ce qu'on attend du tiers : une
     * ENTREPRISE a des identifiants légaux (ICE, IF, RC) — sans qu'aucun ne
     * soit exigé pour autant, l'existant en manque —, un PARTICULIER n'en a
     * pas. Jamais déduite de l'ICE : voir la migration qui l'introduit.
     */
    public const FORME_ENTREPRISE = 'entreprise';

    public const FORME_PARTICULIER = 'particulier';

    public const FORMES = [self::FORME_ENTREPRISE, self::FORME_PARTICULIER];

    protected $table = 'tiers';

    /**
     * Les défauts de la base, connus aussi du modèle : sans eux, un tiers tout
     * juste créé sortait `forme: null` dans la réponse de création — la
     * colonne a bien son défaut, mais l'instance ne relit pas la ligne.
     */
    protected $attributes = [
        'forme' => self::FORME_ENTREPRISE,
        'livraison_identique' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_client' => 'boolean',
            'livraison_identique' => 'boolean',
            'is_supplier' => 'boolean',
            'is_prospect' => 'boolean',
            'is_active' => 'boolean',
            'converti_at' => 'datetime',
            // Sans cast, la colonne décimale sortait en NOMBRE JSON sous
            // SQLite (5000) et en CHAÎNE sous PostgreSQL ("5000.00") : le même
            // écran recevait deux types selon la base. Chaîne à deux décimales
            // partout, comme tous les montants de l'API.
            'plafond_credit' => 'decimal:2',
        ];
    }

    /** Les interlocuteurs chez ce client ou ce fournisseur. */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    /** Celui que visent les documents et les relances, s'il est désigné. */
    public function contactPrincipal(): HasOne
    {
        return $this->hasOne(Contact::class)->where('is_principal', true);
    }

    /** Le fil de commentaires de l'équipe sur ce tiers. */
    public function commentaires(): HasMany
    {
        return $this->hasMany(Commentaire::class);
    }

    protected static function newFactory(): TiersFactory
    {
        return TiersFactory::new();
    }
}
