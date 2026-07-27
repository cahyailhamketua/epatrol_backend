<?php

namespace App\Repositories;

use App\Models\PatrolScan;

class PatrolScanRepository
{
    public function getByAttendanceIds(array $attendanceIds, ?int $userId = null)
    {
        return PatrolScan::with(['qrCode.patrolPoint', 'attendance.user', 'photos'])
            ->whereIn('attendance_id', $attendanceIds)
            ->when($userId, fn($q) => $q->whereHas('attendance', fn($q2) => $q2->where('user_id', $userId)))
            ->get();
    }

    public function getDistinctQrCodeCount(array $attendanceIds, ?int $userId = null): int
    {
        return PatrolScan::whereIn('attendance_id', $attendanceIds)
            ->when($userId, fn($q) => $q->whereHas('attendance', fn($q2) => $q2->where('user_id', $userId)))
            ->distinct('qr_code_id')
            ->count('qr_code_id');
    }

    public function getPointScanGroups(array $attendanceIds, ?int $userId = null)
    {
        return PatrolScan::selectRaw('qr_codes.patrol_point_id, count(*) as scan_count, max(scan_time) as last_scan_time')
            ->join('qr_codes', 'patrol_scans.qr_code_id', '=', 'qr_codes.id')
            ->when($userId, fn($q) => $q->whereHas('attendance', fn($q2) => $q2->where('user_id', $userId)))
            ->whereIn('attendance_id', $attendanceIds)
            ->groupBy('qr_codes.patrol_point_id')
            ->get()
            ->keyBy('patrol_point_id');
    }
}
