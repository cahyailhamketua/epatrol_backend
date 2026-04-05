<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
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

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'username' => ['Username atau password salah'],
            ]);
        }

        // ❌ user nonaktif
        if (!$user->active) {
            return response()->json([
                'message' => 'Akun Anda tidak aktif. Silakan hubungi admin.'
            ], 403);
        }

        // hapus token lama (optional tapi direkomendasikan)
        $user->tokens()->delete();

        $token = $user->createToken('auth_token')->plainTextToken;

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
                'avatar_url' => $user->avatar ? Storage::disk('public')->url($user->avatar) : null,
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

        if (!Hash::check($validated['current_password'], $user->password)) {
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
        return response()->json([
            'user' => array_merge(
                $user->toArray(),
                [
                    'avatar_url' => $user->avatar ? Storage::disk('public')->url($user->avatar) : null,
                ]
            ),
        ]);
    }
}
