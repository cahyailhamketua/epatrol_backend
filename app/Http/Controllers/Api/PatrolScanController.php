<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\PatrolScan;
use App\Services\PatrolScanService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class PatrolScanController extends Controller
{
    protected $patrolScanService;

    public function __construct(PatrolScanService $patrolScanService)
    {
        $this->patrolScanService = $patrolScanService;
    }

    /**
     * Get scan progress for an attendance
     * GET /api/attendance/{attendance}/patrol-scan/progress
     */
    public function getProgress(Attendance $attendance)
    {
        $this->authorize('view', $attendance);

        $progress = $this->patrolScanService->getScanProgress($attendance);
        $details = $this->patrolScanService->getScanDetails($attendance);

        return response()->json([
            'success' => true,
            'data' => [
                'progress' => $progress,
                'scans' => $details['scans'],
                'patrol_points' => $attendance->getPatrolPoints()->map(function ($point) use ($attendance) {
                    return [
                        'id' => $point->id,
                        'name' => $point->name,
                        'sequence_order' => $point->sequence_order,
                        'latitude' => $point->latitude,
                        'longitude' => $point->longitude,
                        'altitude' => $point->altitude,
                        'radius' => $point->radius,
                        'is_scanned' => $attendance->patrolScans()
                            ->whereHas('qrCode', function ($query) use ($point) {
                                $query->where('patrol_point_id', $point->id);
                            })->exists(),
                    ];
                }),
            ],
        ]);
    }

    /**
     * Perform patrol scan (scan QR code)
     * POST /api/patrol-scan
     *
     * Request:
     * {
     *   "attendance_id": 1, // optional, jika tidak dikirim akan diambil dari token (attendance aktif terakhir)
     *   "qr_code": "UUID-CODE",
     *   "scan_latitude": -6.1234,
     *   "scan_longitude": 106.7890,
     *   "scan_altitude": 25.5,
     *   "note": "Optional note"
     * }
     */
    public function performScan(Request $request)
    {
        $this->authorize('create', PatrolScan::class);

        // VALIDATION:
        // Di sini kita sengaja TIDAK memaksa "photos" sebagai array,
        // supaya kompatibel dengan Postman/mobile yang mengirim banyak file
        // dengan key yang sama ("photos").
        $validated = $request->validate([
            'attendance_id' => 'sometimes|exists:attendances,id',
            'qr_code' => 'required|string',
            'scan_latitude' => 'required|numeric|between:-90,90',
            'scan_longitude' => 'required|numeric|between:-180,180',
            'scan_altitude' => 'nullable|numeric',
            'note' => 'nullable|string|max:500',
            'current_time' => 'required|date_format:Y-m-d H:i:s',
        ]);

        // Ambil attendance:
        // - Jika attendance_id dikirim: gunakan itu (harus milik user & exist).
        // - Jika tidak: cari attendance aktif berdasarkan token (check-in sudah ada, check-out belum ada, paling baru).
        if (isset($validated['attendance_id'])) {
            $attendance = Attendance::findOrFail($validated['attendance_id']);
        } else {
            $user = $request->user();
            $attendance = Attendance::where('user_id', $user->id)
                ->whereNotNull('check_in_at')
                ->whereNull('check_out_at')
                ->orderBy('date', 'desc')
                ->orderBy('check_in_at', 'desc')
                ->first();

            if (!$attendance) {
                return response()->json([
                    'success' => false,
                    'message' => 'Absensi aktif tidak ditemukan. Silakan check-in terlebih dahulu.',
                ], 404);
            }
        }

        $this->authorize('scanForAttendance', [PatrolScan::class, $attendance]);

        $project = $attendance->project;
        $projectTimezone = $project?->timezone ?? $project?->organization?->timezone ?? 'Asia/Jakarta';
        $scanTime = Carbon::createFromFormat('Y-m-d H:i:s', $validated['current_time'], $projectTimezone)
            ->setTimezone('UTC');


        // Normalisasi semua file agar selalu berupa array flat UploadedFile.
        // Ambil dari seluruh payload file, karena Postman bisa mengirim multiple file
        // sebagai satu key atau beberapa key (photos, photos[], photos[0], dst).
        $photoFiles = [];
        foreach ($request->allFiles() as $files) {
            if ($files instanceof \Illuminate\Http\UploadedFile) {
                $photoFiles[] = $files;
            } elseif (is_array($files)) {
                array_walk_recursive($files, function ($value) use (&$photoFiles) {
                    if ($value instanceof \Illuminate\Http\UploadedFile) {
                        $photoFiles[] = $value;
                    }
                });
            }
        }

        $result = $this->patrolScanService->createScan(
            $attendance,
            $validated['qr_code'],
            $validated['scan_latitude'],
            $validated['scan_longitude'],
            $validated['scan_altitude'] ?? null,
            $validated['note'] ?? null,
            $scanTime,
            $photoFiles
        );

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'errors' => $result['errors'],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => $result['message'],
            'data' => [
                'scan' => $result['scan']->load('photos', 'qrCode.patrolPoint'),
                'patrol_point' => $result['patrolPoint'],
                'progress' => $this->patrolScanService->getScanProgress($attendance),
            ],
        ], 201);
    }

    /**
     * Add photo to patrol scan
     * POST /api/patrol-scan/{scan}/photo
     */
    public function addPhoto(Request $request, PatrolScan $scan)
    {
        $this->authorize('addPhoto', $scan);

        $validated = $request->validate([
            'photo' => 'required|image|max:5120', // Max 5MB
        ]);

        $result = $this->patrolScanService->addPhoto($scan, $validated['photo']);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'errors' => $result['errors'],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => $result['message'],
            'data' => $result['photo'],
        ], 201);
    }

    /**
     * Get scan details
     * GET /api/patrol-scan/{scan}
     */
    public function show(PatrolScan $scan)
    {
        $this->authorize('view', $scan);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $scan->id,
                'attendance_id' => $scan->attendance_id,
                'qr_code_id' => $scan->qr_code_id,
                'patrol_point' => $scan->qrCode->patrolPoint,
                'scan_time' => $scan->scan_time,
                'note' => $scan->note,
                'photos' => $scan->photos->map(function ($photo) {
                    return [
                        'id' => $photo->id,
                        'photo' => $photo->photo,
                        'url' => Storage::disk('public')->url($photo->photo),
                        'created_at' => $photo->created_at,
                    ];
                }),
            ],
        ]);
    }

    /**
     * Delete photo from patrol scan
     * DELETE /api/patrol-scan/{scan}/photo/{photoId}
     */
    public function deletePhoto(PatrolScan $scan, $photoId)
    {
        $this->authorize('deletePhoto', $scan);

        $photo = $scan->photos()->findOrFail($photoId);

        // Delete file from storage
        if (Storage::disk('public')->exists($photo->photo)) {
            Storage::disk('public')->delete($photo->photo);
        }

        $photo->delete();

        return response()->json([
            'success' => true,
            'message' => 'Foto berhasil dihapus',
        ]);
    }

    /**
     * Get all scans for attendance with photos
     * GET /api/attendance/{attendance}/patrol-scans
     */
    public function getAttendanceScans(Attendance $attendance)
    {
        $this->authorize('view', $attendance);

        $scans = $attendance->patrolScans()
            ->with(['qrCode.patrolPoint', 'photos'])
            ->orderBy('scan_time')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'attendance' => $attendance,
                'scans' => $scans->map(function ($scan) {
                    return [
                        'id' => $scan->id,
                        'patrol_point' => $scan->qrCode->patrolPoint,
                        'scan_time' => $scan->scan_time,
                        'note' => $scan->note,
                        'photos' => $scan->photos->map(function ($photo) {
                            return [
                                'id' => $photo->id,
                                'url' => Storage::disk('public')->url($photo->photo),
                                'created_at' => $photo->created_at,
                            ];
                        }),
                        'photo_count' => $scan->photos->count(),
                    ];
                }),
            ],
        ]);
    }

    /**
     * Download photo
     * GET /api/patrol-scan-photo/{photoId}/download
     */
    public function downloadPhoto($photoId)
    {
        $photo = \App\Models\PatrolScanPhoto::findOrFail($photoId);
        $this->authorize('download', $photo);

        if (!Storage::disk('public')->exists($photo->photo)) {
            return response()->json([
                'success' => false,
                'message' => 'File tidak ditemukan',
            ], 404);
        }

        return Storage::disk('public')->download($photo->photo);
    }

    /**
     * Get scan statistics for verification/reporting
     * GET /api/attendance/{attendance}/patrol-scan/statistics
     */
    public function getStatistics(Attendance $attendance)
    {
        $this->authorize('view', $attendance);

        $scans = $attendance->patrolScans()
            ->with('photos')
            ->get();

        $totalPhotos = $scans->sum(function ($scan) {
            return $scan->photos->count();
        });

        $completionTime = null;
        $firstScan = $attendance->patrolScans()->orderBy('scan_time')->first();
        $lastScan = $attendance->patrolScans()->orderByDesc('scan_time')->first();

        if ($firstScan && $lastScan) {
            $completionTime = $lastScan->scan_time->diffInMinutes($firstScan->scan_time);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'total_scans' => $scans->count(),
                'total_photos' => $totalPhotos,
                'completion_time_minutes' => $completionTime,
                'progress' => $this->patrolScanService->getScanProgress($attendance),
                'all_completed' => $this->patrolScanService->isAllScansCompleted($attendance),
            ],
        ]);
    }
}
