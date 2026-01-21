<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $auth = $request->user();

        $this->authorize('viewAny', User::class);
    
        $users = User::query()
            ->select(
                'id',
                'full_name',
                'username',
                'email',
                'role',
                'project_id',
                'organization_id',
                'active'
            )
            ->when($auth->role === 'admin_project', function ($q) use ($auth) {
                // 🔐 admin project hanya lihat user di project-nya
                $q->where('project_id', $auth->project_id);
            })
            ->when(in_array($auth->role, ['anggota', 'komandan_regu']), function ($q) {
                // ❌ role ini tidak boleh list user
                $q->whereRaw('1 = 0');
            })
            ->when($request->filled('search'), fn ($q) =>
                $q->where('full_name', 'like', '%' . $request->search . '%')
            )
            ->when($request->has('active'), fn ($q) =>
                $q->where('active', $request->boolean('active'))
            )
            ->where('active', true) // 🔥 FILTER UTAMA
            ->orderBy('full_name')
            ->paginate($request->get('per_page', 15));
    
        return response()->json($users);
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
                'message' => 'Unauthorized. Only developer can create users.'
            ], 403);
        }

        $validated = $request->validate([
            'organization_id' => 'nullable|exists:organizations,id',
            'project_id'      => 'nullable|exists:projects,id',
            'full_name'       => 'required|string|max:255',
            'username'        => 'required|string|unique:users,username',
            'email'           => 'nullable|email|unique:users,email',
            'phone'           => 'nullable|string',
            'role'            => 'required|string',
            'password'        => 'required|min:6',
        ]);

        $validated['password'] = bcrypt($validated['password']);

        $user = User::create($validated);

        return response()->json([
            'message' => 'User created successfully',
            'data' => $user
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
            'avatar' => 'sometimes|nullable|string',
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

        $validated = array_filter(
            $validated,
            fn ($value) => !is_null($value) && $value !== ''
        );

        $user->update($validated);

        return response()->json([
            'message' => 'User updated successfully',
            'data' => $user,
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

        $user->update([
            'active' => false,
        ]);

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
