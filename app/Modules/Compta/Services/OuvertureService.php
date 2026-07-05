<?php

namespace App\Modules\Compta\Services;

use App\Modules\Compta\Models\Compte;
use App\Modules\Compta\Models\Ecriture;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Reprise des à-nouveaux (balance d'ouverture) depuis un fichier fourni par
 * l'entreprise : Excel ou CSV avec les colonnes Compte, Libellé, Débit, Crédit.
 * L'import crée une écriture au journal AN datée du 1er janvier de l'exercice,
 * reprenant les soldes de bilan de la clôture précédente.
 */
class OuvertureService
{
    private const EPSILON = 0.005;

    public function __construct(private ComptaService $compta) {}

    /** Aperçu : lignes parsées, équilibre et comptes inconnus (avant import). */
    public function previsualiser(UploadedFile $file): array
    {
        $lignes = $this->parse($file);
        $totalDebit = round(array_sum(array_map(fn ($l) => $l['debit'], $lignes)), 2);
        $totalCredit = round(array_sum(array_map(fn ($l) => $l['credit'], $lignes)), 2);

        return [
            'lignes' => array_map(fn ($l) => [
                'code' => $l['code'],
                'libelle' => $l['libelle'],
                'debit' => number_format($l['debit'], 2, '.', ''),
                'credit' => number_format($l['credit'], 2, '.', ''),
                'existe' => Compte::where('code', $l['code'])->exists(),
            ], $lignes),
            'total_debit' => number_format($totalDebit, 2, '.', ''),
            'total_credit' => number_format($totalCredit, 2, '.', ''),
            'equilibre' => abs($totalDebit - $totalCredit) < self::EPSILON,
            'ecart' => number_format(round($totalDebit - $totalCredit, 2), 2, '.', ''),
        ];
    }

    /** Importe le fichier : crée les comptes manquants et poste l'écriture AN. */
    public function importer(UploadedFile $file, ?int $annee = null): Ecriture
    {
        $this->compta->initialiserPlanComptable();
        $annee ??= now()->year;
        $reference = "OUVERTURE-{$annee}";

        if (Ecriture::where('reference', $reference)->exists()) {
            throw ValidationException::withMessages([
                'fichier' => "Une balance d'ouverture existe déjà pour {$annee}. Supprimez-la avant de réimporter.",
            ]);
        }

        $parsed = $this->parse($file);

        if ($parsed === []) {
            throw ValidationException::withMessages([
                'fichier' => 'Aucune ligne exploitable (colonnes attendues : Compte, Libellé, Débit, Crédit).',
            ]);
        }

        return DB::transaction(function () use ($parsed, $annee, $reference) {
            $lignes = [];

            foreach ($parsed as $l) {
                if ($l['debit'] < self::EPSILON && $l['credit'] < self::EPSILON) {
                    continue;
                }

                $compte = Compte::firstOrCreate(
                    ['code' => $l['code']],
                    ['label' => $l['libelle'] ?: $l['code'], 'classe' => (int) $l['code'][0], 'is_system' => false],
                );

                $lignes[] = [
                    'compte' => $compte,
                    'debit' => $l['debit'],
                    'credit' => $l['credit'],
                ];
            }

            // creerEcriture (via ecrire) garantit l'équilibre débit = crédit.
            return $this->compta->ecrire(
                Ecriture::JOURNAL_A_NOUVEAUX,
                "{$annee}-01-01",
                "Balance d'ouverture — report à nouveau {$annee}",
                $lignes,
                $reference,
            );
        });
    }

    /**
     * Parse le fichier en lignes [code, libelle, debit, credit].
     * En-têtes reconnus (insensibles casse/accents) : compte|code, libelle|intitule,
     * debit, credit. Seules les lignes dont le code suit le CGNC (classe 1-7) sont
     * retenues — en-têtes, totaux et lignes vides sont ignorés.
     */
    private function parse(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $reader = $extension === 'csv'
            ? IOFactory::createReader('Csv')
            : IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);

        $sheet = $reader->load($file->getRealPath())->getActiveSheet();
        $rows = $sheet->toArray(null, true, false, false);

        $map = $this->reperColonnes($rows);
        if ($map === null) {
            return [];
        }

        $lignes = [];
        foreach ($rows as $row) {
            $code = trim((string) ($row[$map['compte']] ?? ''));

            // Un code de compte valide : classe 1-7, au moins 3 chiffres.
            if (! preg_match('/^[1-7][0-9]{2,}$/', $code)) {
                continue;
            }

            $debit = $this->montant($row[$map['debit']] ?? null);
            $credit = $this->montant($row[$map['credit']] ?? null);
            $libelle = $map['libelle'] !== null ? trim((string) ($row[$map['libelle']] ?? '')) : '';

            $lignes[] = compact('code', 'libelle', 'debit', 'credit');
        }

        return $lignes;
    }

    /** Repère l'index des colonnes depuis la ligne d'en-tête. */
    private function reperColonnes(array $rows): ?array
    {
        foreach ($rows as $row) {
            $entetes = [];
            foreach ($row as $i => $cell) {
                $entetes[$i] = $this->normaliser((string) $cell);
            }

            $compte = $this->chercher($entetes, ['compte', 'code']);
            $debit = $this->chercher($entetes, ['debit']);
            $credit = $this->chercher($entetes, ['credit']);

            if ($compte !== null && $debit !== null && $credit !== null) {
                return [
                    'compte' => $compte,
                    'debit' => $debit,
                    'credit' => $credit,
                    'libelle' => $this->chercher($entetes, ['libelle', 'intitule', 'designation']),
                ];
            }
        }

        return null;
    }

    private function chercher(array $entetes, array $candidats): ?int
    {
        foreach ($entetes as $i => $valeur) {
            if (in_array($valeur, $candidats, true)) {
                return $i;
            }
        }

        return null;
    }

    private function normaliser(string $texte): string
    {
        $texte = strtolower(trim($texte));
        $accents = ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'à' => 'a', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'û' => 'u', 'ç' => 'c'];

        return strtr($texte, $accents);
    }

    private function montant(mixed $valeur): float
    {
        if ($valeur === null || $valeur === '') {
            return 0.0;
        }

        if (is_numeric($valeur)) {
            return round((float) $valeur, 2);
        }

        // "1 250,00" → 1250.00
        $nettoye = str_replace([' ', "\u{00A0}"], '', (string) $valeur);
        $nettoye = str_replace(',', '.', $nettoye);

        return is_numeric($nettoye) ? round((float) $nettoye, 2) : 0.0;
    }
}
