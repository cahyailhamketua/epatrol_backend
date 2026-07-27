<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Schedule;
use App\Models\TeamUser;
use App\Models\User;
use App\Services\ImageWebpService;
use App\Support\SignedMediaUrl;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
public function index(Request $request)
{
    $auth = $request->user();

    $this->authorize('viewAny', User::class);

    try {
        $request->validate([
            'search' => 'nullable|string',
            'active' => 'nullable|boolean',
        ]);
    } catch (ValidationException $e) {
        return response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'status_code' => 422,
            'errors' => $e->errors(),
        ], 422);
    }

    $users = User::query()
        ->select(
            'id',
            'full_name',
            'username',
            'email',
            'role',
            'project_id',
            'organization_id',
            'active',
            'avatar', // penting untuk generate avatar_url
            'ktp_photo'
        )
        ->when($auth->role === 'admin_project', function ($q) use ($auth) {
            // 🔐 admin project hanya lihat user di project-nya
            $q->where('project_id', $auth->project_id);
        })
        ->when($auth->role === 'ho', function ($q) use ($auth) {
            $q->where('organization_id', $auth->organization_id)
              ->where('role', '!=', 'ho')
              ->where('id', '!=', $auth->id);
        })
        ->when(in_array($auth->role, ['anggota', 'komandan_regu']), function ($q) {
            // ❌ role ini tidak boleh list user
            $q->whereRaw('1 = 0');
        })
        ->when($request->filled('search'), function ($q) use ($request) {
            $q->where('full_name', 'like', '%' . $request->search . '%');
        })
        ->when($request->has('active'), function ($q) use ($request) {
            $q->where('active', $request->boolean('active'));
        })
        ->when($auth->role !== 'dev', function ($q) {
            $q->where('active', true);
        })
        ->get()
        ->map(function ($user) {
            return [
                'id' => $user->id,
                'full_name' => $user->full_name,
                'username' => $user->username,
                'email' => $user->email,
                'role' => $user->role,
                'project_id' => $user->project_id,
                'organization_id' => $user->organization_id,
                'active' => $user->active,
                'avatar_url' => $user->avatar
                    ? SignedMediaUrl::userAvatar($user)
                    : null,
                'ktp_photo_url' => $user->ktp_photo ? SignedMediaUrl::userKtpPhoto($user) : null,
            ];
        });

    return response()->json([
        'success' => true,
        'message' => 'List users berhasil diambil',
        'data' => $users,
    ]);
}

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $authUser = $request->user();

        // 🔒 HANYA DEV YANG BOLEH CREATE USER
        if ($authUser->role !== 'dev') {
            return response()->json([
                'message' => 'Unauthorized. Only developer can create users.',
            ], 403);
        }

        try {
            $validated = $request->validate([
                'organization_id' => 'nullable|exists:organizations,id',
                'project_id' => 'nullable|exists:projects,id',
                'full_name' => 'required|string|max:255',
                'username' => 'required|string|unique:users,username',
                'email' => 'nullable|email|unique:users,email',
                'phone' => 'nullable|string',
                'role' => 'required|string',
                'password' => 'sometimes|nullable|min:6',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'status_code' => 422,
                'errors' => $e->errors(),
            ], 422);
        }

        // Generate password automatically based on role if available.
        $role = $validated['role'] ?? $request->input('role');

        $rolePasswordMap = [
            'anggota' => 'anggota123',
            'komandan_regu' => 'danru123',
            'admin' => 'admin123',
            'admin_project' => 'admin123',
            'ho' => 'headoffice123',
        ];

        if (isset($rolePasswordMap[$role])) {
            $plainPassword = $rolePasswordMap[$role];
        } elseif (!empty($validated['password'])) {
            $plainPassword = $validated['password'];
        } else {
            $plainPassword = 'password123';
        }

        $validated['password'] = bcrypt($plainPassword);

        $user = User::create($validated);

        return response()->json([
            'message' => 'User created successfully',
            'data' => $user,
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(User $user)
    {
        $this->authorize('view', $user);

        return response()->json([
            'data' => array_merge(
                $user->toArray(),
                [
                    'avatar_url' => $user->avatar ? SignedMediaUrl::userAvatar($user) : null,
                    'avatar_storage_url_legacy' => $user->avatar ? Storage::disk('public')->url($user->avatar) : null,
                    'ktp_photo_url' => $user->ktp_photo ? SignedMediaUrl::userKtpPhoto($user) : null,
                    'ktp_photo_storage_url_legacy' => $user->ktp_photo ? Storage::disk('public')->url($user->ktp_photo) : null,
                ]
            ),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, User $user)
    {
        $this->authorize('update', $user);

        $validated = $request->validate([
            'full_name' => 'sometimes|string|max:255',
            'email' => [
                'sometimes',
                'nullable',
                'email',
                Rule::unique('users')->ignore($user->id),
            ],
            'phone' => 'sometimes|nullable|string|max:20',
            'avatar' => 'sometimes|nullable|image|max:2048',
            'ktp_photo' => 'sometimes|nullable|image|max:4096',
            'nik' => 'sometimes|nullable|string|max:20',
            'npwp' => 'sometimes|nullable|string|max:20',
            'bpjs_kesehatan' => 'sometimes|nullable|string|max:20',
            'bpjs_ketenagakerjaan' => 'sometimes|nullable|string|max:20',
            'bank_name' => 'sometimes|nullable|string|max:20',
            'bank_account' => 'sometimes|nullable|string|max:20',
            'join_date' => 'sometimes|nullable|date',
            'active' => 'sometimes|boolean',

            'project_id' => 'sometimes|nullable|exists:projects,id',
            'organization_id' => 'sometimes|nullable|exists:organizations,id',
        ]);

        // ❌ Role tidak boleh diubah lewat sini
        unset($validated['role']);

        // Handle avatar upload (convert to webp)
        if ($request->hasFile('avatar')) {
            $service = app(ImageWebpService::class);

            // Delete old avatar file if exists
            if ($user->avatar && Storage::disk('public')->exists($user->avatar)) {
                Storage::disk('public')->delete($user->avatar);
            }

            $validated['avatar'] = $service->storeAsWebp($request->file('avatar'), 'avatars', 80);
        } else {
            unset($validated['avatar']);
        }

        // Handle ktp_photo upload (store original)
        if ($request->hasFile('ktp_photo')) {
            // Delete old ktp file if exists
            if ($user->ktp_photo && Storage::disk('public')->exists($user->ktp_photo)) {
                Storage::disk('public')->delete($user->ktp_photo);
            }

            $validated['ktp_photo'] = Storage::disk('public')->putFile('ktp_photos', $request->file('ktp_photo'));
        } else {
            unset($validated['ktp_photo']);
        }

        $validated = array_filter(
            $validated,
            fn ($value) => ! is_null($value) && $value !== ''
        );

        $user->update($validated);
        
        \Log::info('USER UPDATED CONTROLLER', [
            'id' => $user->id,
            'changes' => $user->getChanges(),
        ]);

        $freshUser = $user->fresh();

        return response()->json([
            'message' => 'User updated successfully',
            'data' => array_merge(
                $freshUser->toArray(),
                [
                    'avatar_url' => $freshUser->avatar ? SignedMediaUrl::userAvatar($freshUser) : null,
                    'avatar_storage_url_legacy' => $freshUser->avatar ? Storage::disk('public')->url($freshUser->avatar) : null,
                    'ktp_photo_url' => $freshUser->ktp_photo ? SignedMediaUrl::userKtpPhoto($freshUser) : null,
                    'ktp_photo_storage_url_legacy' => $freshUser->ktp_photo ? Storage::disk('public')->url($freshUser->ktp_photo) : null,
                ]
            ),
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }

    /**
     * NONAKTIFKAN USER
     */
    public function deactivate(User $user)
    {
        $this->authorize('deactivate', $user);

        DB::transaction(function () use ($user) {
            $today = Carbon::today();
            $monthStart = $today->copy()->startOfMonth();
            $monthEnd = $today->copy()->endOfMonth();

            $user->update([
                'active' => false,
            ]);

            // Tutup membership tim aktif per hari ini (agar bulan berikutnya tidak ikut generate).
            TeamUser::where('user_id', $user->id)
                ->whereNull('end_date')
                ->update(['end_date' => $today->toDateString()]);

            // Jadwal bulan berjalan tetap ada namun ditandai prorate keluar.
            Schedule::where('user_id', $user->id)
                ->whereBetween('date', [$monthStart, $monthEnd])
                ->update(['membership_status' => Schedule::STATUS_PRORATE_OUT]);
        });

        return response()->json([
            'message' => 'User has been deactivated',
        ]);
    }

    /**
     * AKTIFKAN KEMBALI USER
     */
    public function activate(User $user)
    {
        $this->authorize('activate', $user);

        $user->update([
            'active' => true,
        ]);

        return response()->json([
            'message' => 'User has been activated',
        ]);
    }

    /**
     * Reset user's password back to default based on role.
     * Only callable by users with role `dev`.
     */
    public function resetPassword(Request $request, User $user)
    {
        $authUser = $request->user();

        if ($authUser->role !== 'dev') {
            return response()->json([
                'message' => 'Unauthorized. Only developer can reset passwords.',
            ], 403);
        }

        $role = $user->role;

        $rolePasswordMap = [
            'anggota' => 'anggota123',
            'komandan_regu' => 'danru123',
            'admin' => 'admin123',
            'admin_project' => 'admin123',
            'ho' => 'headoffice123',
        ];

        if (isset($rolePasswordMap[$role])) {
            $plainPassword = $rolePasswordMap[$role];
        } else {
            $plainPassword = 'password123';
        }

        $user->password = bcrypt($plainPassword);
        $user->save();

        return response()->json([
            'message' => 'Password has been reset to default for this user',
            'new_password' => $plainPassword,
        ]);
    }
}
