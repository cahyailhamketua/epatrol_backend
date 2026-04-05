<?php

namespace App\Services;

use App\Models\Absence;
use App\Models\Attendance;
use App\Models\Schedule;
use Exception;

class AbsenceService
{
    /**
     * Buat atau update absence untuk satu schedule (upsert).
     *
     * @throws Exception
     */
    public function upsertForSchedule(int $scheduleId, string $absenceType): Absence
    {
        $schedule = Schedule::with('assignment')->findOrFail($scheduleId);

        $existingAttendance = Attendance::where('project_id', $schedule->project_id)
            ->where('user_id', $schedule->user_id)
            ->whereDate('date', $schedule->date)
            ->exists();

        if ($existingAttendance) {
            throw new Exception('User sudah check-in pada tanggal ini. Tidak bisa menambah absence.');
        }

        return Absence::updateOrCreate(
            ['schedule_id' => $scheduleId],
            ['absence_type' => $absenceType]
        );
    }

    /**
     * Hapus absence untuk schedule (mis. admin batalkan dari sheet).
     */
    public function deleteForSchedule(int $scheduleId): bool
    {
        return Absence::where('schedule_id', $scheduleId)->delete() > 0;
    }
}
