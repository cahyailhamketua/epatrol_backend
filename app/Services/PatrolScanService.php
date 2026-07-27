<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\PatrolPoint;
use App\Models\PatrolScan;
use App\Models\PatrolScanPhoto;
use App\Models\QrCode;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use App\Jobs\ProcessPatrolScanPhotos;
use App\Jobs\RebuildProjectReportCache;

class PatrolScanService
{
    private const MIN_PHOTOS_PER_SCAN = 4;

    /**
     * Validate if user can perform patrol scan
     *
     * @param  mixed  $user
     * @param  \Carbon\Carbon|null  $scanTime  Waktu scan dari device (UTC), akan dipakai untuk validasi tanggal
     */
    public function canUserScan(Attendance $attendance, $user, ?\Carbon\Carbon $scanTime = null): array
    {
        $errors = [];

        // Check if attendance exists and belongs to user
        if ($attendance->user_id !== $user->id && $user->role !== 'admin_project' && $user->role !== 'dev') {
            $errors[] = 'Anda tidak memiliki akses ke attendance ini';
        }

        // Check if attendance date matches scan date (in project timezone, from device time)
        $projectTimezone = 'Asia/Jakarta';
        if ($attendance->project) {
            $projectTimezone = $attendance->project->timezone ?? ($attendance->project->organization?->timezone ?? 'Asia/Jakarta');
        }

        // Jika scanTime tersedia, gunakan itu sebagai sumber tanggal (seperti flow attendance)
        if ($scanTime) {
            $scanDateInProjectTz = $scanTime->copy()->setTimezone($projectTimezone)->toDateString();
            $scanTimeInProjectTz = $scanTime->copy()->setTimezone($projectTimezone);
        } else {
            // Fallback ke "hari ini" di project timezone jika tidak ada scanTime (backward compatibility)
            $scanDateInProjectTz = now($projectTimezone)->toDateString();
            $scanTimeInProjectTz = now($projectTimezone);
        }

        // Validasi tanggal scan dengan dukungan lintas malam
        if (! $attendance->date) {
            $errors[] = 'Tanggal attendance tidak valid';

            return [
                'valid' => false,
                'errors' => $errors,
            ];
        }

        $attendanceDate = $attendance->date->toDateString();
        $isValidDate = false;
        $isLintasMalam = false;

        if ($attendanceDate === $scanDateInProjectTz) {
            // Scan pada hari yang sama: selalu valid
            $isValidDate = true;
        } else {
            // Cek apakah ini shift lintas malam (midnight crossing)
            $assignment = $attendance->assignment;
            if ($assignment) {
                $startTime = \Carbon\Carbon::createFromFormat('H:i:s', $assignment->start_time);
                $endTime = \Carbon\Carbon::createFromFormat('H:i:s', $assignment->end_time);

                // Shift lintas malam jika end_time <= start_time
                if ($endTime->lessThanOrEqualTo($startTime)) {
                    $isLintasMalam = true;

                    // Untuk shift lintas malam, allow scan pada hari berikutnya
                    // sampai jam end_time dimulai
                    $nextDay = (clone $attendance->date)->addDay()->toDateString();
                    if ($nextDay === $scanDateInProjectTz) {
                        // Cek apakah scan time masih sebelum atau sama dengan end_time
                        $scanTimeOnly = $scanTimeInProjectTz->format('H:i:s');
                        if ($scanTimeOnly <= $endTime->format('H:i:s')) {
                            $isValidDate = true;
                        }
                    }
                }
            }
        }

        if (! $isValidDate) {
            if ($isLintasMalam && $attendance->assignment) {
                $errors[] = 'Scan hanya bisa dilakukan hingga jam '.\Carbon\Carbon::createFromFormat('H:i:s', $attendance->assignment->end_time)->format('H:i').' pada hari berikutnya';
            } else {
                $errors[] = 'Hanya bisa scan pada hari yang sama dengan jadwal (timezone: '.$projectTimezone.')';
            }
        }

        // Check if already checked out
        if ($attendance->check_out_at) {
            $errors[] = 'Sudah check-out, tidak bisa melakukan scan lagi';
        }

        // Check if attendance hasn't checked in yet
        if (! $attendance->check_in_at) {
            $errors[] = 'Silakan check-in terlebih dahulu';
        }

        // For member: verify post is selected
        if (! $attendance->isCommanderAttendance() && ! $attendance->post_id) {
            $errors[] = 'Harap pilih post terlebih dahulu';
        }

        // Member on static post: no patrol scan required/allowed
        if (
            ! $attendance->isCommanderAttendance()
            && $attendance->post
            && $attendance->post->type === 'static'
        ) {
            $errors[] = 'Post static tidak memerlukan patrol scan untuk anggota';
        }

        // Ensure required patrol points exist
        $pointsCount = $attendance->getPatrolPoints()->count();
        if ($attendance->isCommanderAttendance()) {
            if ($pointsCount < 1) {
                $errors[] = 'Pos static Anda belum memiliki patrol point';
            }
        } else {
            if ($attendance->post && $attendance->post->type === 'mobile' && $pointsCount < 1) {
                $errors[] = 'Post mobile belum memiliki patrol point';
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Validate QR Code
     */
    public function validateQrCode(string $qrCode, Attendance $attendance): array
    {
        $qr = QrCode::where('code', $qrCode)
            ->with('patrolPoint')
            ->first();

        $errors = [];

        if (! $qr) {
            $errors[] = 'QR Code tidak ditemukan';

            return ['valid' => false, 'errors' => $errors, 'qr' => null];
        }

        if (! $qr->active) {
            $errors[] = 'QR Code tidak aktif';

            return ['valid' => false, 'errors' => $errors, 'qr' => $qr];
        }

        // Verify the QR code belongs to patrol point of this attendance's post
        if ($attendance->isCommanderAttendance()) {
            // Commander: QR boleh dari patrol point manapun yang berada di pos STATIC dalam project
            $staticPostIds = $attendance->project?->posts()
                ->where('type', 'static')
                ->pluck('id')
                ->all() ?? [];

            if (empty($staticPostIds) || ! in_array($qr->patrolPoint->post_id, $staticPostIds, true)) {
                $errors[] = 'QR Code tidak sesuai dengan pos static di project Anda';
            }
        } else {
            // Member: QR must be from their selected post
            if ($qr->patrolPoint->post_id !== $attendance->post_id) {
                $errors[] = 'QR Code tidak sesuai dengan post yang dipilih';
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'qr' => $qr,
        ];
    }

    /**
     * Check if scan sequence is valid.
     * Non-strict: boleh tidak berurutan (warning saja), tapi tidak boleh scan ulang.
     */
    public function validateSequenceOrder(Attendance $attendance, PatrolPoint $patrolPoint): array
    {
        $errors = [];

        if ($attendance->isCommanderAttendance()) {
            $alreadyScanned = $attendance->patrolScans()
                ->where('qr_code_id', $patrolPoint->qrCode->id)
                ->exists();

            if ($alreadyScanned) {
                return [
                    'valid'               => false,
                    'errors'              => ["Anda sudah scan point '{$patrolPoint->name}' sebelumnya"],
                    'already_scanned'     => true,
                    'nearby_unscanned'    => [],
                    'warnings'            => [],
                    'nextExpectedSequence'=> 1,
                    'scannedSequences'    => [],
                ];
            }

            return [
                'valid'               => true,
                'errors'              => [],
                'already_scanned'     => false,
                'nearby_unscanned'    => [],
                'warnings'            => [],
                'nextExpectedSequence'=> 1,
                'scannedSequences'    => [],
            ];
        }

        // Load semua patrol points beserta qrCode (eager load untuk menghindari N+1)
        $allPoints = $attendance->post->patrolPoints()->with('qrCode')->orderBy('sequence_order')->get();
        $currentSequence = $patrolPoint->sequence_order;

        // QR ID yang sudah di-scan oleh attendance ini
        $scannedQrIds = $attendance->patrolScans()->pluck('qr_code_id')->toArray();

        // Cek duplikat scan
        if (in_array($patrolPoint->qrCode->id, $scannedQrIds)) {
            $nearbyUnscanned = $this->getNearbyUnscannedPoints($allPoints, $scannedQrIds, $currentSequence);

            return [
                'valid'               => false,
                'errors'              => ["Anda sudah scan point '{$patrolPoint->name}' sebelumnya"],
                'already_scanned'     => true,
                'nearby_unscanned'    => $nearbyUnscanned,
                'warnings'            => [],
                'nextExpectedSequence'=> null,
                'scannedSequences'    => [],
            ];
        }

        // Sequence sudah di-scan
        $scannedSequences = $allPoints
            ->filter(fn($p) => $p->qrCode && in_array($p->qrCode->id, $scannedQrIds))
            ->pluck('sequence_order')
            ->toArray();

        $nextExpectedSequence = empty($scannedSequences)
            ? ($allPoints->first()->sequence_order ?? 1)
            : max($scannedSequences) + 1;

        $warnings = [];
        if ($currentSequence !== $nextExpectedSequence) {
            $nextPoint = $allPoints->firstWhere('sequence_order', $nextExpectedSequence);
            if ($nextPoint) {
                $warnings[] = "Urutan scan tidak konsisten untuk patrol point '{$patrolPoint->name}'. "
                    . "Rekomendasi selanjutnya: '{$nextPoint->name}' (urutan {$nextExpectedSequence}).";
            } else {
                $warnings[] = "Urutan scan tidak konsisten untuk patrol point '{$patrolPoint->name}'.";
            }
        }

        return [
            'valid'               => true,
            'errors'              => [],
            'already_scanned'     => false,
            'nearby_unscanned'    => [],
            'warnings'            => $warnings,
            'nextExpectedSequence'=> $nextExpectedSequence,
            'scannedSequences'    => $scannedSequences,
        ];
    }

    /**
     * Dapatkan maksimal 2 patrol point yang belum di-scan, 1 sebelum dan 1 sesudah sequence saat ini.
     */
    private function getNearbyUnscannedPoints($allPoints, array $scannedQrIds, int $currentSequence): array
    {
        $unscanned = $allPoints->filter(
            fn($p) => ! ($p->qrCode && in_array($p->qrCode->id, $scannedQrIds))
        );

        $before = $unscanned->filter(fn($p) => $p->sequence_order < $currentSequence)->last();
        $after  = $unscanned->filter(fn($p) => $p->sequence_order > $currentSequence)->first();

        $result = [];
        foreach ([$before, $after] as $p) {
            if ($p) {
                $result[] = [
                    'id'             => $p->id,
                    'name'           => $p->name,
                    'sequence_order' => $p->sequence_order,
                ];
            }
        }

        // Jika kurang dari 2, isi dari unscanned terdekat lainnya
        if (count($result) < 2) {
            $resultIds = array_column($result, 'id');
            foreach ($unscanned as $p) {
                if (! in_array($p->id, $resultIds)) {
                    $result[]   = ['id' => $p->id, 'name' => $p->name, 'sequence_order' => $p->sequence_order];
                    $resultIds[] = $p->id;
                    if (count($result) >= 2) break;
                }
            }
        }

        return array_values($result);
    }

    /**
     * Validate location (distance and altitude)
     */
    public function validateLocation(
        Attendance $attendance,
        PatrolPoint $patrolPoint,
        float $scanLatitude,
        float $scanLongitude,
        ?float $scanAltitude = null
    ): array {
        $errors = [];

        // Calculate distance using Haversine formula
        $distance = $this->calculateDistance(
            $patrolPoint->latitude,
            $patrolPoint->longitude,
            $scanLatitude,
            $scanLongitude
        );

        // Check if within radius (convert meters to distance units)
        $radiusInMeters = $patrolPoint->radius; // Assuming radius is in km
        if ($distance > $radiusInMeters) {
            $errors[] = sprintf(
                'Lokasi scan terlalu jauh. Jarak: %.2f m, Radius: %.2f m',
                $distance,
                $radiusInMeters
            );
        }

        // Validate altitude jika konfigurasi altitude di patrol point terisi dan bukan 0.
        // Jika altitude patrol point = 0 atau null → tidak ada pembatasan ketinggian.
        if ($patrolPoint->altitude !== null && $patrolPoint->altitude != 0 && $scanAltitude !== null) {
            $altitudeDiff = abs($patrolPoint->altitude - $scanAltitude);
            // Allow 50 meter altitude difference
            $maxAltitudeDiff = 50;

            if ($altitudeDiff > $maxAltitudeDiff) {
                $errors[] = sprintf(
                    'Ketinggian tidak sesuai. Ketinggian expected: %.2f m, actual: %.2f m (diff: %.2f m)',
                    $patrolPoint->altitude,
                    $scanAltitude,
                    $altitudeDiff
                );
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'distance' => $distance,
            'radius' => $radiusInMeters,
        ];
    }

    /**
     * Create patrol scan with validation
     * 🚀 Optimized for concurrency: Image processing moved outside DB transaction.
     */
    public function createScan(
        Attendance $attendance,
        string $qrCode,
        float $scanLatitude,
        float $scanLongitude,
        ?float $scanAltitude = null,
        ?string $note = null,
        $scanTime = null,
        array $photoFiles = []
    ): array {
        try {
            // 0. Idempotency Check
            $idempotencyKey = request()->header('X-Idempotency-Key');
            if ($idempotencyKey) {
                $lockKey = "idempotency:scan:{$idempotencyKey}";
                if (!Redis::set($lockKey, 'processing', 'EX', 3600, 'NX')) {
                    return ['success' => false, 'errors' => ['error' => 'Permintaan sedang diproses atau sudah berhasil.'], 'status_code' => 409];
                }
            }

            // 1. Validation Logic
            $userValidation = $this->canUserScan($attendance, auth()->user(), $scanTime);
            if (!$userValidation['valid']) return ['success' => false, 'errors' => $userValidation['errors']];

            $qrValidation = $this->validateQrCode($qrCode, $attendance);
            if (!$qrValidation['valid']) return ['success' => false, 'errors' => $qrValidation['errors']];

            $qr = $qrValidation['qr'];
            $patrolPoint = $qr->patrolPoint;

            // Validasi lokasi berdasarkan radius
            $locationValidation = $this->validateLocation(
                $attendance,
                $patrolPoint,
                $scanLatitude,
                $scanLongitude,
                $scanAltitude
            );

            if (! $locationValidation['valid']) {
                return [
                    'success' => false,
                    'errors' => $locationValidation['errors'],
                    'distance' => $locationValidation['distance'],
                    'radius' => $locationValidation['radius'],
                ];
            }

            $sequenceValidation = $this->validateSequenceOrder($attendance, $patrolPoint);
            if (!$sequenceValidation['valid']) return ['success' => false, 'errors' => $sequenceValidation['errors'], 'already_scanned' => true];

            if (count($photoFiles) < self::MIN_PHOTOS_PER_SCAN) {
                return ['success' => false, 'errors' => ['photos' => 'Minimal '.self::MIN_PHOTOS_PER_SCAN.' foto wajib diupload']];
            }

            // 2. Offload Image Processing (Store Temp Files)
            $tempPaths = [];
            foreach ($photoFiles as $file) {
                $tempPaths[] = $file->store('temp/patrol-scans', 'public');
            }

            // 3. Database Operations (MINIMAL) with Duplicate Protection
            try {
                $scan = DB::transaction(function () use ($attendance, $qr, $scanTime, $note) {
                    return PatrolScan::create([
                        'attendance_id' => $attendance->id,
                        'qr_code_id' => $qr->id,
                        'scan_time' => $scanTime ?? now(),
                        'note' => $note,
                    ]);
                });
            } catch (\Illuminate\Database\QueryException $e) {
                // 1062 = Duplicate entry
                if ($e->getCode() == '23000' || str_contains($e->getMessage(), '1062')) {
                    return [
                        'success' => false, 
                        'errors' => ['error' => 'Titik ini sudah pernah di-scan.'], 
                        'status_code' => 409
                    ];
                }
                throw $e;
            }

            // 4. Redis Progress Increment & Async Work (with fallback)
            $this->incrementRedisProgress($attendance->id);
            
            ProcessPatrolScanPhotos::dispatch($scan, $tempPaths);
            RebuildProjectReportCache::dispatch($attendance->project_id);

            return [
                'success' => true,
                'scan' => $scan,
                'patrolPoint' => $patrolPoint,
                'message' => "Scan '{$patrolPoint->name}' berhasil dicatat",
                'progress' => $this->getScanProgress($attendance)
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'errors' => ['error' => $e->getMessage()],
            ];
        }
    }

    /**
     * Add photo to patrol scan
     */
    public function addPhoto(PatrolScan $scan, $photoFile): array
    {
        try {
            // Validate file
            if (! $photoFile) {
                return [
                    'success' => false,
                    'errors' => ['photo' => 'File foto tidak ditemukan'],
                ];
            }

            // Store photo
            $path = app(ImageWebpService::class)->storeAsWebp($photoFile, 'patrol-scan-photos', 80);

            $photo = PatrolScanPhoto::create([
                'patrol_scan_id' => $scan->id,
                'photo' => $path,
            ]);

            // Batalkan cache laporan project ini dengan update versi
            // nanti coba pake tags
            if ($scan->attendance) {
                Cache::forever('project_reports_'.$scan->attendance->project_id.'_v', time());
            }

            return [
                'success' => true,
                'photo' => $photo,
                'message' => 'Foto berhasil disimpan',
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'errors' => ['photo' => $e->getMessage()],
            ];
        }
    }
    /**
     * Get scan progress if no attendance
     * 
     */

     public function getProgressByPost(int $postId, ?Attendance $attendance = null): array
     {
         // 🔹 Ambil semua patrol point di post
         $totalPoints = \App\Models\PatrolPoint::where('post_id', $postId)->count();
     
         // 🔹 Default (belum scan)
         $scanCount = 0;
     
         // 🔥 Kalau ada attendance → pakai existing logic
         if ($attendance) {
             return $this->getScanProgress($attendance);
         }
     
         return [
             'total' => $totalPoints,
             'scanned' => $scanCount,
             'remaining' => $totalPoints - $scanCount,
             'percentage' => $totalPoints > 0 ? round(($scanCount / $totalPoints) * 100, 2) : 0,
             'completed' => $scanCount === $totalPoints,
         ];
     }
     
    /**
     * Get merged scan progress for multiple attendances at a specific post
     */
    public function getMergedScanProgress(\Illuminate\Support\Collection $attendances, int $postId): array
    {
        $totalPoints = \App\Models\PatrolPoint::where('post_id', $postId)->count();
        $attendanceIds = $attendances->pluck('id')->toArray();

        // Hitung distinct qr_code_id yang discan oleh gabungan attendance di post tsb
        $scanCount = \App\Models\PatrolScan::whereIn('attendance_id', $attendanceIds)
            ->distinct('qr_code_id')
            ->count('qr_code_id');

        return [
            'total' => $totalPoints,
            'scanned' => $scanCount,
            'remaining' => max(0, $totalPoints - $scanCount),
            'percentage' => $totalPoints > 0 ? round(($scanCount / $totalPoints) * 100, 2) : 0,
            'completed' => $scanCount >= $totalPoints,
        ];
    }

    /**
     * Get merged scan progress for all commanders at a specific project
     */
    public function getMergedCommanderProgress(\App\Models\Project $project, \Illuminate\Support\Collection $attendances): array
    {
        // Commander: QR boleh dari patrol point manapun yang berada di pos STATIC dalam project
        $staticPostIds = $project->posts()
            ->where('type', 'static')
            ->pluck('id')
            ->all();

        $totalPoints = \App\Models\PatrolPoint::whereIn('post_id', $staticPostIds)->count();

        if ($attendances->isEmpty()) {
            return [
                'total' => $totalPoints,
                'scanned' => 0,
                'remaining' => $totalPoints,
                'percentage' => 0,
                'completed' => false,
            ];
        }

        $attendanceIds = $attendances->pluck('id')->toArray();

        // Hitung distinct qr_code_id yang discan oleh gabungan commander di project tsb
        $scanCount = \App\Models\PatrolScan::whereIn('attendance_id', $attendanceIds)
            ->distinct('qr_code_id')
            ->count('qr_code_id');

        return [
            'total' => $totalPoints,
            'scanned' => $scanCount,
            'remaining' => max(0, $totalPoints - $scanCount),
            'percentage' => $totalPoints > 0 ? round(($scanCount / $totalPoints) * 100, 2) : 0,
            'completed' => $totalPoints > 0 && $scanCount >= $totalPoints,
        ];
    }

    /**
     * Get scan progress for attendance (Optimized with Redis + Fallback)
     */
    public function getScanProgress(Attendance $attendance): array
    {
        $redisKey = "patrol:progress:{$attendance->id}";
        $scanCount = null;

        try {
            $scanCount = Redis::get($redisKey);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("Redis unavailable: " . $e->getMessage());
        }

        if ($scanCount === null) {
            // Rebuild from DB if Redis is missing key OR Redis is down
            $scanCount = $attendance->patrolScans()->count();
            
            try {
                Redis::setex($redisKey, 86400, $scanCount); // Cache for 24h
            } catch (\Throwable $e) {}
        }

        $allPoints = $attendance->getPatrolPoints();
        $totalPoints = $allPoints->count();

        return [
            'total' => (int) $totalPoints,
            'scanned' => (int) $scanCount,
            'remaining' => max(0, $totalPoints - $scanCount),
            'percentage' => $totalPoints > 0 ? round(($scanCount / $totalPoints) * 100, 2) : 0,
            'completed' => (int)$scanCount >= (int)$totalPoints,
        ];
    }

    private function incrementRedisProgress(int $attendanceId): void
    {
        try {
            $redisKey = "patrol:progress:{$attendanceId}";
            if (Redis::exists($redisKey)) {
                Redis::incr($redisKey);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("Redis increment failed: " . $e->getMessage());
        }
    }

    /**
     * Check if all required scans are completed
     */
    public function isAllScansCompleted(Attendance $attendance): bool
    {
        $progress = $this->getScanProgress($attendance);

        return $progress['completed'];
    }

    /**
     * Calculate distance between two coordinates using Haversine formula
     * Returns distance in meters
     */
    private function calculateDistance(
        float $lat1,
        float $lon1,
        float $lat2,
        float $lon2
    ): float {
        $earthRadius = 6371000; // Earth's radius in meters

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        $distance = $earthRadius * $c;

        return $distance;
    }

    /**
     * Get detailed scan information
     */
    public function getScanDetails(Attendance $attendance): array
    {
        $scans = $attendance->patrolScans()
            ->with(['qrCode.patrolPoint', 'photos'])
            ->orderBy('scan_time')
            ->get();

        return [
            'attendance' => $attendance,
            'scans' => $scans->map(function ($scan) {
                return [
                    'id' => $scan->id,
                    'patrol_point' => $scan->qrCode->patrolPoint,
                    'scan_time' => $scan->scan_time,
                    'note' => $scan->note,
                    'photos' => $scan->photos,
                    'photo_count' => $scan->photos->count(),
                ];
            }),
        ];
    }
}
