<?php

namespace App\Modules\Compta\Services;

use App\Modules\Compta\Models\BankStatement;
use App\Modules\Compta\Models\BankStatementLine;
use App\Modules\Compta\Models\Compte;
use App\Modules\Compta\Models\EcritureLigne;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Rapprochement bancaire : on importe un relevé (opérations ligne à ligne) et
 * on pointe chaque ligne contre l'écriture comptable correspondante du compte
 * de trésorerie. Convention `montant` du relevé, côté titulaire : + = entrée
 * (encaissement), − = sortie (décaissement). Côté compta, le « flux » d'une
 * ligne d'écriture sur le compte banque = débit − crédit (débit = entrée).
 */
class RapprochementBancaireService
{
    private const EPSILON = 0.005;

    /** Comptes de trésorerie rapprochables (banques/chèques, classe 51) ayant des mouvements. */
    public function comptesRapprochables(): array
    {
        return Compte::query()
            ->where('code', 'like', '51%')
            ->whereHas('lignesViaEcriture')
            ->orderBy('code')
            ->get()
            ->map(fn (Compte $c) => ['id' => $c->id, 'code' => $c->code, 'label' => $c->label])
            ->values()
            ->all();
    }

    /** Importe un relevé : crée le relevé et ses lignes depuis un fichier Excel/CSV. */
    public function importer(int $compteId, UploadedFile $file, array $meta): BankStatement
    {
        $compte = Compte::findOrFail($compteId);
        $lignes = $this->parse($file);

        if ($lignes === []) {
            throw ValidationException::withMessages([
                'fichier' => 'Aucune opération exploitable (colonnes attendues : Date, Libellé, Débit, Crédit — ou Montant).',
            ]);
        }

        return DB::transaction(function () use ($compte, $lignes, $meta) {
            $statement = BankStatement::create([
                'compte_id' => $compte->id,
                'libelle' => $meta['libelle'] ?? ('Relevé '.$compte->label),
                'date_debut' => $meta['date_debut'] ?? null,
                'date_fin' => $meta['date_fin'] ?? null,
                'solde_initial' => $meta['solde_initial'] ?? 0,
                'solde_final' => $meta['solde_final'] ?? 0,
                'statut' => BankStatement::STATUT_EN_COURS,
            ]);

            foreach ($lignes as $l) {
                $statement->lignes()->create([
                    'date_operation' => $l['date'],
                    'libelle' => $l['libelle'],
                    'reference' => $l['reference'],
                    'montant' => $l['montant'],
                ]);
            }

            return $statement;
        });
    }

    /**
     * Pointage automatique : pour chaque ligne de relevé non pointée, cherche
     * UNE écriture non pointée du compte, de montant égal (au centime), et la
     * relie. Les cas ambigus (plusieurs candidates) sont laissés au manuel.
     */
    public function autoRapprocher(BankStatement $statement): int
    {
        $dispo = $this->ecrituresNonPointees($statement->compte_id);
        $count = 0;

        foreach ($statement->lignes()->whereNull('ecriture_ligne_id')->get() as $ligne) {
            $cibleFlux = round((float) $ligne->montant, 2);

            $candidates = $dispo->filter(fn ($e) => abs($e['flux'] - $cibleFlux) < self::EPSILON);

            if ($candidates->count() === 1) {
                $match = $candidates->first();
                $ligne->update(['ecriture_ligne_id' => $match['id'], 'rapproche_at' => now()]);
                $dispo = $dispo->reject(fn ($e) => $e['id'] === $match['id']); // consommée
                $count++;
            }
        }

        return $count;
    }

    /** Pointage manuel d'une ligne de relevé sur une ligne d'écriture (montants égaux requis). */
    public function rapprocher(BankStatementLine $ligne, int $ecritureLigneId): BankStatementLine
    {
        $ecriture = $this->ligneEcritureDuCompte($ligne->statement->compte_id, $ecritureLigneId);

        if ($this->estDejaPointee($ecritureLigneId, $ligne->id)) {
            throw ValidationException::withMessages(['ecriture_ligne_id' => 'Cette écriture est déjà rapprochée.']);
        }

        $flux = round((float) $ecriture->debit - (float) $ecriture->credit, 2);
        if (abs($flux - round((float) $ligne->montant, 2)) >= self::EPSILON) {
            throw ValidationException::withMessages([
                'ecriture_ligne_id' => 'Le montant de l\'écriture ne correspond pas à celui de la ligne de relevé.',
            ]);
        }

        $ligne->update(['ecriture_ligne_id' => $ecritureLigneId, 'rapproche_at' => now()]);

        return $ligne->refresh();
    }

