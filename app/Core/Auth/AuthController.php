<?php

namespace App\Core\Auth;

use App\Core\Tenancy\Tenant;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Inscription SaaS : crée l'entreprise (tenant) et son premier utilisateur admin.
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        [$user, $tenant] = DB::transaction(function () use ($data) {
            $slug = Str::slug($data['company_name']);
            if (Tenant::where('slug', $slug)->exists()) {
                $slug .= '-'.Str::lower(Str::random(4));
            }

            $tenant = Tenant::create([
                'name' => $data['company_name'],
                'slug' => $slug,
                'subscription_status' => 'essai',
                'trial_ends_at' => now()->addDays(14), // 14 jours d'essai
            ]);

            $user = User::create([
                'tenant_id' => $tenant->id,
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'role' => 'admin',
            ]);

            return [$user, $tenant];
        });

        return response()->json([
            'token' => $user->createToken('spa')->plainTextToken,
            'user' => $user,
            'tenant' => $tenant,
            'permissions' => $user->permissionsMap(),
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if ($user === null || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'Identifiants invalides.',
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => 'Ce compte a été désactivé. Contactez votre administrateur.',
            ]);
        }

        if ($user->tenant?->isSuspended()) {
            throw ValidationException::withMessages([
                'email' => 'L\'accès de votre entreprise est suspendu. Contactez le support.',
            ]);
        }

        return response()->json([
            'token' => $user->createToken('spa')->plainTextToken,
            'user' => $user,
            'tenant' => $user->tenant,
            'permissions' => $user->permissionsMap(),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => $user,
            'tenant' => $user->tenant,
            'permissions' => $user->permissionsMap(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Déconnecté.']);
    }

    /** Mise à jour du profil de l'utilisateur connecté (nom). */
    public function updateProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $user = $request->user();
        $user->update(['name' => $data['name']]);

        return response()->json([
            'user' => $user->refresh(),
            'tenant' => $user->tenant,
            'permissions' => $user->permissionsMap(),
        ]);
    }
}
