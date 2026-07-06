<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1e293b; padding: 32px 40px; line-height: 1.5; }
        .company { font-size: 14px; font-weight: bold; }
        .muted { color: #64748b; }
        .dest { margin-top: 40px; text-align: right; }
        .objet { margin-top: 32px; font-weight: bold; }
        .niveau { display: inline-block; margin-top: 4px; padding: 3px 10px; border-radius: 4px; font-size: 10px; font-weight: bold; }
        .n1 { background: #eff6ff; color: #1d4ed8; }
        .n2 { background: #fff7ed; color: #c2410c; }
        .n3 { background: #fef2f2; color: #b91c1c; }
        .corps { margin-top: 20px; text-align: justify; }
        table.facture { width: 100%; border-collapse: collapse; margin: 20px 0; }
        table.facture th { background: #0f172a; color: #fff; padding: 6px 10px; text-align: left; font-size: 9px; text-transform: uppercase; }
        table.facture td { padding: 6px 10px; border-bottom: 1px solid #e2e8f0; }
        .num { text-align: right; }
        .du { font-size: 14px; font-weight: bold; color: #b91c1c; }
        .footer { margin-top: 40px; }
        .legal { position: fixed; bottom: 24px; left: 40px; right: 40px; border-top: 1px solid #cbd5e1; padding-top: 8px; font-size: 8px; color: #64748b; text-align: center; }
    </style>
</head>
<body>
@php
    $fmt = fn ($v) => number_format((float) $v, 2, ',', ' ');
    $s = $document->tenant->settings ?? [];
    $corps = [
        1 => "Sauf erreur ou omission de notre part, il apparaît que la facture ci-dessous demeure impayée à ce jour. Nous vous prions de bien vouloir procéder à son règlement dans les meilleurs délais. Si votre paiement a été effectué entre-temps, veuillez ne pas tenir compte de ce rappel.",
        2 => "Malgré notre précédent rappel, la facture ci-dessous reste impayée à ce jour. Nous vous demandons de régulariser cette situation sous huitaine. À défaut, nous serions contraints de suspendre nos prestations et d'engager les démarches de recouvrement.",
        3 => "En dépit de nos relances successives, la facture ci-dessous demeure impayée. La présente constitue une MISE EN DEMEURE de régler la somme due sous huit (8) jours. Passé ce délai, et sans règlement de votre part, nous engagerons une procédure de recouvrement contentieux, sans autre avis.",
    ];
@endphp

<table style="width: 100%;">
    <tr>
        <td>
            <div class="company">{{ $document->tenant->name }}</div>
            <div class="muted">{{ $s['address'] ?? '' }}</div>
            <div class="muted">{{ $s['city'] ?? '' }}</div>
            @if (! empty($s['ice']))<div class="muted">ICE : {{ $s['ice'] }}</div>@endif
        </td>
    </tr>
</table>

<div class="dest">
    <div style="font-weight: bold;">{{ $document->tiers->name }}</div>
    @if ($document->tiers->address)<div>{{ $document->tiers->address }}</div>@endif
    @if ($document->tiers->city)<div>{{ $document->tiers->city }} {{ $document->tiers->postal_code }}</div>@endif
    @if ($document->tiers->ice)<div class="muted">ICE : {{ $document->tiers->ice }}</div>@endif
    <div class="muted" style="margin-top: 10px;">Le {{ now()->format('d/m/Y') }}</div>
</div>

<div class="objet">
    Objet : {{ $niveauLabel }} — facture {{ $document->code }}
    <div class="niveau n{{ $niveau }}">Niveau {{ $niveau }} · {{ strtoupper($niveauLabel) }}</div>
</div>

<div class="corps">
    <p>Madame, Monsieur,</p>
    <p style="margin-top: 10px;">{{ $corps[$niveau] }}</p>
</div>

<table class="facture">
    <thead>
        <tr>
            <th>Facture</th>
            <th>Date</th>
            <th>Échéance</th>
            <th class="num">Retard</th>
            <th class="num">Total TTC</th>
            <th class="num">Reste dû</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td>{{ $document->code }}</td>
            <td>{{ $document->date_document?->format('d/m/Y') }}</td>
            <td>{{ $echeance?->format('d/m/Y') }}</td>
            <td class="num">{{ $joursRetard }} j</td>
            <td class="num">{{ $fmt($document->total_ttc) }}</td>
            <td class="num du">{{ $fmt($resteAPayer) }} MAD</td>
        </tr>
    </tbody>
</table>

<div class="corps">
    <p>Nous restons à votre disposition pour toute information et vous prions d'agréer, Madame, Monsieur, l'expression de nos salutations distinguées.</p>
</div>

<div class="footer">
    <div style="font-weight: bold;">{{ $document->tenant->name }}</div>
</div>

<div class="legal">
    {{ $document->tenant->name }}
    @if (! empty($s['ice'])) — ICE : {{ $s['ice'] }} @endif
    @if (! empty($s['if'])) — IF : {{ $s['if'] }} @endif
    — Lettre de relance générée par Dolibarr Maroc
</div>
</body>
</html>
