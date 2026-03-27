<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\Attendance;
use App\Models\OvertimeLog;
use App\Models\Schedule;
use Carbon\Carbon;

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

    /**
     * Auto pilih assignment kerja (non-OFF) berdasarkan waktu device.
     *
     * Rule:
     * - Cari assignment aktif di project yang "mencakup" waktu sekarang.
     * - Support shift lintas malam: end_time <= start_time berarti melewati tengah malam.
     * - Kalau jam 08:00 dan shift M adalah 21:00-09:00, maka dianggap masih shift M (interval mulai kemarin).
     *
     * @return array{assignment: Assignment, start: Carbon, end: Carbon}|null
     */
    public function resolveWorkAssignmentByTime(int $projectId, Carbon $nowInProjectTz): ?array
    {
        $assignments = Assignment::where('project_id', $projectId)
            ->where('is_off', false)
            ->get();

        if ($assignments->isEmpty()) {
            return null;
        }

        $today = $nowInProjectTz->copy()->startOfDay();
        $yesterday = $today->copy()->subDay();

        $candidates = [];

        foreach ($assignments as $assignment) {
            // Interval yang dimulai "hari ini"
            $startToday = Carbon::createFromFormat('Y-m-d H:i:s', $today->toDateString().' '.$assignment->start_time, $nowInProjectTz->getTimezone());
            $endToday = Carbon::createFromFormat('Y-m-d H:i:s', $today->toDateString().' '.$assignment->end_time, $nowInProjectTz->getTimezone());
            if ($endToday->lessThanOrEqualTo($startToday)) {
                $endToday->addDay();
            }

            // Interval yang dimulai "kemarin" (untuk menangkap jam pagi pada shift malam)
            $startYesterday = Carbon::createFromFormat('Y-m-d H:i:s', $yesterday->toDateString().' '.$assignment->start_time, $nowInProjectTz->getTimezone());
            $endYesterday = Carbon::createFromFormat('Y-m-d H:i:s', $yesterday->toDateString().' '.$assignment->end_time, $nowInProjectTz->getTimezone());
            if ($endYesterday->lessThanOrEqualTo($startYesterday)) {
                $endYesterday->addDay();
            }

            if ($nowInProjectTz->betweenIncluded($startToday, $endToday)) {
                $candidates[] = ['assignment' => $assignment, 'start' => $startToday, 'end' => $endToday];
            } elseif ($nowInProjectTz->betweenIncluded($startYesterday, $endYesterday)) {
                $candidates[] = ['assignment' => $assignment, 'start' => $startYesterday, 'end' => $endYesterday];
            }
        }

        if (empty($candidates)) {
            return null;
        }

        // Jika ada lebih dari 1 kandidat, pilih yang start-nya paling dekat (terakhir) sebelum now.
        usort($candidates, function ($a, $b) {
            return $b['start']->timestamp <=> $a['start']->timestamp;
        });

        return $candidates[0];
    }

    public function buildDisplayCode(string $workCode): string
    {
        // Format requirement: o/m atau o/p (lowercase)
        return 'o/'.strtolower(trim($workCode));
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

        // schedule_id di overtime_logs bersifat unique.
        // Saat check-in OFF di-edit/re-checkin, record harus di-update, bukan insert baru.
        return OvertimeLog::updateOrCreate(
            ['schedule_id' => $schedule->id],
            [
                'project_id' => $schedule->project_id,
                'user_id' => $schedule->user_id,
                'attendance_id' => $attendance->id,
                'scheduled_assignment_id' => $scheduled->id,
                'work_assignment_id' => $workAssignment->id,
                'date' => $schedule->date,
                'display_code' => $this->buildDisplayCode($workAssignment->code),
                'minutes' => 0,
            ]
        );
    }

    public function finalizeMinutes(OvertimeLog $log, int $minutes): OvertimeLog
    {
        $log->update(['minutes' => max(0, $minutes)]);

        return $log->fresh();
    }
}
