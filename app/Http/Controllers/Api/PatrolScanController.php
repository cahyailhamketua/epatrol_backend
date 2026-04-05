<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Attendance;
use App\Models\PatrolScan;
use App\Services\PatrolScanService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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
     * Progress detail for attendance with patrol scan + ishoma activities + basic timesheet
     * GET /api/attendance/{attendance}/patrol-scan/progress-detail
     */
    public function getProgressDetail(Attendance $attendance)
    {
        $this->authorize('view', $attendance);

        $progress = $this->patrolScanService->getScanProgress($attendance);
        $scanDetails = $this->patrolScanService->getScanDetails($attendance);

        // Ishoma activities for post or project
        $ishomaQuery = Activity::where('active', true)->where('name', 'like', '%ishoma%');
        if ($attendance->post_id) {
            $ishomaQuery->where('post_id', $attendance->post_id);
        } else {
            $ishomaQuery->where('project_id', $attendance->project_id)->whereNull('post_id');
        }

        $ishomaActivities = $ishomaQuery->with('assignmentTimes.assignment')->get();

        // Basic timesheet data for the current member (today
        $timesheet = [
            'attendance_id' => $attendance->id,
            'user_id' => $attendance->user_id,
            'check_in_at' => $attendance->check_in_at?->toISOString(),
            'check_out_at' => $attendance->check_out_at?->toISOString(),
            'computed_status' => $attendance->computed_status,
            'duration_minutes' => ($attendance->check_in_at && $attendance->check_out_at) ? $attendance->check_in_at->diffInMinutes($attendance->check_out_at) : null,
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'attendance' => [
                    'id' => $attendance->id,
                    'user_id' => $attendance->user_id,
                    'project_id' => $attendance->project_id,
                    'post_id' => $attendance->post_id,
                    'check_in_at' => $attendance->check_in_at?->toISOString(),
                    'check_out_at' => $attendance->check_out_at?->toISOString(),
                    'computed_status' => $attendance->computed_status,
                ],
                'progress' => $progress,
                'scan_details' => $scanDetails,
                'patrol_points' => $attendance->getPatrolPoints()->map(function ($point) use ($attendance) {
                    return [
                        'id' => $point->id,
                        'name' => $point->name,
                        'sequence_order' => $point->sequence_order,
                        'is_scanned' => $attendance->patrolScans()
                            ->whereHas('qrCode', function ($query) use ($point) {
                                $query->where('patrol_point_id', $point->id);
                            })->exists(),
                    ];
                }),
                'ishoma_activities' => $ishomaActivities,
                'timesheet' => $timesheet,
            ],
        ]);
    }

    /**
     * List patrol points not yet scanned for given attendance
     * GET /api/attendance/{attendance}/patrol-scan/unscanned
     */
    public function getUnscannedPoints(Attendance $attendance)
    {
        $this->authorize('view', $attendance);

        $scannedQrCodeIds = $attendance->patrolScans()->pluck('qr_code_id')->toArray();

        $openPoints = $attendance->getPatrolPoints()->map(function ($point) use ($scannedQrCodeIds) {
            $isScanned = in_array($point->qrCode->id, $scannedQrCodeIds);

            return [
                'id' => $point->id,
                'name' => $point->name,
                'sequence_order' => $point->sequence_order,
                'is_scanned' => $isScanned,
            ];
        });

        $unscanned = $openPoints->filter(fn ($point) => ! $point['is_scanned'])->values();

        return response()->json([
            'success' => true,
            'data' => [
                'total' => $openPoints->count(),
                'scanned' => $openPoints->count() - $unscanned->count(),
                'unscanned' => $unscanned,
            ],
        ]);
    }

    /**
     * Validate QR only for mobile flow and response remaining points
     * POST /api/patrol-scan/check-qr
     */
    public function checkQr(Request $request)
    {
        $validated = $request->validate([
            'attendance_id' => 'sometimes|integer',
            'qr_code' => 'required|string',
        ]);

        if (isset($validated['attendance_id'])) {
            $attendance = Attendance::with(['project.organization', 'user', 'assignment'])->find($validated['attendance_id']);
            if (! $attendance) {
                return response()->json(['success' => false, 'message' => 'Attendance tidak ditemukan'], 404);
            }
        } else {
            $user = $request->user();
            $attendance = Attendance::with(['project.organization', 'user', 'assignment'])
                ->where('user_id', $user->id)
                ->whereNotNull('check_in_at')
                ->whereNull('check_out_at')
                ->orderByDesc('check_in_at')
                ->first();

            if (! $attendance) {
                return response()->json(['success' => false, 'message' => 'Absensi aktif tidak ditemukan'], 404);
            }
        }

        $this->authorize('scanForAttendance', [PatrolScan::class, $attendance]);

        $qrCheck = $this->patrolScanService->validateQrCode($validated['qr_code'], $attendance);

        $remaining = $attendance->getPatrolPoints()->filter(function ($point) use ($attendance) {
            return ! $attendance->patrolScans()->whereHas('qrCode', function ($query) use ($point) {
                $query->where('patrol_point_id', $point->id);
            })->exists();
        })->values();

        return response()->json([
            'success' => $qrCheck['valid'],
            'errors' => $qrCheck['errors'] ?? [],
            'data' => [
                'attendance_id' => $attendance->id,
                'qr_code' => $validated['qr_code'],
                'is_valid' => $qrCheck['valid'],
                'remaining_patrol_points' => $remaining,
                'scan_progress' => $this->patrolScanService->getScanProgress($attendance),
            ],
        ]);
    }

    /**
     * Perform patrol scan (scan QR code)
     * POST /api/patrol-scan
     *
     * Mendukung scanning lintas malam (midnight crossing):
     * - Untuk shift siang biasa: scan hanya pada hari yang sama dengan jadwal
     * - Untuk shift malam lintas malam (end_time < start_time, misal 20:00-04:00):
     *   scan boleh dilakukan hingga jam end_time pada hari berikutnya
     *
     * Request:
     * {
     *   "attendance_id": 1, // optional, jika tidak dikirim akan diambil dari token (attendance aktif terakhir)
     *   "qr_code": "UUID-CODE",
     *   "scan_latitude": -6.1234,
     *   "scan_longitude": 106.7890,
     *   "scan_altitude": 25.5,
     *   "note": "Optional note",
     *   "current_time": "2024-04-02 23:30:00" // waktu scan dari device
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
            'attendance_id' => 'sometimes|integer',
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
            $attendance = Attendance::with(['project.organization', 'user', 'assignment'])
                ->where('id', (int) $validated['attendance_id'])
                ->first();

            if (! $attendance) {
                return response()->json([
                    'success' => false,
                    'message' => 'Attendance tidak valid',
                ], 404);
            }

            // Attendance harus aktif untuk scan
            if (! $attendance->check_in_at || $attendance->check_out_at) {
                return response()->json([
                    'success' => false,
                    'message' => 'Attendance tidak valid',
                ], 400);
            }
        } else {
            $user = $request->user();
            $attendance = Attendance::with(['project.organization', 'user', 'assignment'])
                ->where('user_id', $user->id)
                ->whereNotNull('check_in_at')
                ->whereNull('check_out_at')
                ->orderBy('date', 'desc')
                ->orderBy('check_in_at', 'desc')
                ->first();

            if (! $attendance) {
                return response()->json([
                    'success' => false,
                    'message' => 'Absensi aktif tidak ditemukan. Silakan check-in terlebih dahulu.',
                ], 404);
            }
        }

        $this->authorize('scanForAttendance', [PatrolScan::class, $attendance]);

        // Tentukan timezone dari project atau organization atau fallback ke default
        $projectTimezone = 'Asia/Jakarta';
        if ($attendance->project) {
            $projectTimezone = $attendance->project->timezone ?? ($attendance->project->organization?->timezone ?? 'Asia/Jakarta');
        }

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

        if (! $result['success']) {
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
                'validation_warnings' => $result['warnings'] ?? [],
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

        if (! $result['success']) {
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

        if (! Storage::disk('public')->exists($photo->photo)) {
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
