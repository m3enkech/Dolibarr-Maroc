<?php

namespace App\Core\Auth;

use App\Http\Controllers\Controller;
use App\Mail\ResetPasswordMail;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Mot de passe oublié / réinitialisation (public) et changement de mot de
 * passe (connecté). Le jeton est stocké haché dans password_reset_tokens ;
 * l'email contient le jeton en clair dans un lien vers le SPA.
 */
class PasswordController extends Controller
{
    private const EXPIRATION_MINUTES = 60;

    /** Demande de réinitialisation : envoie un lien si le compte existe. */
    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $email = Str::lower($data['email']);

        $user = User::where('email', $email)->where('is_active', true)->first();

        if ($user !== null) {
            $token = Str::random(64);

            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $email],
                ['token' => Hash::make($token), 'created_at' => now()],
            );

            $url = rtrim(config('app.url'), '/')."/reinitialiser/{$token}?email=".urlencode($email);
            Mail::to($email)->send(new ResetPasswordMail($url, $user->name));
        }

        // Réponse générique : ne révèle pas si l'email existe.
        return response()->json([
            'message' => 'Si un compte existe pour cet email, un lien de réinitialisation vient d\'être envoyé.',
        ]);
    }

    /** Réinitialise le mot de passe via le jeton reçu par email. */
    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8'],
        ]);
        $email = Str::lower($data['email']);

        $row = DB::table('password_reset_tokens')->where('email', $email)->first();

        $invalide = fn () => throw ValidationException::withMessages([
            'token' => 'Ce lien de réinitialisation est invalide ou expiré.',
        ]);

        if ($row === null || ! Hash::check($data['token'], $row->token)) {
            $invalide();
        }

        if (Carbon::parse($row->created_at)->addMinutes(self::EXPIRATION_MINUTES)->isPast()) {
            $invalide();
        }

        $user = User::where('email', $email)->first();
        if ($user === null) {
            $invalide();
        }

        $user->update(['password' => $data['password']]);

        // Le jeton est à usage unique ; on révoque aussi les sessions actives.
        DB::table('password_reset_tokens')->where('email', $email)->delete();
        $user->tokens()->delete();

        return response()->json(['message' => 'Votre mot de passe a été réinitialisé. Vous pouvez vous connecter.']);
    }

    /** Change le mot de passe d'un utilisateur connecté (vérifie l'actuel). */
    public function change(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'different:current_password'],
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'Le mot de passe actuel est incorrect.',
            ]);
        }

        $user->update(['password' => $data['password']]);

        return response()->json(['message' => 'Mot de passe mis à jour.']);
    }
}
