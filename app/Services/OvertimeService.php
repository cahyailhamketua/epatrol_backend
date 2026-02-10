<?php

namespace App\Services;

use App\Models\OvertimeLog;
use App\Models\Attendance;
use App\Models\Schedule;
use Carbon\Carbon;
use Exception;

class OvertimeService
{
    /**
     * Create overtime log with validation
     *
     * @param array $data
     * @return OvertimeLog
     * @throws Exception
     */
    public function createOvertimeLog(array $data): OvertimeLog
    {
        // Calculate planned minutes
        $plannedMinutes = $this->calculateMinutes($data['planned_start_time'], $data['planned_end_time']);
        $data['planned_minutes'] = $plannedMinutes;

        // Create the overtime log
        $overtimeLog = OvertimeLog::create($data);

        return $overtimeLog;
    }

    /**
     * Approve overtime log
     *
     * @param OvertimeLog $overtimeLog
     * @param int $approvedById
     * @return OvertimeLog
     */
    public function approveOvertimeLog(OvertimeLog $overtimeLog, int $approvedById): OvertimeLog
    {
        $overtimeLog->update([
            'status' => 'APPROVED',
            'approved_by' => $approvedById,
            'approved_at' => now(),
        ]);

        return $overtimeLog->refresh();
    }

    /**
     * Reject overtime log
     *
     * @param OvertimeLog $overtimeLog
     * @return OvertimeLog
     */
    public function rejectOvertimeLog(OvertimeLog $overtimeLog): OvertimeLog
    {
        $overtimeLog->update([
            'status' => 'REJECTED',
        ]);

        return $overtimeLog->refresh();
    }

    /**
     * Complete overtime log with actual times
     *
     * @param OvertimeLog $overtimeLog
     * @param string $actualStartTime
     * @param string $actualEndTime
     * @return OvertimeLog
     */
    public function completeOvertimeLog(
        OvertimeLog $overtimeLog,
        string $actualStartTime,
        string $actualEndTime
    ): OvertimeLog {
        $actualMinutes = $this->calculateMinutes($actualStartTime, $actualEndTime);

        $overtimeLog->update([
            'actual_start_time' => $actualStartTime,
            'actual_end_time' => $actualEndTime,
            'actual_minutes' => $actualMinutes,
            'status' => 'COMPLETED',
        ]);

        return $overtimeLog->refresh();
    }

    /**
     * Get pending overtime logs for approval
     *
     * @param int $projectId
     * @param string|null $date
     * @return mixed
     */
    public function getPendingOvertimeLogs(int $projectId, ?string $date = null)
    {
        $query = OvertimeLog::where('project_id', $projectId)
            ->where('status', 'PENDING')
            ->with(['user', 'assignment']);

        if ($date) {
            $query->where('date', $date);
        }

        return $query->orderBy('date', 'desc')->get();
    }

    /**
     * Get approved overtime for a user in a period (for payroll)
     *
     * @param int $userId
     * @param Carbon $periodStart
     * @param Carbon $periodEnd
     * @return mixed
     */
    public function getApprovedOvertimeInPeriod(int $userId, Carbon $periodStart, Carbon $periodEnd)
    {
        return OvertimeLog::where('user_id', $userId)
            ->where('status', 'APPROVED')
            ->inPeriod($periodStart, $periodEnd)
            ->get();
    }

    /**
     * Check if user has approved overtime on a date
     *
     * @param int $userId
     * @param string $date
     * @return OvertimeLog|null
     */
    public function getApprovedOvertimeByDate(int $userId, string $date): ?OvertimeLog
    {
        return OvertimeLog::where('user_id', $userId)
            ->where('date', $date)
            ->where('status', 'APPROVED')
            ->first();
    }

    /**
     * Calculate minutes between two times
     */
    private function calculateMinutes(string $startTime, string $endTime): int
    {
        $start = Carbon::createFromFormat('H:i:s', $startTime);
        $end = Carbon::createFromFormat('H:i:s', $endTime);

        // If end time is before start time, assume next day
        if ($end->lessThanOrEqualTo($start)) {
            $end->addDay();
        }

        return $end->diffInMinutes($start);
    }
}