    /** Annule le pointage d'une ligne de relevé. */
    public function annuler(BankStatementLine $ligne): BankStatementLine
    {
        $ligne->update(['ecriture_ligne_id' => null, 'rapproche_at' => null]);

        return $ligne->refresh();
    }

    public function supprimer(BankStatement $statement): void
    {
        $statement->delete(); // les lignes cascade (FK)
    }

    /**
     * État du rapprochement : lignes de relevé (pointées/non), écritures non
     * pointées du compte, et les soldes.
     *
     * solde_rapproche = solde_comptable + Σ(relevé non pointé) − Σ(compta non pointée)
     * Le rapprochement est équilibré quand solde_rapproche == solde_final du relevé.
     *
     * @return array<string, mixed>
     */
    public function etat(BankStatement $statement): array
    {
        $statement->load(['compte', 'lignes.ecritureLigne.ecriture']);

        $soldeComptable = $this->soldeComptable($statement->compte_id, $statement->date_fin?->toDateString());
        $nonPointees = $this->ecrituresNonPointees($statement->compte_id);

        $releveNonPointe = round($statement->lignes->whereNull('ecriture_ligne_id')->sum(fn ($l) => (float) $l->montant), 2);
        $comptaNonPointe = round($nonPointees->sum('flux'), 2);

        $soldeRapproche = round($soldeComptable + $releveNonPointe - $comptaNonPointe, 2);
        $soldeFinal = round((float) $statement->solde_final, 2);

        return [
            'statement' => [
                'id' => $statement->id,
                'libelle' => $statement->libelle,
                'compte' => ['code' => $statement->compte->code, 'label' => $statement->compte->label],
                'date_debut' => $statement->date_debut?->toDateString(),
                'date_fin' => $statement->date_fin?->toDateString(),
                'solde_initial' => number_format((float) $statement->solde_initial, 2, '.', ''),
                'solde_final' => number_format($soldeFinal, 2, '.', ''),
                'statut' => $statement->statut,
            ],
            'lignes' => $statement->lignes->map(fn (BankStatementLine $l) => [
                'id' => $l->id,
                'date_operation' => $l->date_operation->toDateString(),
                'libelle' => $l->libelle,
                'reference' => $l->reference,
                'montant' => number_format((float) $l->montant, 2, '.', ''),
                'rapprochee' => $l->ecriture_ligne_id !== null,
                'ecriture' => $l->ecritureLigne ? [
                    'id' => $l->ecritureLigne->id,
                    'date' => $l->ecritureLigne->ecriture?->date_ecriture?->toDateString(),
                    'libelle' => $l->ecritureLigne->libelle,
                ] : null,
            ])->values(),
            'ecritures_non_pointees' => $nonPointees->values()->all(),
            'soldes' => [
                'comptable' => number_format($soldeComptable, 2, '.', ''),
                'releve' => number_format($soldeFinal, 2, '.', ''),
                'rapproche' => number_format($soldeRapproche, 2, '.', ''),
                'ecart' => number_format(round($soldeFinal - $soldeRapproche, 2), 2, '.', ''),
                'releve_non_pointe' => number_format($releveNonPointe, 2, '.', ''),
                'compta_non_pointe' => number_format($comptaNonPointe, 2, '.', ''),
            ],
        ];
    }

    /* ------------------------------------------------------------------ */

    /** Solde comptable du compte (Σ débit − crédit) jusqu'à une date incluse. */
    private function soldeComptable(int $compteId, ?string $dateFin): float
    {
        $q = EcritureLigne::where('compte_id', $compteId)->whereHas('ecriture', function ($sub) use ($dateFin) {
            if ($dateFin !== null) {
                $sub->whereDate('date_ecriture', '<=', $dateFin);
            }
        });

        return round((float) $q->sum('debit') - (float) $q->sum('credit'), 2);
    }

    /** Écritures du compte non encore pointées, avec leur flux (débit − crédit). */
    private function ecrituresNonPointees(int $compteId)
    {
        $pointees = BankStatementLine::whereNotNull('ecriture_ligne_id')->pluck('ecriture_ligne_id')->all();

        return EcritureLigne::where('compte_id', $compteId)
            ->whereNotIn('id', $pointees ?: [0])
            ->whereHas('ecriture')
            ->with('ecriture:id,date_ecriture,libelle')
            ->get()
            ->map(fn (EcritureLigne $l) => [
                'id' => $l->id,
                'date' => $l->ecriture?->date_ecriture?->toDateString(),
                'libelle' => $l->libelle ?: $l->ecriture?->libelle,
                'flux' => round((float) $l->debit - (float) $l->credit, 2),
                'montant' => number_format(round((float) $l->debit - (float) $l->credit, 2), 2, '.', ''),
            ])
            ->filter(fn ($e) => abs($e['flux']) >= self::EPSILON);
    }

