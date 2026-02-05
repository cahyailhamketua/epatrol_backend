<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PatrolPoint;
use Illuminate\Http\Request;
use App\Models\QrCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
