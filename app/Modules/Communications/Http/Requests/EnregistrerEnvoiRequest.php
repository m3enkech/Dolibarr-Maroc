<?php

namespace App\Modules\Communications\Http\Requests;

use App\Core\Format\NumeroWhatsApp;
use App\Modules\Communications\Models\Communication;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ce que le navigateur envoie au moment d'ouvrir WhatsApp : le numéro choisi,
 * la langue choisie, et le texte FINAL (retouches comprises).
 *
 * Les routes des ventes et des relances en dérivent avec leurs propres
 * champs : la nature du message n'est pas la même, la permission non plus.
 */
abstract class EnregistrerEnvoiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    abstract protected function reglesPropres(): array;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->reglesPropres() + [
            // Facultatif : sans numéro, wa.me laisse choisir le contact dans
            // WhatsApp. Le numéro est re-normalisé ici, quel que soit le format
            // reçu — le journal ne garde que la forme canonique.
            'numero' => ['nullable', 'string', 'max:40', function (string $attribut, mixed $valeur, Closure $echec) {
                // Sans `bail`, la règle s'exécute même quand `string` a déjà
                // refusé un tableau — et le transtypage d'un tableau lève une
                // ErrorException : un 500 au lieu du 422 déjà posé.
                if (! is_string($valeur)) {
                    return;
                }

                $numero = NumeroWhatsApp::depuis($valeur);
                if (! $numero->valide()) {
                    $echec(__($numero->raison));
                }
            }],
            'langue' => ['required', Rule::in(Communication::LANGUES)],
            'message' => ['required', 'string', 'max:'.(int) config('communications.message_longueur_max', 2000)],
            // Clé tirée par le navigateur au moment du clic, et renvoyée telle
            // quelle par « Réessayer l'enregistrement » : un second appel pour
            // le MÊME geste retrouve la ligne au lieu d'en écrire une autre.
            // Facultative, pour qu'un appel sans elle reste accepté.
            'cle' => ['nullable', 'uuid'],
        ];
    }

    public function numero(): ?NumeroWhatsApp
    {
        $saisie = $this->validated('numero');

        return $saisie !== null ? NumeroWhatsApp::depuis($saisie) : null;
    }

    /**
     * En minuscules : PostgreSQL compare un uuid sans tenir compte de la casse,
     * SQLite compare des chaînes — la même clé doit être la même partout.
     */
    public function cle(): ?string
    {
        $cle = $this->validated('cle');

        return $cle !== null ? strtolower($cle) : null;
    }
}
