<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1e293b; padding: 24px; }
        .header { width: 100%; margin-bottom: 24px; }
        .header td { vertical-align: top; }
        .doc-title { font-size: 22px; font-weight: bold; color: #059669; text-transform: uppercase; }
        .doc-code { font-size: 13px; font-weight: bold; margin-top: 2px; }
        .brouillon { color: #dc2626; font-size: 11px; font-weight: bold; margin-top: 4px; }
        .company { font-size: 14px; font-weight: bold; }
        .muted { color: #64748b; }
        .box { border: 1px solid #cbd5e1; border-radius: 4px; padding: 10px; }
        .box-title { font-size: 8px; text-transform: uppercase; letter-spacing: 1px; color: #64748b; margin-bottom: 4px; }
        table.lignes { width: 100%; border-collapse: collapse; margin-top: 18px; }
        table.lignes th { background: #0f172a; color: #fff; padding: 6px 8px; text-align: left; font-size: 9px; text-transform: uppercase; }
        table.lignes th.num, table.lignes td.num { text-align: right; }
        table.lignes td { padding: 6px 8px; border-bottom: 1px solid #e2e8f0; }
        table.totaux { margin-top: 14px; width: 240px; margin-left: auto; border-collapse: collapse; }
        table.totaux td { padding: 5px 8px; border-bottom: 1px solid #e2e8f0; }
        table.totaux .grand td { font-weight: bold; font-size: 12px; background: #ecfdf5; border-top: 2px solid #059669; }
        table.tva { border-collapse: collapse; margin-top: 14px; }
        table.tva th, table.tva td { border: 1px solid #cbd5e1; padding: 4px 10px; font-size: 9px; }
        table.tva th { background: #f1f5f9; }
        .notes { margin-top: 18px; }
        .footer { position: fixed; bottom: 0; left: 24px; right: 24px; border-top: 1px solid #cbd5e1; padding: 8px 0; font-size: 8px; color: #64748b; text-align: center; }
    </style>
</head>
<body>
@php
    $titles = ['devis' => 'Devis', 'commande' => 'Bon de commande', 'bon_livraison' => 'Bon de livraison', 'facture' => 'Facture', 'avoir' => 'Avoir'];
    $fmt = fn ($v) => number_format((float) $v, 2, ',', ' ');
@endphp

<table class="header">
    <tr>
        <td style="width: 55%;">
            <div class="company">{{ $document->tenant->name }}</div>
            <div class="muted">{{ $document->tenant->settings['address'] ?? '' }}</div>
            <div class="muted">{{ $document->tenant->settings['city'] ?? '' }}</div>
        </td>
        <td style="text-align: right;">
            <div class="doc-title">{{ $titles[$document->type] }}</div>
            <div class="doc-code">{{ $document->code }}</div>
            @if ($document->statut === 'brouillon')
                <div class="brouillon">BROUILLON — NON DÉFINITIF</div>
            @endif
            @if ($document->type === 'avoir' && $document->source)
                <div class="muted" style="margin-top: 2px;">Avoir sur facture {{ $document->source->code }}</div>
            @endif
            <div class="muted" style="margin-top: 6px;">Date : {{ $document->date_document?->format('d/m/Y') }}</div>
            @if ($document->date_echeance)
                <div class="muted">Échéance : {{ $document->date_echeance->format('d/m/Y') }}</div>
            @endif
        </td>
    </tr>
</table>

<table style="width: 100%;">
    <tr>
        <td style="width: 55%;"></td>
        <td>
            <div class="box">
                <div class="box-title">{{ $document->tiers->is_supplier && ! $document->tiers->is_client ? 'Fournisseur' : 'Client' }}</div>
                <div style="font-weight: bold; font-size: 11px;">{{ $document->tiers->name }}</div>
                @if ($document->tiers->address)<div>{{ $document->tiers->address }}</div>@endif
                @if ($document->tiers->city)<div>{{ $document->tiers->city }} {{ $document->tiers->postal_code }}</div>@endif
                @if ($document->tiers->ice)<div class="muted">ICE : {{ $document->tiers->ice }}</div>@endif
                @if ($document->tiers->if_number)<div class="muted">IF : {{ $document->tiers->if_number }}</div>@endif
            </div>
        </td>
    </tr>
</table>

<table class="lignes">
    <thead>
        <tr>
            <th style="width: 40%;">Désignation</th>
            <th class="num">Qté</th>
            <th class="num">P.U. HT</th>
            <th class="num">Remise</th>
            <th class="num">TVA</th>
            <th class="num">Total HT</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($document->lignes as $ligne)
            <tr>
                <td>{{ $ligne->designation }}</td>
                <td class="num">{{ rtrim(rtrim(number_format((float) $ligne->quantite, 3, ',', ' '), '0'), ',') }}</td>
                <td class="num">{{ $fmt($ligne->prix_unitaire) }}</td>
                <td class="num">{{ (float) $ligne->remise_percent > 0 ? $fmt($ligne->remise_percent).' %' : '—' }}</td>
                <td class="num">{{ number_format((float) $ligne->tva_rate, 0) }} %</td>
                <td class="num">{{ $fmt($ligne->montant_ht) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table style="width: 100%;">
    <tr>
        <td style="vertical-align: top;">
            <table class="tva">
                <thead>
                    <tr><th>Taux TVA</th><th>Base HT</th><th>Montant TVA</th></tr>
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
        </td>
        <td style="vertical-align: top;">
            <table class="totaux">
                <tr><td>Total HT</td><td style="text-align: right;">{{ $fmt($document->total_ht) }} MAD</td></tr>
                <tr><td>Total TVA</td><td style="text-align: right;">{{ $fmt($document->total_tva) }} MAD</td></tr>
                <tr class="grand"><td>Total TTC</td><td style="text-align: right;">{{ $fmt($document->total_ttc) }} MAD</td></tr>
                @if ($document->type === 'facture' && $document->paiements->isNotEmpty())
                    <tr><td>Payé</td><td style="text-align: right;">{{ $fmt($document->paiements->sum('montant')) }} MAD</td></tr>
                    <tr><td><strong>Reste à payer</strong></td><td style="text-align: right;"><strong>{{ $fmt((float) $document->total_ttc - (float) $document->paiements->sum('montant')) }} MAD</strong></td></tr>
                @endif
            </table>
        </td>
    </tr>
</table>

@if ($document->notes)
    <div class="notes">
        <div class="box-title">Notes</div>
        <div>{{ $document->notes }}</div>
    </div>
@endif

<div class="footer">
    {{ $document->tenant->name }}
    @if (! empty($document->tenant->settings['ice'])) — ICE : {{ $document->tenant->settings['ice'] }} @endif
    @if (! empty($document->tenant->settings['if'])) — IF : {{ $document->tenant->settings['if'] }} @endif
    @if (! empty($document->tenant->settings['rc'])) — RC : {{ $document->tenant->settings['rc'] }} @endif
    — Document généré par Dolibarr Maroc
</div>
</body>
</html>
