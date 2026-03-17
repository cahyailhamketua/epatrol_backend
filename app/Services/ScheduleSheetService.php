<?php

namespace App\Services;

use App\Models\Schedule;
use App\Models\Attendance;
use App\Models\Absence;
use App\Models\OvertimeLog;
use App\Models\Assignment;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

class ScheduleSheetService
{
    public function generate(int $projectId, string $month)
    {
        $startDate = Carbon::parse($month . '-01')->startOfMonth();
        $endDate   = Carbon::parse($month . '-01')->endOfMonth();

        /*
        |--------------------------------------------------------------------------
        | 1️⃣ GET ALL SCHEDULES (BULK)
        |--------------------------------------------------------------------------
        */
        $schedules = Schedule::with(['user', 'assignment', 'team', 'post'])
            ->where('project_id', $projectId)
            ->whereBetween('date', [$startDate, $endDate])
            ->get();

        /*
        |--------------------------------------------------------------------------
        | 2️⃣ GET ATTENDANCES
        |--------------------------------------------------------------------------
        */
        $attendances = Attendance::where('project_id', $projectId)
            ->whereBetween('date', [$startDate, $endDate])
            ->get()
            ->keyBy(fn($a) => $a->user_id . '_' . $a->date->format('Y-m-d'));

        /*
        |--------------------------------------------------------------------------
        | 3️⃣ GET ABSENCES
        |--------------------------------------------------------------------------
        */
        $absences = Absence::where('project_id', $projectId)
            ->whereBetween('date', [$startDate, $endDate])
            ->where('status', 'APPROVED')
            ->get()
            ->keyBy(fn($a) => $a->user_id . '_' . $a->date->format('Y-m-d'));

        /*
        |--------------------------------------------------------------------------
        | 4️⃣ GET OVERTIME
        |--------------------------------------------------------------------------
        */
        $overtimes = OvertimeLog::where('project_id', $projectId)
            ->whereBetween('date', [$startDate, $endDate])
            ->where('status', 'APPROVED')
            ->get()
            ->keyBy(fn($o) => $o->user_id . '_' . $o->date->format('Y-m-d'));

        /*
        |--------------------------------------------------------------------------
        | 5️⃣ GROUP SCHEDULE PER USER
        |--------------------------------------------------------------------------
        */
        $grouped = $schedules->groupBy('user_id');

        $rows = [];

        foreach ($grouped as $userId => $userSchedules) {

            $user = $userSchedules->first()->user;

            // Summary mentah per user
            $summary = [
                'p' => 0,
                'm' => 0,
                'o' => 0,
                'HADIR' => 0,
                'HADIR TELAT' => 0,
                'SAKIT' => 0,
                'DINAS' => 0,
                'IZIN' => 0,
                'CUTI' => 0,
                'ALPA' => 0,
                'OVERTIME_MINUTES' => 0,
            ];

            $days = [];

            foreach (CarbonPeriod::create($startDate, $endDate) as $date) {

                $dateString = $date->format('Y-m-d');

                $schedule = $userSchedules->firstWhere('date', $dateString);

                if (!$schedule) {
                    continue;
                }

                $assignmentCode = $schedule->assignment->code;
                $summary[$assignmentCode]++;

                $key = $userId . '_' . $dateString;

                $attendance = $attendances[$key] ?? null;
                $absence    = $absences[$key] ?? null;
                $overtime   = $overtimes[$key] ?? null;

                // Attendance Summary
                if ($attendance) {
                    $summary[$attendance->attendance_status]++;
                }

                // Absence Summary
                if ($absence) {
                    if (! array_key_exists($absence->absence_type, $summary)) {
                        $summary[$absence->absence_type] = 0;
                    }
                    $summary[$absence->absence_type]++;
                }

                // Overtime Summary
                if ($overtime) {
                    $summary['OVERTIME_MINUTES'] += $overtime->planned_minutes;
                }

                $days[$dateString] = [
                    'schedule_id' => $schedule->id,
                    'assignment' => $assignmentCode,
                    'attendance' => $attendance ? [
                        'id' => $attendance->id,
                        'check_in_at' => $attendance->check_in_at,
                        'check_out_at' => $attendance->check_out_at,
                        'status' => $attendance->attendance_status,
                        'late_minutes' => $attendance->late_minutes,
                    ] : null,
                    'absence' => $absence ? [
                        'type' => $absence->absence_type,
                        'status' => $absence->status,
                    ] : null,
                    'overtime' => $overtime ? [
                        'minutes' => $overtime->planned_minutes,
                        'status' => $overtime->status,
                    ] : null,
                ];
            }

            $firstSchedule = $userSchedules->first();

            // Summary akhir yang dipakai frontend (HK, OT, OFF, dll)
            $finalSummary = [
                'SCHEDULE' => ($summary['P'] ?? 0) + ($summary['M'] ?? 0) + ($summary['O'] ?? 0),
                'HK' => ($summary['HADIR'] ?? 0) + ($summary['HADIR TELAT'] ?? 0),
                'OT' => $summary['OVERTIME_MINUTES'] ?? 0,
                'OFF' => $summary['O'] ?? 0,
                'SK' => $summary['SAKIT'] ?? 0,
                'SD' => $summary['DINAS'] ?? 0,
                'IZIN' => $summary['IZIN'] ?? 0,
                'CUTI' => $summary['CUTI'] ?? 0,
                'ALPA' => $summary['ALPA'] ?? 0,
            ];

            $rows[] = [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->full_name ?? $user->name,
                    'team_id' => $firstSchedule->team_id,
                    'team_name' => optional($firstSchedule->team)->name,
                    'post_id' => $firstSchedule->post_id,
                    'post_name' => optional($firstSchedule->post)->name,
                ],
                'summary' => $finalSummary,
                'raw_summary' => $summary,
                'days' => $days,
            ];
        }

        return [
            'meta' => [
                'project_id' => $projectId,
                'month' => $month,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'generated_at' => now(),
            ],
            'rows' => $rows,
        ];
    }
}