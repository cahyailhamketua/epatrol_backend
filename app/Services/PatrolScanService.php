<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\PatrolScan;
use App\Models\PatrolScanPhoto;
use App\Models\QrCode;
use App\Models\PatrolPoint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Exception;
use App\Services\ImageWebpService;

class PatrolScanService
{
    private const MIN_PHOTOS_PER_SCAN = 4;

    /**
     * Validate if user can perform patrol scan
     *
     * @param Attendance $attendance
     * @param mixed $user
     * @param \Carbon\Carbon|null $scanTime Waktu scan dari device (UTC), akan dipakai untuk validasi tanggal
     */
    public function canUserScan(Attendance $attendance, $user, ?\Carbon\Carbon $scanTime = null): array
    {
        $errors = [];

        // Check if attendance exists and belongs to user
        if ($attendance->user_id !== $user->id && $user->role !== 'admin_project' && $user->role !== 'dev') {
            $errors[] = 'Anda tidak memiliki akses ke attendance ini';
        }

        // Check if attendance date matches scan date (in project timezone, from device time)
        $projectTimezone = $attendance->project?->timezone
            ?? $attendance->project?->organization?->timezone
            ?? 'Asia/Jakarta';

        // Jika scanTime tersedia, gunakan itu sebagai sumber tanggal (seperti flow attendance)
        if ($scanTime) {
            $scanDateInProjectTz = $scanTime->copy()->setTimezone($projectTimezone)->toDateString();
        } else {
            // Fallback ke "hari ini" di project timezone jika tidak ada scanTime (backward compatibility)
            $scanDateInProjectTz = now($projectTimezone)->toDateString();
        }

        if ($attendance->date->toDateString() !== $scanDateInProjectTz) {
            $errors[] = 'Hanya bisa scan pada hari yang sama dengan jadwal (timezone: ' . $projectTimezone . ')';
        }

        // Check if already checked out
        if ($attendance->check_out_at) {
            $errors[] = 'Sudah check-out, tidak bisa melakukan scan lagi';
        }

        // Check if attendance hasn't checked in yet
        if (!$attendance->check_in_at) {
            $errors[] = 'Silakan check-in terlebih dahulu';
        }

        // For member: verify post is selected
        if (!$attendance->isCommanderAttendance() && !$attendance->post_id) {
            $errors[] = 'Harap pilih post terlebih dahulu';
        }

        // Member on static post: no patrol scan required/allowed
        if (
            !$attendance->isCommanderAttendance()
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

        if (!$qr) {
            $errors[] = 'QR Code tidak ditemukan';
            return ['valid' => false, 'errors' => $errors, 'qr' => null];
        }

        if (!$qr->active) {
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

            if (empty($staticPostIds) || !in_array($qr->patrolPoint->post_id, $staticPostIds, true)) {
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
     * Check if scan sequence is valid
     * Member must scan in order
     */
    public function validateSequenceOrder(Attendance $attendance, PatrolPoint $patrolPoint): array
    {
        $errors = [];

        if ($attendance->isCommanderAttendance()) {
            // Commander: only 1 point, but still prevent duplicate scan
            $alreadyScanned = $attendance->patrolScans()
                ->where('qr_code_id', $patrolPoint->qrCode->id)
                ->exists();

            if ($alreadyScanned) {
                $errors[] = "Anda sudah scan point '{$patrolPoint->name}' sebelumnya";
            }

            return [
                'valid' => empty($errors),
                'errors' => $errors,
                'nextExpectedSequence' => 1,
                'scannedSequences' => [],
            ];
        }

        // Get all patrol points for this post, ordered by sequence
        $allPoints = $attendance->post->patrolPoints()->get();
        $currentSequence = $patrolPoint->sequence_order;

        // Get already scanned points for this attendance
        $scannedQrIds = $attendance->patrolScans()
            ->pluck('qr_code_id')
            ->toArray();

        $scannedSequences = PatrolPoint::whereHas('qrCode', function ($query) use ($scannedQrIds) {
            $query->whereIn('id', $scannedQrIds);
        })->pluck('sequence_order')
            ->toArray();

        // Get the next expected sequence (highest scanned + 1)
        $nextExpectedSequence = empty($scannedSequences) 
            ? $allPoints->first()->sequence_order 
            : max($scannedSequences) + 1;

        // Check if current scan is the next expected
        if ($currentSequence !== $nextExpectedSequence) {
            $nextPoint = $allPoints->firstWhere('sequence_order', $nextExpectedSequence);
            $errors[] = "Anda harus scan point '{$nextPoint->name}' terlebih dahulu (urutan {$nextExpectedSequence})";
        }

        // Check if already scanned this exact qr
        if (in_array($patrolPoint->qrCode->id, $scannedQrIds)) {
            $errors[] = "Anda sudah scan point '{$patrolPoint->name}' sebelumnya";
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'nextExpectedSequence' => $nextExpectedSequence,
            'scannedSequences' => $scannedSequences,
        ];
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
        $radiusInMeters = $patrolPoint->radius * 1000; // Assuming radius is in km
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
            // Validate user can scan (gunakan scanTime dari device untuk validasi tanggal)
            $userValidation = $this->canUserScan(
                $attendance,
                auth()->user(),
                $scanTime instanceof \Carbon\Carbon ? $scanTime : null
            );
            if (!$userValidation['valid']) {
                return [
                    'success' => false,
                    'errors' => $userValidation['errors'],
                ];
            }

            // Validate QR code
            $qrValidation = $this->validateQrCode($qrCode, $attendance);
            if (!$qrValidation['valid']) {
                return [
                    'success' => false,
                    'errors' => $qrValidation['errors'],
                ];
            }

            $qr = $qrValidation['qr'];
            $patrolPoint = $qr->patrolPoint;

            // Validate sequence order
            $sequenceValidation = $this->validateSequenceOrder($attendance, $patrolPoint);
            if (!$sequenceValidation['valid']) {
                return [
                    'success' => false,
                    'errors' => $sequenceValidation['errors'],
                ];
            }

            // Validate location
            $locationValidation = $this->validateLocation(
                $attendance,
                $patrolPoint,
                $scanLatitude,
                $scanLongitude,
                $scanAltitude
            );
            if (!$locationValidation['valid']) {
                return [
                    'success' => false,
                    'errors' => $locationValidation['errors'],
                ];
            }

            // All validations passed, create the scan
            DB::beginTransaction();

            $scan = PatrolScan::create([
                'attendance_id' => $attendance->id,
                'qr_code_id' => $qr->id,
                'scan_time' => $scanTime ?? now(),
                'note' => $note,
            ]);

            // Enforce and store photos (required)
            if (count($photoFiles) < self::MIN_PHOTOS_PER_SCAN) {
                throw new Exception('Minimal ' . self::MIN_PHOTOS_PER_SCAN . ' foto wajib diupload untuk setiap scan');
            }

            foreach ($photoFiles as $photoFile) {
                $path = app(ImageWebpService::class)->storeAsWebp($photoFile, 'patrol-scan-photos', 80);
                PatrolScanPhoto::create([
                    'patrol_scan_id' => $scan->id,
                    'photo' => $path,
                ]);
            }

            DB::commit();

            return [
                'success' => true,
                'scan' => $scan,
                'patrolPoint' => $patrolPoint,
                'message' => "Scan '{$patrolPoint->name}' berhasil dicatat ({$sequenceValidation['nextExpectedSequence']}/{$attendance->getPatrolPoints()->count()})",
            ];
        } catch (Exception $e) {
            DB::rollBack();
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
            if (!$photoFile) {
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
     * Get scan progress for attendance
     */
    public function getScanProgress(Attendance $attendance): array
    {
        $allPoints = $attendance->getPatrolPoints();
        $scanCount = $attendance->patrolScans()->count();
        $totalPoints = $allPoints->count();

        return [
            'total' => $totalPoints,
            'scanned' => $scanCount,
            'remaining' => $totalPoints - $scanCount,
            'percentage' => $totalPoints > 0 ? round(($scanCount / $totalPoints) * 100, 2) : 0,
            'completed' => $scanCount === $totalPoints,
        ];
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
