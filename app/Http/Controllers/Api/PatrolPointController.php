<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PatrolPoint;
use App\Models\PatrolScan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class PatrolPointController extends Controller
{
    /**
     * CREATE PATROL POINT
     * POST /posts/{post}/patrol-points
     * 
     * Logic:
     * - Static post: hanya 1 patrol point (untuk komandan regu)
     * - Mobile post: multiple patrol points dengan sequence order
     * - Sequence order harus unique per post
     * - Altitude untuk validasi ketinggian saat scanning
     * - Radius untuk validasi distance
     */
    public function store(Request $request, Post $post)
    {
        $this->authorize('manage', [PatrolPoint::class, $post->project]);

        try {
            $validated = $request->validate([
                'name' => 'required|string|max:100',
                'sequence_order' => 'required|integer|min:1',
                'latitude' => 'required|numeric|between:-90,90',
                'longitude' => 'required|numeric|between:-180,180',
                'altitude' => 'nullable|numeric',
                'radius' => 'nullable|integer|min:1',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success'     => false,
                'message'     => 'Validation failed',
                'status_code' => 422,
                'errors'      => $e->errors(),
            ], 422);
        }

        // ===== VALIDATION LOGIC =====
        
        // 1. Jika post type 'static': hanya boleh 1 patrol point
        if ($post->type === 'static') {
            $existingCount = $post->patrolPoints()->count();
            if ($existingCount > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Static post hanya boleh punya 1 patrol point (untuk komandan regu)',
                    'post_type' => 'static',
                    'current_count' => $existingCount,
                ], 422);
            }
            
            // Static post sequence harus 1
            if ($validated['sequence_order'] !== 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'Static post patrol point harus memiliki sequence_order = 1',
                    'provided_sequence' => $validated['sequence_order'],
                ], 422);
            }
        }
        
        // 2. Validasi unique [post_id, sequence_order]
        $sequenceExists = $post->patrolPoints()
            ->where('sequence_order', $validated['sequence_order'])
            ->exists();
            
        if ($sequenceExists) {
            return response()->json([
                'success' => false,
                'message' => 'Sequence order sudah ada untuk post ini',
                'post_id' => $post->id,
                'post_type' => $post->type,
                'sequence_order' => $validated['sequence_order'],
                'suggestion' => 'Gunakan sequence order yang berbeda atau update patrol point yang ada',
            ], 422);
        }
        
        // 3. Set default radius jika tidak diisi
        if (empty($validated['radius'])) {
            $validated['radius'] = 5; // Default 5 km
        }

        $point = null;
        DB::transaction(function () use ($post, $validated, &$point) {
            $point = $post->patrolPoints()->create($validated);

            // Auto-generate QR code
            $point->qrCode()->create([
                'code' => strtoupper(Str::uuid()),
                'active' => true,
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Patrol point created dengan QR code',
            'data' => [
                'id' => $point->id,
                'post' => [
                    'id' => $post->id,
                    'name' => $post->name,
                    'type' => $post->type,
                ],
                'patrol_point' => $point->load('qrCode')->toArray(),
                'info' => [
                    'type' => $post->type === 'static' 
                        ? 'Static Point (Komandan Regu only)' 
                        : "Mobile Point (Sequence {$point->sequence_order})",
                    'total_points_in_post' => $post->patrolPoints()->count(),
                ],
                'qr_code' => [
                    'id' => $point->qrCode->id,
                    'code' => $point->qrCode->code,
                    'active' => $point->qrCode->active,
                    'image_url' => url('/api/qr-codes/' . $point->qrCode->id . '/image'),
                ],
            ],
        ], 201);
    }

    /**
     * LIST PATROL POINT BY POST
     * GET /posts/{post}/patrol-points
     * Bentuk data mengikuti transformasi di PostController@index
     */
    public function indexByPost(Request $request, Post $post)
    {
        $this->authorize('view', $post);

        $points = $post->patrolPoints()
            ->with('qrCode')
            ->orderBy('sequence_order')
            ->get();

        $points->transform(function ($patrolPoint) {
            if ($patrolPoint->qrCode) {
                $qrCode = QrCode::format('svg')
                    ->size(200)
                    ->generate($patrolPoint->qrCode->code);
                $patrolPoint->qr_code_image = 'data:image/svg+xml;base64,' . base64_encode($qrCode);
            } else {
                $patrolPoint->qr_code_image = null;
            }

            return $patrolPoint;
        });

        return response()->json([
            'data' => $points,
        ]);
    }

    /**
     * SHOW PATROL POINT
     * GET /patrol-points/{patrolPoint}
     * 
     * Show detail patrol point dengan context:
     * - Post type (static/mobile)
     * - Sequence order dalam workflow
     * - Altitude untuk distance validation
     * - Current QR code status
     */
    public function show(PatrolPoint $patrolPoint)
    {
        $this->authorize('view', $patrolPoint);

        $post = $patrolPoint->post;
        
        return response()->json([
            'success' => true,
            'data' => [
                'patrol_point' => $patrolPoint->load('qrCode')->toArray(),
                'post_context' => [
                    'id' => $post->id,
                    'name' => $post->name,
                    'type' => $post->type,
                    'type_description' => $post->type === 'static' 
                        ? 'Static Point - Untuk Komandan Regu (max 1 point per post)' 
                        : 'Mobile Point - Untuk Anggota (multiple points dengan sequence)',
                ],
                'scanning_info' => [
                    'sequence_order' => $patrolPoint->sequence_order,
                    'total_points_in_post' => $post->patrolPoints()->count(),
                    'coordinates' => [
                        'latitude' => $patrolPoint->latitude,
                        'longitude' => $patrolPoint->longitude,
                        'altitude' => $patrolPoint->altitude,
                    ],
                    'validation_distance_radius' => $patrolPoint->radius . ' km',
                    'altitude_tolerance' => '±50 meters (from patrol point altitude)',
                ],
                'qr_code' => [
                    'id' => $patrolPoint->qrCode->id,
                    'code' => $patrolPoint->qrCode->code,
                    'active' => $patrolPoint->qrCode->active,
                    'scannable' => $patrolPoint->qrCode->active,
                    'image_url' => url('/api/qr-codes/' . $patrolPoint->qrCode->id . '/image'),
                ],
            ],
        ]);
    }

    /**
     * UPDATE PATROL POINT
     * PATCH /patrol-points/{patrolPoint}
     * 
     * Logic:
     * - Tidak boleh mengubah sequence_order yang sudah ada
     * - Update altitude hanya jika perlu untuk recalibration
     * - Bisa update radius untuk adjustment validated distance
     */
    public function update(Request $request, PatrolPoint $patrolPoint)
    {
        $this->authorize(
            'manage',
            [PatrolPoint::class, $patrolPoint->post->project]
        );

        try {
            $validated = $request->validate([
                'name' => 'sometimes|string|max:100',
                'latitude' => 'sometimes|numeric|between:-90,90',
                'longitude' => 'sometimes|numeric|between:-180,180',
                'altitude' => 'sometimes|nullable|numeric',
                'radius' => 'sometimes|integer|min:1',
                'regenerate_qr' => 'sometimes|boolean',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success'     => false,
                'message'     => 'Validation failed',
                'status_code' => 422,
                'errors'      => $e->errors(),
            ], 422);
        }

        // Jangan boleh update sequence_order
        if ($request->has('sequence_order')) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak boleh mengubah sequence_order. Hapus dan buat yang baru jika perlu',
                'current_sequence' => $patrolPoint->sequence_order,
            ], 422);
        }

        DB::transaction(function () use ($patrolPoint, $validated) {

            $patrolPoint->update($validated);

            if (!empty($validated['regenerate_qr']) && $validated['regenerate_qr']) {

                // Nonaktifkan QR lama
                if ($patrolPoint->qrCode) {
                    $patrolPoint->qrCode->update(['active' => false]);
                }

                // Buat QR baru
                $patrolPoint->qrCode()->create([
                    'code' => strtoupper(Str::uuid()),
                    'active' => true,
                ]);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Patrol point updated successfully',
            'data' => [
                'patrol_point' => $patrolPoint->fresh()->load('qrCode')->toArray(),
                'post_info' => [
                    'id' => $patrolPoint->post->id,
                    'name' => $patrolPoint->post->name,
                    'type' => $patrolPoint->post->type,
                ],
                'qr_regenerated' => $validated['regenerate_qr'] ?? false,
            ],
        ]);
    }


    /**
     * DELETE PATROL POINT
     * DELETE /patrol-points/{patrolPoint}
     * 
     * Validasi:
     * - Cek apakah ada patrol scans yang sudah menggunakan patrol point ini
     * - Jika ada scans: warning atau prevent deletion (depending on business rule)
     * - Soft delete QR code dengan cascade ke patrol scans
     */
    public function destroy(PatrolPoint $patrolPoint)
    {
        $this->authorize(
            'manage',
            [PatrolPoint::class, $patrolPoint->post->project]
        );

        // Cek apakah ada patrol scans yang menggunakan QR code dari patrol point ini
        $scansCount = PatrolScan::where('qr_code_id', $patrolPoint->qrCode->id)->count();
        
        if ($scansCount > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak bisa menghapus patrol point, sudah ada ' . $scansCount . ' scan yang terhubung',
                'error_code' => 'PATROL_POINT_IN_USE',
                'linked_scans_count' => $scansCount,
                'recommendation' => 'Deactivate patrol point atau update scans terlebih dahulu',
            ], 422);
        }

        DB::transaction(function () use ($patrolPoint) {
            $patrolPoint->qrCode()?->delete();
            $patrolPoint->delete();
        });

        return response()->json([
            'success' => true,
            'message' => 'Patrol point deleted successfully',
            'data' => [
                'deleted_point' => [
                    'id' => $patrolPoint->id,
                    'name' => $patrolPoint->name,
                    'post_id' => $patrolPoint->post_id,
                ],
            ],
        ]);
    }
}
