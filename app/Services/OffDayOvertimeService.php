<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\Attendance;
use App\Models\OvertimeLog;
use App\Models\Schedule;

class OffDayOvertimeService
{
    /**
     * Cari assignment kerja (non-off) di project berdasarkan kode shift (mis. P, M).
     */
    public function resolveWorkAssignment(int $projectId, string $workCode): ?Assignment
    {
        $code = strtoupper(trim($workCode));

        return Assignment::where('project_id', $projectId)
            ->whereRaw('UPPER(code) = ?', [$code])
            ->where('is_off', false)
            ->first();
    }

    public function buildDisplayCode(string $workCode): string
    {
        // Format requirement: o/m atau o/p (lowercase)
        return 'o/' . strtolower(trim($workCode));
    }

    /**
     * Buat log lembur hari OFF saat check-in berhasil.
     */
    public function createFromCheckIn(
        Schedule $schedule,
        Attendance $attendance,
        Assignment $workAssignment
    ): OvertimeLog {
        $scheduled = $schedule->assignment;
        if (! $scheduled || ! $scheduled->isOffDuty()) {
            throw new \InvalidArgumentException('Schedule is not an off-day assignment.');
        }

        return OvertimeLog::create([
            'project_id' => $schedule->project_id,
            'user_id' => $schedule->user_id,
            'schedule_id' => $schedule->id,
            'attendance_id' => $attendance->id,
            'scheduled_assignment_id' => $scheduled->id,
            'work_assignment_id' => $workAssignment->id,
            'date' => $schedule->date,
            'display_code' => $this->buildDisplayCode($workAssignment->code),
            'minutes' => 0,
        ]);
    }

    public function finalizeMinutes(OvertimeLog $log, int $minutes): OvertimeLog
    {
        $log->update(['minutes' => max(0, $minutes)]);

        return $log->fresh();
    }
}
