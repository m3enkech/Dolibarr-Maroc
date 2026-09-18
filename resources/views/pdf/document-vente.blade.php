<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 22px 26px 78px 26px; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9.5px; color: #1f2937; }

        /* --- En-tête : émetteur à gauche, titre et solde à droite. --- */
        table.entete { width: 100%; }
        table.entete td { vertical-align: top; }
        .emetteur-nom { font-size: 15px; font-weight: bold; color: #0f172a; }
        .emetteur-ligne { color: #475569; line-height: 1.5; }
        .doc-titre { font-size: 26px; font-weight: bold; color: #0f172a; text-align: right; letter-spacing: -0.5px; }
        .doc-numero { text-align: right; color: #475569; margin-top: 2px; }
        .brouillon { color: #b91c1c; font-weight: bold; text-align: right; margin-top: 4px; letter-spacing: 1px; }

        /* Le solde dû, encadré : c'est ce que le client cherche en premier. */
        .solde { margin-top: 10px; background: #f1f5f9; padding: 7px 10px; text-align: right; }
        .solde-libelle { color: #475569; font-size: 8.5px; text-transform: uppercase; letter-spacing: 0.6px; }
        .solde-montant { font-size: 15px; font-weight: bold; color: #0f172a; margin-top: 1px; }

        /* --- Destinataire à gauche, métadonnées à droite. --- */
        table.parties { width: 100%; margin-top: 26px; }
        table.parties > tbody > tr > td { vertical-align: top; }
        .bloc-titre { font-size: 8.5px; text-transform: uppercase; letter-spacing: 0.8px; color: #64748b; margin-bottom: 5px; }
        .client-nom { font-size: 12px; font-weight: bold; color: #0f172a; }
        .client-ligne { color: #334155; line-height: 1.5; }
        table.meta { width: 100%; border-collapse: collapse; }
        table.meta td { padding: 2.5px 0; vertical-align: top; }
        table.meta td.libelle { color: #64748b; padding-right: 10px; }
        table.meta td.valeur { text-align: right; font-weight: bold; color: #0f172a; }

        /* --- Lignes. --- */
        table.lignes { width: 100%; border-collapse: collapse; margin-top: 22px; }
        table.lignes th { background: #0f172a; color: #fff; padding: 7px 8px; text-align: left; font-size: 8.5px; text-transform: uppercase; letter-spacing: 0.4px; font-weight: normal; }
        table.lignes th.num, table.lignes td.num { text-align: right; }
        table.lignes td { padding: 8px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        table.lignes tr.paire td { background: #f8fafc; }
        .rang { color: #94a3b8; }
        .colis { font-size: 8.5px; color: #64748b; margin-top: 2px; }

        /* --- Totaux et ventilation de TVA. --- */
        table.bas { width: 100%; margin-top: 14px; }
        table.bas > tbody > tr > td { vertical-align: top; }
        table.tva { border-collapse: collapse; }
        table.tva th, table.tva td { border: 1px solid #cbd5e1; padding: 4px 9px; font-size: 8.5px; }
        table.tva th { background: #f1f5f9; color: #334155; font-weight: normal; }
        table.totaux { width: 100%; border-collapse: collapse; }
        table.totaux td { padding: 5px 9px; }
        table.totaux td.valeur { text-align: right; }
        table.totaux tr.sep td { border-top: 1px solid #e2e8f0; }
        table.totaux tr.grand td { font-weight: bold; font-size: 12px; border-top: 2px solid #0f172a; border-bottom: 2px solid #0f172a; }
        table.totaux tr.du td { font-weight: bold; background: #f1f5f9; }

        /* --- Mention légale en toutes lettres (art. 145 du CGI). --- */
        .lettres { margin-top: 16px; border-top: 1px solid #e2e8f0; padding-top: 9px; }
        .lettres-valeur { font-weight: bold; color: #0f172a; margin-top: 2px; }

        .remarques { margin-top: 16px; color: #334155; line-height: 1.55; }
        .signature { margin-top: 30px; text-align: right; color: #64748b; }
        .signature-trait { margin-top: 34px; border-top: 1px solid #94a3b8; width: 180px; margin-left: auto; padding-top: 4px; }

        /* Le bandeau légal se répète sur CHAQUE page : une page détachée d'une
           facture doit rester identifiable et régulière. */
        .pied { position: fixed; bottom: -58px; left: 0; right: 0; border-top: 1px solid #cbd5e1; padding-top: 6px; font-size: 7.5px; color: #64748b; text-align: center; line-height: 1.6; }
    </style>
</head>
<body>
@php
    $titres = ['devis' => 'Devis', 'commande' => 'Bon de commande', 'bon_livraison' => 'Bon de livraison', 'facture' => 'Facture', 'avoir' => 'Avoir'];
    $fmt = fn ($v) => number_format((float) $v, 2, ',', ' ');
    $qte = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, ',', ' '), '0'), ',');
    $s = $document->tenant->settings ?? [];

    $paye = (float) $document->paiements->sum('montant');
    $du = round((float) $document->total_ttc - $paye, 2);
    $estFacture = in_array($document->type, ['facture', 'avoir'], true);

    // Le bon de commande du client : sa propre référence d'abord, à défaut
    // notre commande d'origine — c'est celle qu'il reconnaîtra.
    $bonCommande = $document->reference_client ?: ($document->source?->type === 'commande' ? $document->source->code : null);

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
            <div class="emetteur-nom">{{ $document->tenant->name }}</div>
            @if (! empty($s['address']))<div class="emetteur-ligne">{{ $s['address'] }}</div>@endif
            @if (! empty($s['city']) || ! empty($s['postal_code']))
                <div class="emetteur-ligne">{{ trim(($s['postal_code'] ?? '').' '.($s['city'] ?? '')) }}</div>
            @endif
            @if (! empty($s['phone']))<div class="emetteur-ligne">{{ $s['phone'] }}</div>@endif
            @if (! empty($s['email']))<div class="emetteur-ligne">{{ $s['email'] }}</div>@endif
            @if (! empty($s['website']))<div class="emetteur-ligne">{{ $s['website'] }}</div>@endif
        </td>
        <td>
            <div class="doc-titre">{{ $titres[$document->type] }}</div>
            <div class="doc-numero">N° {{ $document->code }}</div>

            @if ($document->statut === 'brouillon')
                <div class="brouillon">BROUILLON — NON DÉFINITIF</div>
            @endif
            @if ($document->type === 'avoir' && $document->source)
                <div class="doc-numero">Avoir sur facture {{ $document->source->code }}</div>
            @endif

            @if ($estFacture)
                <div class="solde">
                    <div class="solde-libelle">{{ $du > 0.004 ? 'Solde dû' : 'Réglée' }}</div>
                    <div class="solde-montant">{{ $fmt(max($du, 0)) }} DH</div>
                </div>
            @endif
        </td>
    </tr>
</table>

<table class="parties">
    <tr>
        <td style="width: 52%; padding-right: 24px;">
            <div class="bloc-titre">
                {{ $document->tiers->is_supplier && ! $document->tiers->is_client ? 'Fournisseur' : 'Facturé à' }}
            </div>
            <div class="client-nom">{{ $document->tiers->name }}</div>
            @if ($document->tiers->ice)<div class="client-ligne">ICE : {{ $document->tiers->ice }}</div>@endif
            @if ($document->tiers->if_number)<div class="client-ligne">I.F : {{ $document->tiers->if_number }}</div>@endif
            <div class="client-ligne">Code client : {{ $document->tiers->code }}</div>
            @if ($document->tiers->address)<div class="client-ligne" style="margin-top: 4px;">{{ $document->tiers->address }}</div>@endif
            @if ($document->tiers->city || $document->tiers->postal_code)
                <div class="client-ligne">{{ trim(($document->tiers->postal_code ?? '').' '.($document->tiers->city ?? '')) }}</div>
            @endif
        </td>
        <td>
            <table class="meta">
                <tr>
                    <td class="libelle">Date{{ $document->type === 'facture' ? ' de facture' : '' }} :</td>
                    <td class="valeur">{{ $document->date_document?->format('d/m/Y') }}</td>
                </tr>
                @if ($document->date_echeance)
                    <tr>
                        <td class="libelle">Date d'échéance :</td>
                        <td class="valeur">{{ $document->date_echeance->format('d/m/Y') }}</td>
                    </tr>
                @endif
                @if (! empty($s['conditions_paiement']))
                    <tr>
                        <td class="libelle">Conditions :</td>
                        <td class="valeur">{{ $s['conditions_paiement'] }}</td>
                    </tr>
                @endif
                @if ($bonCommande)
                    <tr>
                        <td class="libelle">N° de bon de commande :</td>
                        <td class="valeur">{{ $bonCommande }}</td>
                    </tr>
                @endif
            </table>
        </td>
    </tr>
</table>

<table class="lignes">
    <thead>
        <tr>
            <th style="width: 4%;">#</th>
            <th style="width: 40%;">Article &amp; description</th>
            <th class="num">Quantité</th>
            <th class="num">P.U. HT</th>
            <th class="num">Remise</th>
            <th class="num">TVA</th>
            <th class="num">Montant HT</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($document->lignes as $i => $ligne)
            <tr @class(['paire' => $i % 2 === 1])>
                <td class="rang">{{ $i + 1 }}</td>
                <td>
                    {{ $ligne->designation }}
                    @if ($ligne->conditionnement_id && $ligne->quantite_colis)
                        {{-- Vente au colis : le client commande des cartons, pas des pièces. --}}
                        <div class="colis">{{ $qte($ligne->quantite_colis) }} × {{ $ligne->conditionnement->nom ?? 'colis' }}</div>
                    @endif
                </td>
                <td class="num">{{ $qte($ligne->quantite) }}</td>
                <td class="num">{{ $fmt($ligne->prix_unitaire) }}</td>
                <td class="num">{{ (float) $ligne->remise_percent > 0 ? $fmt($ligne->remise_percent).' %' : '—' }}</td>
                <td class="num">{{ number_format((float) $ligne->tva_rate, 0) }} %</td>
                <td class="num">{{ $fmt($ligne->montant_ht) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table class="bas">
    <tr>
        <td style="width: 52%; padding-right: 24px;">
            @if ($tvaBreakdown->count() > 1 || (float) $document->total_tva > 0)
                <div class="bloc-titre">Ventilation de la TVA</div>
                <table class="tva">
                    <thead>
                        <tr><th>Taux</th><th>Base HT</th><th>Montant TVA</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($tvaBreakdown as $line)
                            <tr>
                                <td>{{ number_format($line['rate'], 0) }} %</td>
                                <td style="text-align: right;">{{ $fmt($line['ht']) }}</td>
                                <td style="text-align: right;">{{ $fmt($line['tva']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </td>
        <td>
            <table class="totaux">
                <tr><td>Sous-total HT</td><td class="valeur">{{ $fmt($document->total_ht) }}</td></tr>
                <tr><td>TVA</td><td class="valeur">{{ $fmt($document->total_tva) }}</td></tr>
                <tr class="grand"><td>Total TTC</td><td class="valeur">{{ $fmt($document->total_ttc) }} DH</td></tr>
                @if ($estFacture && $document->paiements->isNotEmpty())
                    <tr class="sep"><td>Déjà réglé</td><td class="valeur">{{ $fmt($paye) }}</td></tr>
                    <tr class="du"><td>Solde dû</td><td class="valeur">{{ $fmt($du) }} DH</td></tr>
                @endif
            </table>
        </td>
    </tr>
</table>

{{-- Article 145 du CGI : la mention en toutes lettres fait foi. --}}
<div class="lettres">
    <span class="bloc-titre">Arrêté la présente {{ mb_strtolower($titres[$document->type]) }} à la somme de</span>
    <div class="lettres-valeur">{{ $montantEnLettres }}</div>
</div>

@if ($document->notes)
    <div class="remarques">
        <div class="bloc-titre">Remarques</div>
        {!! nl2br(e($document->notes)) !!}
    </div>
@endif

<div class="signature">
    <div class="signature-trait">Signature autorisée</div>
</div>

<div class="pied">
    <strong>{{ $document->tenant->name }}</strong>
    @if ($mentions)<br>{{ implode(' — ', $mentions) }}@endif
</div>
</body>
</html>
