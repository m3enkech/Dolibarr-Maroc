<?php

namespace App\Modules\Portail\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Portail\Http\ClientDuGrossiste;
use App\Modules\Portail\Services\PortailFactureService;
use App\Modules\Ventes\Services\DocumentPdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class FacturesPortailController extends Controller
{
    use ClientDuGrossiste;

    public function __construct(private PortailFactureService $service) {}

    public function index(Request $request): JsonResponse
    {
        $client = $this->client($request);

        return response()->json(
            $this->service->lister($client, max(1, $request->integer('page', 1)))
            + ['situation' => $this->service->situation($client)],
        );
    }

    public function show(Request $request): JsonResponse
    {
        // Paramètre lu par son NOM : l'URL porte d'abord {grossiste}, un
        // argument positionnel recevrait donc le slug.
        $id = (int) $request->route('facture');

        return response()->json(['data' => $this->service->detail($this->client($request), $id)]);
    }

    /** Le PDF de l'ERP, servi tel quel : c'est la même pièce, elle doit dire la même chose. */
    public function pdf(Request $request, DocumentPdfService $pdf): Response
    {
        $document = $this->service->document($this->client($request), (int) $request->route('facture'));

        return $pdf->rendre($document)->download($document->code.'.pdf');
    }
}
