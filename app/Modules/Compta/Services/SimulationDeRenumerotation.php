<?php

namespace App\Modules\Compta\Services;

use RuntimeException;

/**
 * Signal interne : la simulation a joué la renumérotation en vrai, il faut tout
 * annuler.
 *
 * Une simulation qui se contenterait de calculer les numéros sans les écrire
 * n'éprouverait NI la double passe NI l'index unique — c'est-à-dire justement
 * les deux endroits où une renumérotation échoue.
 */
class SimulationDeRenumerotation extends RuntimeException {}
