<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PatrolPoint;
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
     */
    public function store(Request $request, Post $post)
    {
        $this->authorize('manage', [PatrolPoint::class, $post->project]);

        try {
            $validated = $request->validate([
                'name' => 'required|string|max:100',
                'sequence_order' => 'required|integer|min:1',
                'latitude' => 'required|numeric',
                'longitude' => 'required|numeric',
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

        DB::transaction(function () use ($post, $validated, &$point) {

            $point = $post->patrolPoints()->create($validated);

            $point->qrCode()->create([
                'code' => strtoupper(Str::uuid()),
                'active' => true,
            ]);
        });

        return response()->json([
            'message' => 'Patrol point created with QR code',
            'data' => $point->load('qrCode'),
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
     */
    public function show(PatrolPoint $patrolPoint)
    {
        $this->authorize('view', $patrolPoint);

        return response()->json([
            'data' => $patrolPoint->load('qrCode'),
        ]);
    }

    /**
     * UPDATE PATROL POINT
     * PUT /patrol-points/{patrolPoint}
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
                'sequence_order' => 'sometimes|integer|min:1',
                'latitude' => 'sometimes|numeric',
                'longitude' => 'sometimes|numeric',
                'altitude' => 'nullable|numeric',
                'radius' => 'nullable|integer|min:1',
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
            'message' => 'Patrol point updated',
            'data' => $patrolPoint->load('qrCode'),
        ]);
    }


    /**
     * DELETE PATROL POINT
     * DELETE /patrol-points/{patrolPoint}
     */
    public function destroy(PatrolPoint $patrolPoint)
    {
        $this->authorize(
            'manage',
            [PatrolPoint::class, $patrolPoint->post->project]
        );

        DB::transaction(function () use ($patrolPoint) {
            $patrolPoint->qrCode()?->delete();
            $patrolPoint->delete();
        });

        return response()->json([
            'message' => 'Patrol point deleted',
        ]);
    }
}
