<?php

namespace App\Services;

use App\Models\OvertimeLog;
use Carbon\Carbon;

class OvertimeService
{
    /**
     * Overtime untuk payroll / laporan: log lembur hari OFF (menit di overtime_logs).
     */
    public function getApprovedOvertimeInPeriod(int $userId, Carbon $periodStart, Carbon $periodEnd)
    {
        return OvertimeLog::where('user_id', $userId)
            ->where('minutes', '>', 0)
            ->inPeriod($periodStart, $periodEnd)
            ->get();
    }

    public function getOvertimeByDate(int $userId, string $date): ?OvertimeLog
    {
        return OvertimeLog::where('user_id', $userId)
            ->whereDate('date', $date)
            ->first();
    }
}
