<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Le nom vient d'APP_NAME : le même code sert plusieurs déploiements
         (Cloud Run, le VPS), chacun sous son propre nom. L'interface React le
         relit dans la balise application-name (resources/js/lib/marque.ts). --}}
    @php($initiales = collect(preg_split('/\s+/u', trim(config('app.name')), -1, PREG_SPLIT_NO_EMPTY))->take(2)->map(fn ($mot) => mb_strtoupper(mb_substr($mot, 0, 1)))->implode(''))
    <title>{{ config('app.name') }} — ERP & Comptabilité en ligne pour PME marocaines</title>
    <meta name="application-name" content="{{ config('app.name') }}">
    <meta name="contact-email" content="{{ config('app.contact_email') }}">
    <meta name="description" content="Ventes, achats, stock et comptabilité CGNC dans une seule plateforme. Écritures automatiques, état de TVA et export SIMPL-TVA au format DGI. 14 jours d'essai gratuit.">
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='7' fill='%23059669'/%3E%3Ctext x='16' y='22' font-family='system-ui,sans-serif' font-size='15' font-weight='700' fill='white' text-anchor='middle'%3E{{ rawurlencode($initiales) }}%3C/text%3E%3C/svg%3E">
    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="theme-color" content="#059669">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Caisse">
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
</head>
<body class="antialiased">
    <div id="root"></div>
</body>
</html>
