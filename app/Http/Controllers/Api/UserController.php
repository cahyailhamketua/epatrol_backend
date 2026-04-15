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
            'avatar' // penting untuk generate avatar_url
        )
        ->when($auth->role === 'admin_project', function ($q) use ($auth) {
            // 🔐 admin project hanya lihat user di project-nya
            $q->where('project_id', $auth->project_id);
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
                'password' => 'required|min:6',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'status_code' => 422,
                'errors' => $e->errors(),
            ], 422);
        }

        $validated['password'] = bcrypt($validated['password']);

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
            'data' => $user,
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

        $validated = array_filter(
            $validated,
            fn ($value) => ! is_null($value) && $value !== ''
        );

        $user->update($validated);

        return response()->json([
            'message' => 'User updated successfully',
            'data' => array_merge(
                $user->fresh()->toArray(),
                [
                    'avatar_url' => $user->avatar ? SignedMediaUrl::userAvatar($user) : null,
                    'avatar_storage_url_legacy' => $user->avatar ? Storage::disk('public')->url($user->avatar) : null,
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
}
