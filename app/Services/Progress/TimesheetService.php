<?php

namespace App\Services\Progress;

use App\Models\Attendance;
use App\Models\Schedule;
use App\Models\User;
use Carbon\Carbon;

class TimesheetService
{
    public function buildTimesheet(array $userIds, string $timezone, Carbon $now)
    {
        $dates = [
            $now->copy()->subDay(),
            $now->copy(),
            $now->copy()->addDay(),
        ];

        $dateStrings = collect($dates)->map(fn($d) => $d->toDateString())->toArray();

        $users = User::whereIn('id', $userIds)->get()->keyBy('id');

        $schedules = Schedule::whereIn('user_id', $userIds)
            ->whereIn('date', $dateStrings)
            ->with(['assignment', 'absence'])
            ->get()
            ->groupBy('user_id');

        $attendances = Attendance::whereIn('user_id', $userIds)
            ->whereIn('date', $dateStrings)
            ->with(['assignment', 'overtimeLog.workAssignment'])
            ->get()
            ->groupBy('user_id');

        $result = [];

        foreach ($userIds as $userId) {
            $userSchedules = $schedules->get($userId, collect())->keyBy(fn($s) => Carbon::parse($s->date)->format('Y-m-d'));
            $userAttendances = $attendances->get($userId, collect())->keyBy(fn($a) => Carbon::parse($a->date)->format('Y-m-d'));

            $history = [];
            $summary = [
                'total_hari' => count($dates),
                'schedule_kerja' => 0,
                'kode_kehadiran' => 0,
                'telat' => 0,
                'tidak_absen' => 0,
            ];

            foreach ($dates as $currentDate) {
                $dateStr = $currentDate->toDateString();
                $schedule = $userSchedules->get($dateStr);
                $attendance = $userAttendances->get($dateStr);

                $scheduleCode = $schedule?->assignment?->code;
                $isOffSchedule = $schedule ? $schedule->assignment->isOffDuty() : true;

                $attendanceCodeStr = null;
                $checkIn = '--:--';
                $checkOut = '--:--';
                $statusStr = '';

                if ($schedule && ! $isOffSchedule) {
                    $summary['schedule_kerja']++;
                }

                if ($attendance) {
                    $summary['kode_kehadiran']++;

                    if ($isOffSchedule) {
                        $attendanceCodeStr = $attendance->overtimeLog?->workAssignment?->code ?? $attendance->assignment?->code;
                    } else {
                        $attendanceCodeStr = $attendance->assignment?->code ?? $scheduleCode;
                    }

                    $checkIn = $attendance->check_in_at ? $attendance->check_in_at->setTimezone($timezone)->format('H:i') : '--:--';
                    $checkOut = $attendance->check_out_at ? $attendance->check_out_at->setTimezone($timezone)->format('H:i') : '--:--';

                    if (in_array($attendance->computed_status, ['HADIR TELAT', 'HADIR TELAT LEMBUR'])) {
                        $statusStr = 'Telat';
                        $summary['telat']++;
                    } else {
                        $statusStr = 'Tepat Waktu';
                    }
                } elseif ($schedule && $schedule->absence) {
                    $statusStr = $schedule->absence->label;
                    $attendanceCodeStr = $schedule->absence->absence_type;

                    if (in_array($schedule->absence->absence_type, ['S', 'I', 'C', 'A'])) {
                        $summary['tidak_absen']++;
                    }
                } else {
                    if ($schedule && ! $isOffSchedule) {
                        $statusStr = 'Tidak Absen';
                        $summary['tidak_absen']++;
                    } else {
                        $statusStr = 'Libur';
                    }
                }

                $history[] = [
                    'date' => $currentDate->format('d'),
                    'day_name' => $currentDate->translatedFormat('l'),
                    'full_date' => $dateStr,
                    'schedule_code' => $scheduleCode ?? '-',
                    'attendance_code' => $attendanceCodeStr,
                    'check_in' => $checkIn,
                    'check_out' => $checkOut,
                    'status' => $statusStr,
                ];
            }

            $result[] = [
                'user_id' => $userId,
                'user_name' => $users->get($userId)?->full_name,
                'summary' => $summary,
                'history' => $history,
            ];
        }

        return $result;
    }
}
