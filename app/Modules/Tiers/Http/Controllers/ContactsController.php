<?php

namespace App\Modules\Tiers\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tiers\Http\Resources\ContactResource;
use App\Modules\Tiers\Models\Contact;
use App\Modules\Tiers\Models\Tiers;
use App\Modules\Tiers\Services\ContactService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ContactsController extends Controller
{
    public function __construct(private ContactService $service) {}

    public function index(Tiers $tiers): AnonymousResourceCollection
    {
        // Le principal d'abord : c'est celui qu'on cherche neuf fois sur dix.
        $contacts = $tiers->contacts()
            ->orderByDesc('is_principal')
            ->orderBy('nom')
            ->get();

        return ContactResource::collection($contacts);
    }

    public function store(Request $request, Tiers $tiers): JsonResponse
    {
        $contact = $this->service->create($tiers, $this->valider($request));

        return response()->json(['data' => new ContactResource($contact)], 201);
    }

    public function update(Request $request, Tiers $tiers, Contact $contact): JsonResponse
    {
        // Le tiers de l'URL doit être celui du contact : sans ce contrôle,
        // /tiers/1/contacts/42 modifierait le contact du tiers 2.
        abort_unless($contact->tiers_id === $tiers->id, 404);

        $contact = $this->service->update($contact, $this->valider($request, $contact));

        return response()->json(['data' => new ContactResource($contact)]);
    }

    public function destroy(Tiers $tiers, Contact $contact): JsonResponse
    {
        abort_unless($contact->tiers_id === $tiers->id, 404);

        $this->service->delete($contact);

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function valider(Request $request, ?Contact $contact = null): array
    {
        $obligatoire = $contact === null ? 'required' : 'sometimes';

        return $request->validate([
            'nom' => [$obligatoire, 'string', 'max:255'],
            'fonction' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'mobile' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_principal' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
