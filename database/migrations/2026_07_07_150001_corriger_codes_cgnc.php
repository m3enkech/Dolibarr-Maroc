<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Mise en conformité du plan comptable avec le CGNC officiel marocain.
 *
 * Le plan de référence initial comportait des codes systématiquement décalés
 * (ex. Clients en 3411 au lieu de 3421, amortissements en 29xx au lieu de 28xx,
 * TVA facturée en 4441 au lieu de 4455…). Cette migration renomme les
 * `comptes.code` existants (par tenant) selon la correspondance officielle.
 *
 * Les écritures (ecriture_lignes) et les mappings (compta_mappings) référencent
 * le `compte_id`, PAS le code : renommer le code préserve donc TOUT l'historique.
 *
 * Renommage en DEUX PHASES (code → temporaire → code final) car certains codes
 * sont « échangés » (3411→3421 alors que 3421→3431 ; 4441→4455 / 4446→4441 ;
 * 6141→6161 / 6161→6193 ; etc.), ce qui provoquerait des collisions d'unicité.
 */
return new class extends Migration
{
    /** Correspondance code_actuel => code_CGNC_officiel. */
    public const MAP = [
        // Classe 1 — Financement permanent
        '1141' => '1140',   // Réserve légale
        '1151' => '1161',   // Report à nouveau (solde créditeur)
        '1152' => '1169',   // Report à nouveau (solde débiteur)
        '1161' => '1191',   // Résultat net de l'exercice (bénéfice)
        '1162' => '1199',   // Résultat net de l'exercice (perte)
        '1415' => '1481',   // Emprunts auprès des établissements de crédit
        // Classe 2 — Actif immobilisé
        '2330' => '2332',   // Installations techniques, matériel et outillage
        '2450' => '2486',   // Dépôts et cautionnements versés
        '2832' => '2822',   // Amortissement des brevets, marques et logiciels
        '2921' => '2832',   // Amortissement des constructions
        '2922' => '2833',   // Amortissement des installations techniques
        '2924' => '2834',   // Amortissement du matériel de transport
        '2925' => '2835',   // Amortissement du mobilier et matériel de bureau
        '2926' => '28355',  // Amortissement du matériel informatique
        // Classe 3 — Actif circulant
        '3111' => '3122',   // Matières et fournitures consommables
        '3151' => '3111',   // Marchandises
        '3411' => '3421',   // Clients
        '3412' => '3425',   // Clients — effets à recevoir
        '3413' => '3424',   // Clients douteux ou litigieux
        '3421' => '3431',   // Personnel — débiteurs
        '3441' => '34551',  // État — TVA récupérable sur immobilisations
        '3442' => '34552',  // État — TVA récupérable sur charges
        '3443' => '3453',   // État — Acomptes sur IS
        '3461' => '3488',   // Débiteurs divers
        '3491' => '3942',   // Provisions pour dépréciation des comptes clients
        // Classe 4 — Passif circulant
        '4412' => '4415',   // Fournisseurs — effets à payer
        '4421' => '4432',   // Personnel — rémunérations dues
        '4432' => '4463',   // Associés — comptes courants créditeurs
        '4441' => '4455',   // État — TVA facturée
        '4442' => '4456',   // État — TVA due
        '4443' => '4453',   // État — Acomptes sur IS (créditeur)
        '4445' => '44525',  // État — Impôts et taxes sur salaires (IR)
        '4446' => '4441',   // Organismes sociaux (CNSS)
        '4461' => '4488',   // Créditeurs divers
        // Classe 6 — Charges
        '6117' => '6126',   // Achats de travaux, études et prestations de services
        '6121' => '6131',   // Locations et charges locatives
        '6126' => '6134',   // Primes d'assurances
        '6128' => '6142',   // Transports
        '6129' => '6143',   // Déplacements, missions et réceptions
        '6131' => '6145',   // Frais postaux et de télécommunications
        '6132' => '6136',   // Rémunérations d'intermédiaires et honoraires
        '6133' => '6144',   // Publicité, publications et relations publiques
        '6135' => '6147',   // Services bancaires
        '6141' => '6161',   // Impôts et taxes directs (Taxe Professionnelle)
        '6151' => '6171',   // Rémunérations du personnel
        '6152' => '6174',   // Charges sociales
        '6161' => '6193',   // Dotations d'exploitation aux amortissements
        '6511' => '6513',   // VNA des immobilisations cédées
        '6514' => '6583',   // Pénalités et amendes fiscales et pénales
        // Classe 7 — Produits
        '7114' => '7124',   // Ventes de services
        '7118' => '7119',   // Rabais, remises et ristournes accordés
        '7161' => '7181',   // Produits d'exploitation divers
        '7511' => '7513',   // Produits de cession des immobilisations
    ];

    public function up(): void
    {
        $this->renommer(self::MAP);
    }

    public function down(): void
    {
        $this->renommer(array_flip(self::MAP));
    }

    /** Renomme les codes en deux phases (temporaire puis final) pour éviter les collisions. */
    private function renommer(array $map): void
    {
        $comptes = DB::table('comptes')->whereIn('code', array_keys($map))->get(['id', 'code']);

        // Phase 1 : code → temporaire unique (basé sur l'id).
        foreach ($comptes as $c) {
            DB::table('comptes')->where('id', $c->id)->update(['code' => 'TMP'.$c->id]);
        }

        // Phase 2 : temporaire → code CGNC final.
        foreach ($comptes as $c) {
            DB::table('comptes')->where('id', $c->id)->update(['code' => $map[$c->code]]);
        }
    }
};
