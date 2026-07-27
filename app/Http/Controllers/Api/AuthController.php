<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Support\SignedMediaUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * LOGIN
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $user = User::where('username', $credentials['username'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'username' => ['Username atau password salah'],
            ]);
        }

        // ❌ user nonaktif
        if (! $user->active) {
            return response()->json([
                'message' => 'Akun Anda tidak aktif. Silakan hubungi admin.',
            ], 403);
        }

        // hapus token lama (optional tapi direkomendasikan)
        $user->tokens()->delete();

        $token = $user->createToken('auth_token')->plainTextToken;

        $organization = $user->organization()->first();
        $project = $user->project()->first();

        return response()->json([
            'message' => 'Login berhasil',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'full_name' => $user->full_name,
                'username' => $user->username,
                'role' => $user->role,
                'project_id' => $user->project_id,
                'organization_id' => $user->organization_id,
                'avatar' => $user->avatar,
                'avatar_url' => $user->avatar ? SignedMediaUrl::userAvatar($user) : null,
                'avatar_storage_url_legacy' => $user->avatar ? Storage::disk('public')->url($user->avatar) : null,
                'organization' => $organization ? [
                    'id' => $organization->id,
                    'name' => $organization->name,
                    'logo' => $organization->logo,
                    'logo_url' => $organization->logo ? $this->organizationLogoUrl($organization) : null,
                ] : null,
                'project' => $project ? [
                    'id' => $project->id,
                    'name' => $project->name,
                    'logo' => $project->logo,
                    'logo_url' => $project->logo ? $this->projectLogoUrl($project) : null,
                ] : null,
            ],
        ]);
    }

    /**
     * LOGOUT
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logout berhasil',
        ]);
    }

    /**
     * CHANGE PASSWORD (SELF)
     */
    public function changePassword(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:8|confirmed',
        ]);

        if (! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Password lama tidak sesuai'],
            ]);
        }

        $user->update([
            'password' => Hash::make($validated['new_password']),
        ]);

        // optional: logout semua sesi
        $user->tokens()->delete();

        return response()->json([
            'message' => 'Password berhasil diubah, silakan login kembali',
        ]);
    }

    /**
     * PROFILE (CHECK TOKEN)
     */
    public function me(Request $request)
    {
        $user = $request->user();
        $organization = $user->organization()->first();
        $project = $user->project()->first();

        return response()->json([
            'user' => array_merge(
                $user->toArray(),
                [
                    'avatar_url' => $user->avatar ? SignedMediaUrl::userAvatar($user) : null,
                    'avatar_storage_url_legacy' => $user->avatar ? Storage::disk('public')->url($user->avatar) : null,
                    'organization' => $organization ? [
                        'id' => $organization->id,
                        'name' => $organization->name,
                        'logo' => $organization->logo,
                        'logo_url' => $organization->logo ? $this->organizationLogoUrl($organization) : null,
                    ] : null,
                    'project' => $project ? [
                        'id' => $project->id,
                        'name' => $project->name,
                        'logo' => $project->logo,
                        'logo_url' => $project->logo ? $this->projectLogoUrl($project) : null,
                    ] : null,
                ]
            ),
        ]);
    }

    private function organizationLogoUrl(Organization $organization): string
    {
        return URL::temporarySignedRoute(
            'media.organization-logo',
            now()->addDays(7),
            ['organization' => $organization->id]
        );
    }

    private function projectLogoUrl(Project $project): string
    {
        return URL::temporarySignedRoute(
            'media.project-logo',
            now()->addDays(7),
            ['project' => $project->id]
        );
    }
}
