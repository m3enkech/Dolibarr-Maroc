<?php

namespace App\Core\Auth;

/**
 * Catalogue central des rôles métier et de leurs droits par domaine.
 *
 * Un « domaine » = un module fonctionnel (ventes, compta…). Le niveau d'accès
 * vaut 'none' | 'read' | 'write' (write implique read). Le middleware
 * `permission:{domaine}` déduit l'action de la méthode HTTP (GET = read, sinon
 * write) et applique ces droits sur l'API. Le frontend reçoit la même matrice
 * (User::permissionsMap) pour filtrer le menu et masquer les actions.
 *
 * Source de vérité UNIQUE : ajouter un module = ajouter une colonne ici.
 */
class Roles
{
    public const ADMIN = 'admin';
    public const MANAGER = 'manager';
    public const COMPTABLE = 'comptable';
    public const COMMERCIAL = 'commercial';
    public const CAISSIER = 'caissier';
    public const LECTURE = 'lecture';

    public const NONE = 'none';
    public const READ = 'read';
    public const WRITE = 'write';

    /** Domaines protégés (un par module fonctionnel). */
    public const DOMAINES = [
        'tiers', 'catalogue', 'ventes', 'achats', 'stock', 'compta',
        'pos', 'crm', 'relances', 'effets', 'equipe', 'parametres',
    ];

    /**
     * Libellés présentés dans l'UI (gestion de l'équipe).
     */
    public const LIBELLES = [
        self::ADMIN => 'Administrateur',
        self::MANAGER => 'Manager',
        self::COMPTABLE => 'Comptable',
        self::COMMERCIAL => 'Commercial',
        self::CAISSIER => 'Caissier',
        self::LECTURE => 'Lecture seule',
    ];

    /**
     * Matrice rôle → domaine → niveau. Les domaines absents valent 'none'.
     * L'admin est traité à part (accès total, y compris futurs domaines).
     */
    private const MATRICE = [
        self::MANAGER => [
            'tiers' => self::WRITE, 'catalogue' => self::WRITE, 'ventes' => self::WRITE,
            'achats' => self::WRITE, 'stock' => self::WRITE, 'compta' => self::WRITE,
            'pos' => self::WRITE, 'crm' => self::WRITE, 'relances' => self::WRITE,
            'effets' => self::WRITE, 'equipe' => self::NONE, 'parametres' => self::READ,
        ],
        self::COMPTABLE => [
            'tiers' => self::READ, 'catalogue' => self::READ, 'ventes' => self::READ,
            'achats' => self::READ, 'stock' => self::READ, 'compta' => self::WRITE,
            'pos' => self::NONE, 'crm' => self::NONE, 'relances' => self::WRITE,
            'effets' => self::WRITE, 'equipe' => self::NONE, 'parametres' => self::READ,
        ],
        self::COMMERCIAL => [
            'tiers' => self::WRITE, 'catalogue' => self::READ, 'ventes' => self::WRITE,
            'achats' => self::NONE, 'stock' => self::READ, 'compta' => self::NONE,
            'pos' => self::WRITE, 'crm' => self::WRITE, 'relances' => self::WRITE,
            'effets' => self::NONE, 'equipe' => self::NONE, 'parametres' => self::READ,
        ],
        self::CAISSIER => [
            'tiers' => self::READ, 'catalogue' => self::READ, 'ventes' => self::NONE,
            'achats' => self::NONE, 'stock' => self::READ, 'compta' => self::NONE,
            'pos' => self::WRITE, 'crm' => self::NONE, 'relances' => self::NONE,
            'effets' => self::NONE, 'equipe' => self::NONE, 'parametres' => self::READ,
        ],
        self::LECTURE => [
            'tiers' => self::READ, 'catalogue' => self::READ, 'ventes' => self::READ,
            'achats' => self::READ, 'stock' => self::READ, 'compta' => self::READ,
            'pos' => self::NONE, 'crm' => self::READ, 'relances' => self::READ,
            'effets' => self::READ, 'equipe' => self::NONE, 'parametres' => self::READ,
        ],
    ];

    /** Rôles assignables à un collaborateur (tous sauf... tous, ici). */
    public static function assignables(): array
    {
        return array_keys(self::LIBELLES);
    }

    public static function existe(string $role): bool
    {
        return array_key_exists($role, self::LIBELLES);
    }

    /** Niveau d'accès d'un rôle sur un domaine ('none'|'read'|'write'). */
    public static function niveau(string $role, string $domaine): string
    {
        if ($role === self::ADMIN) {
            return self::WRITE; // l'admin peut tout, y compris les domaines futurs
        }

        return self::MATRICE[$role][$domaine] ?? self::NONE;
    }

    /** Matrice complète d'un rôle (pour le frontend). */
    public static function map(string $role): array
    {
        $map = [];
        foreach (self::DOMAINES as $domaine) {
            $map[$domaine] = self::niveau($role, $domaine);
        }

        return $map;
    }
}