    private function ligneEcritureDuCompte(int $compteId, int $ecritureLigneId): EcritureLigne
    {
        $ligne = EcritureLigne::where('id', $ecritureLigneId)
            ->where('compte_id', $compteId)
            ->whereHas('ecriture')
            ->first();

        if ($ligne === null) {
            throw ValidationException::withMessages(['ecriture_ligne_id' => 'Écriture introuvable pour ce compte.']);
        }

        return $ligne;
    }

    private function estDejaPointee(int $ecritureLigneId, int $saufLigneId): bool
    {
        return BankStatementLine::where('ecriture_ligne_id', $ecritureLigneId)
            ->where('id', '!=', $saufLigneId)
            ->exists();
    }

    /**
     * Parse le relevé : lignes [date, libelle, reference, montant].
     * Colonnes reconnues (insensibles casse/accents) : date ; libelle|libellé|
     * description|operation ; debit ; credit ; ou montant (signé, + = entrée).
     * reference facultative.
     *
     * @return array<int, array{date: string, libelle: string, reference: ?string, montant: float}>
     */
    private function parse(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $reader = $extension === 'csv' ? IOFactory::createReader('Csv') : IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $rows = $reader->load($file->getRealPath())->getActiveSheet()->toArray(null, true, false, false);

        $map = $this->reperColonnes($rows);
        if ($map === null) {
            return [];
        }

        $lignes = [];
        foreach ($rows as $row) {
            $date = $this->date($row[$map['date']] ?? null);
            if ($date === null) {
                continue; // ligne sans date valide = en-tête / total / vide
            }

            if ($map['montant'] !== null) {
                $montant = $this->montant($row[$map['montant']] ?? null);
            } else {
                $montant = round($this->montant($row[$map['credit']] ?? null) - $this->montant($row[$map['debit']] ?? null), 2);
            }

            if (abs($montant) < self::EPSILON) {
                continue;
            }

            $lignes[] = [
                'date' => $date,
                'libelle' => $map['libelle'] !== null ? trim((string) ($row[$map['libelle']] ?? '')) : '',
                'reference' => $map['reference'] !== null ? (trim((string) ($row[$map['reference']] ?? '')) ?: null) : null,
                'montant' => $montant,
            ];
        }

        return $lignes;
    }

    private function reperColonnes(array $rows): ?array
    {
        foreach ($rows as $row) {
            $entetes = [];
            foreach ($row as $i => $cell) {
                $entetes[$i] = $this->normaliser((string) $cell);
            }

            $date = $this->chercher($entetes, ['date', 'dateoperation', 'dateop', 'datevaleur']);
            $debit = $this->chercher($entetes, ['debit']);
            $credit = $this->chercher($entetes, ['credit']);
            $montant = $this->chercher($entetes, ['montant']);

            if ($date !== null && (($debit !== null && $credit !== null) || $montant !== null)) {
                return [
                    'date' => $date,
                    'debit' => $debit,
                    'credit' => $credit,
                    'montant' => $montant,
                    'libelle' => $this->chercher($entetes, ['libelle', 'libelle', 'description', 'operation', 'intitule', 'nature']),
                    'reference' => $this->chercher($entetes, ['reference', 'ref', 'piece']),
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

    /** Convertit une cellule date (serial Excel ou chaîne) en 'Y-m-d', ou null. */
    private function date(mixed $valeur): ?string
    {
        if ($valeur === null || $valeur === '') {
            return null;
        }

        if (is_numeric($valeur) && (float) $valeur > 1) {
            try {
                return ExcelDate::excelToDateTimeObject((float) $valeur)->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }

        $texte = trim((string) $valeur);
        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'd/m/y', 'd.m.Y'] as $format) {
            $d = \DateTime::createFromFormat($format, $texte);
            if ($d !== false) {
                return $d->format('Y-m-d');
            }
        }

        try {
            return Carbon::parse($texte)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function montant(mixed $valeur): float
    {
        if ($valeur === null || $valeur === '') {
            return 0.0;
        }

        if (is_numeric($valeur)) {
            return round((float) $valeur, 2);
        }

        $nettoye = str_replace([' ', "\u{00A0}"], '', (string) $valeur);
        $nettoye = str_replace(',', '.', $nettoye);

        return is_numeric($nettoye) ? round((float) $nettoye, 2) : 0.0;
    }
}
