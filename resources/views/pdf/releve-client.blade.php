<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <style>
        /* Mêmes marges que les factures, pour les mêmes raisons : le bandeau
           légal vit dans la marge basse et se répète sur chaque page. */
        @page { margin: 22px 26px 78px 26px; }
        /* Remise à zéro du CONTENU seulement (« body * ») — jamais « * » ni
           html/body : chez DomPDF, l'un comme l'autre efface les marges de
           @page, et le texte touche les bords de la feuille (voir la facture,
           document-vente.blade.php, où c'est mesuré). */
        body * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #1f2937; }

        table.entete { width: 100%; }
        table.entete td { vertical-align: top; }
        .emetteur-nom { font-size: 15px; font-weight: bold; color: #0f172a; }
        .emetteur-ligne { color: #475569; line-height: 1.5; }
        .doc-titre { font-size: 22px; font-weight: bold; color: #0f172a; text-align: right; letter-spacing: -0.5px; }
        .doc-numero { text-align: right; color: #475569; margin-top: 2px; }

        /* Le solde arrêté, encadré : c'est ce que le destinataire cherche. */
        .solde { margin-top: 10px; background: #f1f5f9; padding: 7px 10px; text-align: right; }
        .solde-libelle { color: #475569; font-size: 8.5px; text-transform: uppercase; letter-spacing: 0.6px; }
        .solde-montant { font-size: 15px; font-weight: bold; color: #0f172a; margin-top: 1px; }

        table.parties { width: 100%; margin-top: 24px; }
        table.parties > tbody > tr > td { vertical-align: top; }
        .bloc-titre { font-size: 8.5px; text-transform: uppercase; letter-spacing: 0.8px; color: #64748b; margin-bottom: 5px; }
        .client-nom { font-size: 12px; font-weight: bold; color: #0f172a; }
        .client-ligne { color: #334155; line-height: 1.5; }
        table.meta { width: 100%; border-collapse: collapse; }
        table.meta td { padding: 2.5px 0; vertical-align: top; }
        table.meta td.libelle { color: #64748b; padding-right: 10px; }
        table.meta td.valeur { text-align: right; font-weight: bold; color: #0f172a; }

        /* Les mouvements. L'en-tête du tableau se répète en haut de chaque
           page (thead) : une page détachée reste lisible. */
        table.lignes { width: 100%; border-collapse: collapse; margin-top: 20px; }
        table.lignes thead { display: table-header-group; }
        table.lignes th { background: #0f172a; color: #fff; padding: 6px 6px; text-align: left; font-size: 8px; text-transform: uppercase; letter-spacing: 0.4px; font-weight: normal; }
        table.lignes th.num, table.lignes td.num { text-align: right; white-space: nowrap; }
        table.lignes td { padding: 5px 6px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        table.lignes tr { page-break-inside: avoid; }
        table.lignes tr.paire td { background: #f8fafc; }
        table.lignes tr.report td { background: #f1f5f9; font-weight: bold; color: #0f172a; }
        table.lignes tr.totaux td { border-top: 1px solid #0f172a; font-weight: bold; }
        table.lignes tr.final td { background: #f1f5f9; font-weight: bold; color: #0f172a; border-top: 2px solid #0f172a; border-bottom: 2px solid #0f172a; font-size: 10px; }
        .piece { white-space: nowrap; }
        .date { white-space: nowrap; }

        .note { margin-top: 14px; color: #475569; line-height: 1.55; page-break-inside: avoid; }

        .pied { position: fixed; bottom: -58px; left: 0; right: 0; border-top: 1px solid #cbd5e1; padding-top: 6px; font-size: 7.5px; color: #64748b; text-align: center; line-height: 1.6; }
    </style>
</head>
<body>
@php
    // Les documents restent en FRANÇAIS, par décision : comme les factures.
    $fmt = fn ($v) => number_format((float) $v, 2, ',', ' ');
    $date = fn (string $d) => \Carbon\CarbonImmutable::createFromFormat('!Y-m-d', $d)->format('d/m/Y');
    $s = $tiers->tenant->settings ?? [];
    $fournisseur = $releve['compte'] === 'fournisseur';
    $final = (float) $releve['solde_final'];

    // Le solde lu par le DESTINATAIRE : un client à qui l'on doit, ou un
    // fournisseur qui nous doit, lit « en votre faveur » ; l'inverse est dû.
    $libelleSolde = abs($final) < 0.005
        ? 'Compte soldé'
        : (($final > 0) !== $fournisseur ? 'Solde dû' : 'Solde en votre faveur');

    $mentions = array_filter([
        ! empty($s['capital']) ? 'Capital de '.$s['capital'] : null,
        ! empty($s['rc']) ? 'R.C : '.$s['rc'] : null,
        ! empty($s['patente']) ? 'Patente : '.$s['patente'] : null,
        ! empty($s['if']) ? 'I.F : '.$s['if'] : null,
        ! empty($s['cnss']) ? 'C.N.S.S : '.$s['cnss'] : null,
        ! empty($s['ice']) ? 'ICE : '.$s['ice'] : null,
    ]);
@endphp

<table class="entete">
    <tr>
        <td style="width: 52%;">
            <div class="emetteur-nom">{{ $tiers->tenant->name }}</div>
            @if (! empty($s['address']))<div class="emetteur-ligne">{{ $s['address'] }}</div>@endif
            @if (! empty($s['city']) || ! empty($s['postal_code']))
                <div class="emetteur-ligne">{{ trim(($s['postal_code'] ?? '').' '.($s['city'] ?? '')) }}</div>
            @endif
            @if (! empty($s['phone']))<div class="emetteur-ligne">{{ $s['phone'] }}</div>@endif
            @if (! empty($s['email']))<div class="emetteur-ligne">{{ $s['email'] }}</div>@endif
            @if (! empty($s['website']))<div class="emetteur-ligne">{{ $s['website'] }}</div>@endif
        </td>
        <td>
            <div class="doc-titre">Relevé de compte</div>
            <div class="doc-numero">{{ $fournisseur ? 'Compte fournisseur' : 'Compte client' }}</div>
            <div class="doc-numero">Du {{ $date($releve['du']) }} au {{ $date($releve['au']) }}</div>

            <div class="solde">
                <div class="solde-libelle">{{ $libelleSolde }} au {{ $date($releve['au']) }}</div>
                <div class="solde-montant">{{ $fmt(abs($final)) }} DH</div>
            </div>
        </td>
    </tr>
</table>

<table class="parties">
    <tr>
        <td style="width: 52%; padding-right: 24px;">
            <div class="bloc-titre">Destinataire</div>
            <div class="client-nom">{{ $tiers->name }}</div>
            @if ($tiers->ice)<div class="client-ligne">ICE : {{ $tiers->ice }}</div>@endif
            @if ($tiers->if_number)<div class="client-ligne">I.F : {{ $tiers->if_number }}</div>@endif
            @if ($tiers->address)<div class="client-ligne" style="margin-top: 4px;">{{ $tiers->address }}</div>@endif
            @if ($tiers->city || $tiers->postal_code)
                <div class="client-ligne">{{ trim(($tiers->postal_code ?? '').' '.($tiers->city ?? '')) }}</div>
            @endif
        </td>
        <td>
            <table class="meta">
                <tr>
                    <td class="libelle">{{ $fournisseur ? 'Code fournisseur' : 'Code client' }} :</td>
                    <td class="valeur">{{ $tiers->code }}</td>
                </tr>
                <tr>
                    <td class="libelle">Période :</td>
                    <td class="valeur">{{ $date($releve['du']) }} – {{ $date($releve['au']) }}</td>
                </tr>
                <tr>
                    <td class="libelle">Édité le :</td>
                    <td class="valeur">{{ $edite->format('d/m/Y') }}</td>
                </tr>
                <tr>
                    <td class="libelle">Devise :</td>
                    <td class="valeur">{{ $releve['devise'] }}</td>
                </tr>
            </table>
        </td>
    </tr>
</table>

<table class="lignes">
    <thead>
        <tr>
            <th style="width: 11%;">Date</th>
            <th style="width: 16%;">Pièce</th>
            <th>Libellé</th>
            <th class="num" style="width: 13%;">Débit</th>
            <th class="num" style="width: 13%;">Crédit</th>
            <th class="num" style="width: 14%;">Solde</th>
        </tr>
    </thead>
    <tbody>
        {{-- Le report est le solde du SOIR de la veille : les mouvements du
             premier jour sont dans la période. « Solde au {du} » ici et « Solde
             au {au} » plus bas donnaient, pour une seule journée, deux soldes
             différents à la même date. --}}
        <tr class="report">
            <td class="date">{{ $date($releve['report_au']) }}</td>
            <td></td>
            <td>Solde au {{ $date($releve['report_au']) }} (report)</td>
            <td class="num"></td>
            <td class="num"></td>
            <td class="num">{{ $fmt($releve['solde_initial']) }}</td>
        </tr>
        @foreach ($releve['lignes'] as $i => $ligne)
            <tr @class(['paire' => $i % 2 === 1])>
                <td class="date">{{ $date($ligne['date']) }}</td>
                <td class="piece">{{ $ligne['piece'] }}</td>
                <td>{{ $ligne['libelle'] }}</td>
                <td class="num">{{ (float) $ligne['debit'] > 0 ? $fmt($ligne['debit']) : '' }}</td>
                <td class="num">{{ (float) $ligne['credit'] > 0 ? $fmt($ligne['credit']) : '' }}</td>
                <td class="num">{{ $fmt($ligne['solde']) }}</td>
            </tr>
        @endforeach
        <tr class="totaux">
            <td colspan="3">Totaux de la période</td>
            <td class="num">{{ $fmt($releve['total_debit']) }}</td>
            <td class="num">{{ $fmt($releve['total_credit']) }}</td>
            <td class="num"></td>
        </tr>
        <tr class="final">
            <td colspan="5">Solde au {{ $date($releve['au']) }}</td>
            <td class="num">{{ $fmt($releve['solde_final']) }}</td>
        </tr>
    </tbody>
</table>

<div class="note">
    @if ($fournisseur)
        Solde positif : montant que nous vous devons ; négatif : montant que vous nous devez.
    @else
        Solde positif : montant restant dû ; négatif : montant en votre faveur.
    @endif
    Sauf erreur ou omission de notre part. Merci de nous signaler toute différence avec votre comptabilité.
</div>

<div class="pied">
    <strong>{{ $tiers->tenant->name }}</strong>
    @if ($mentions)<br>{{ implode(' — ', $mentions) }}@endif
</div>
</body>
</html>
