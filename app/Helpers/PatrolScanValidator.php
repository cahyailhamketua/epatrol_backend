<?php

namespace App\Helpers;

use App\Models\Attendance;
use App\Models\PatrolPoint;

/**
 * PatrolScanValidator - Helper untuk validasi patrol scan
 */
class PatrolScanValidator
{
    /**
     * Validasi bahwa user sudah check-in
     */
    public static function validateCheckIn(Attendance $attendance, ?string &$error = null): bool
    {
        if (!$attendance->check_in_at) {
            $error = 'Silakan check-in terlebih dahulu';
            return false;
        }
        return true;
    }

    /**
     * Validasi bahwa user belum check-out
     */
    public static function validateNotCheckedOut(Attendance $attendance, ?string &$error = null): bool
    {
        if ($attendance->check_out_at) {
            $error = 'Sudah check-out, tidak bisa melakukan scan lagi';
            return false;
        }
        return true;
    }

    /**
     * Validasi bahwa attendance adalah hari ini
     */
    public static function validateSameDay(Attendance $attendance, ?string &$error = null): bool
    {
        if ($attendance->date->toDateString() !== now()->toDateString()) {
            $error = 'Hanya bisa scan pada hari yang sama dengan jadwal';
            return false;
        }
        return true;
    }

    /**
     * Validasi post sudah dipilih (untuk member)
     */
    public static function validatePostSelected(Attendance $attendance, ?string &$error = null): bool
    {
        if (!$attendance->isCommanderAttendance() && !$attendance->post_id) {
            $error = 'Harap pilih post terlebih dahulu';
            return false;
        }
        return true;
    }

    /**
     * Haversine formula untuk menghitung jarak
     * 
     * @return float Jarak dalam meter
     */
    public static function calculateDistance(
        float $lat1,
        float $lon1,
        float $lat2,
        float $lon2
    ): float {
        $earthRadius = 6371000; // Earth radius in meters

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
     * Validasi distance dengan tolerance
     */
    public static function validateDistance(
        float $distance,
        float $allowedRadius,
        ?string &$error = null
    ): bool {
        if ($distance > $allowedRadius) {
            $error = sprintf(
                'Lokasi scan terlalu jauh. Jarak: %.2f m, Radius maksimal: %.2f m',
                $distance,
                $allowedRadius
            );
            return false;
        }
        return true;
    }

    /**
     * Validasi altitude dengan tolerance
     */
    public static function validateAltitude(
        float $expectedAltitude,
        float $actualAltitude,
        float $tolerance = 50.0,
        ?string &$error = null
    ): bool {
        $diff = abs($expectedAltitude - $actualAltitude);

        if ($diff > $tolerance) {
            $error = sprintf(
                'Ketinggian tidak sesuai. Ketinggian expected: %.2f m, actual: %.2f m (diff: %.2f m)',
                $expectedAltitude,
                $actualAltitude,
                $diff
            );
            return false;
        }

        return true;
    }

    /**
     * Cek apakah semua scans sudah selesai
     */
    public static function areAllScansCompleted(Attendance $attendance): bool
    {
        $totalPoints = $attendance->getPatrolPoints()->count();
        $scannedCount = $attendance->patrolScans()->count();
        return $totalPoints > 0 && $totalPoints === $scannedCount;
    }

    /**
     * Get next expected sequence number untuk member
     */
    public static function getNextExpectedSequence(Attendance $attendance): ?int
    {
        if ($attendance->isCommanderAttendance()) {
            return null; // Komandan hanya 1 point
        }

        $scannedQrIds = $attendance->patrolScans()
            ->pluck('qr_code_id')
            ->toArray();

        if (empty($scannedQrIds)) {
            // Belum ada scan, mulai dari sequence terkecil
            return $attendance->post?->patrolPoints()
                ->orderBy('sequence_order')
                ->first()?->sequence_order ?? 1;
        }

        // Cari max sequence yang sudah di-scan
        $maxScannedSequence = PatrolPoint::whereHas('qrCode', function ($query) use ($scannedQrIds) {
            $query->whereIn('id', $scannedQrIds);
        })->max('sequence_order');

        // Next sequence adalah max + 1
        return $maxScannedSequence + 1;
    }

    /**
     * Validasi bahwa attendance sudah waktunya scan (check-in harus dilakukan dulu)
     */
    public static function validateTimingForScan(Attendance $attendance, ?string &$error = null): bool
    {
        // Check-in harus done
        if (!$attendance->check_in_at) {
            $error = 'Belum check-in';
            return false;
        }

        // Check-out belum boleh
        if ($attendance->check_out_at) {
            $error = 'Sudah check-out';
            return false;
        }

        return true;
    }

    /**
     * Get all validation errors untuk attendance
     */
    public static function getValidationErrors(Attendance $attendance): array
    {
        $errors = [];

        // Check-in validation
        if (!self::validateCheckIn($attendance, $err)) {
            $errors[] = $err;
        }

        // Check-out validation
        if (!self::validateNotCheckedOut($attendance, $err)) {
            $errors[] = $err;
        }

        // Same day validation
        if (!self::validateSameDay($attendance, $err)) {
            $errors[] = $err;
        }

        // Post selection validation
        if (!self::validatePostSelected($attendance, $err)) {
            $errors[] = $err;
        }

        return $errors;
    }
}
