<?php

/*
 * Amorce de la suite PostgreSQL (phpunit.pgsql.xml).
 *
 * La base de test est PARTAGÉE : RefreshDatabase la vide et la remigre au
 * premier test de chaque processus. Deux suites lancées en même temps (deux
 * relecteurs, deux terminaux) se détruisaient leurs tables l'une sous l'autre,
 * et les échecs qui en sortaient (« relation "users" does not exist ») ne
 * prouvaient rien sur le code. Un verrou consultatif de SESSION, pris ici et
 * tenu jusqu'à la fin du processus — il tombe avec la connexion, même sur un
 * plantage —, met la seconde suite en file d'attente.
 *
 * Clé tirée du nom de la base : une suite pointée ailleurs (DB_DATABASE posé
 * dans l'environnement, que phpunit.pgsql.xml ne force pas) n'attend pas.
 */

require __DIR__.'/../vendor/autoload.php';

$base = getenv('DB_DATABASE') ?: 'dolibarr_pgtest';

$verrou = new PDO(
    sprintf('pgsql:host=%s;port=%s;dbname=%s', getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_PORT') ?: '5432', $base),
    getenv('DB_USERNAME') ?: null,
    getenv('DB_PASSWORD') ?: null,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

$cle = crc32('phpunit:'.$base);

// ::int — un booléen PostgreSQL peut revenir en chaîne 'f', qui est vraie en PHP.
if ((int) $verrou->query("SELECT pg_try_advisory_lock({$cle})::int")->fetchColumn() !== 1) {
    fwrite(STDERR, "Une autre suite tourne sur la base « {$base} » : attente de sa fin…\n");
    $verrou->query("SELECT pg_advisory_lock({$cle})");
}

// Gardée vivante jusqu'à la fin du processus : c'est elle qui tient le verrou.
$GLOBALS['verrouSuitePostgres'] = $verrou;
