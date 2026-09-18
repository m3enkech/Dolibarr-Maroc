<?php

namespace App\Modules\Integrations\Zoho;

use RuntimeException;

/**
 * Signal interne : la simulation a joué le chemin réel, il faut tout annuler.
 *
 * Une simulation qui emprunte un code différent du vrai import finit toujours
 * par mentir sur un détail — un arrondi, un refus, une contrainte. Celle-ci
 * exécute exactement la même chose, dans une transaction qu'on fait échouer
 * exprès. D'où cette exception, qui ne signale aucune erreur.
 */
class SimulationTerminee extends RuntimeException {}
