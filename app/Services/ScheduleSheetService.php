<?php

namespace App\Services;

use App\Models\Schedule;
use App\Models\Attendance;
use App\Models\Absence;
use App\Models\OvertimeLog;
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
        $schedules = Schedule::with(['user', 'assignment', 'team'])
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
        | 3️⃣ GET ABSENCES (per schedule_id, relasi ke sel sheet)
        |--------------------------------------------------------------------------
        */
        $absences = Absence::whereIn(
            'schedule_id',
            $schedules->pluck('id')
        )->get()->keyBy('schedule_id');

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

        // Summary total untuk seluruh project (bulan tersebut)
        $overallSummary = [
            'SCHEDULE_COUNT' => 0, // jumlah schedule kerja (exclude off)
            'HK' => 0,
            'OT' => 0,
            'OFF' => 0,
            'SAKIT' => 0,
            'IZIN' => 0,
            'CUTI' => 0,
            'ALPA' => 0,
        ];

        foreach ($grouped as $userId => $userSchedules) {

            $user = $userSchedules->first()->user;

            // Agregasi internal per user (untuk menghitung summary akhir; tidak diexpose sebagai raw_summary)
            $summary = [
                'P' => 0,
                'M' => 0,
                'O' => 0,
                'HADIR' => 0,
                'HADIR TELAT' => 0,
                'SAKIT' => 0,
                'IZIN' => 0,
                'CUTI' => 0,
                'ALPA' => 0,
                'OVERTIME_MINUTES' => 0,
            ];

            $days = [];
            $scheduleCount = 0; // exclude OFF (assignment is_off=true)
            $offCount = 0; // assignment is_off=true

            foreach (CarbonPeriod::create($startDate, $endDate) as $date) {

                $dateString = $date->format('Y-m-d');

                $schedule = $userSchedules->firstWhere('date', $dateString);

                if (!$schedule) {
                    continue;
                }

                $assignment = $schedule->assignment;
                $assignmentCode = strtoupper((string) $assignment->code);

                if (! array_key_exists($assignmentCode, $summary)) {
                    $summary[$assignmentCode] = 0;
                }
                $summary[$assignmentCode]++;

                // Count schedule kerja: selain off (assignment is_off=true)
                if ($assignment->is_off) {
                    $offCount++;
                } else {
                    $scheduleCount++;
                }

                $key = $userId . '_' . $dateString;

                $attendance = $attendances[$key] ?? null;
                $absence    = $absences[$schedule->id] ?? null;
                $overtime   = $overtimes[$key] ?? null;

                // Attendance (DINAS tidak dimasukkan ke agregat)
                if ($attendance && $attendance->attendance_status !== 'DINAS') {
                    $status = $attendance->attendance_status;
                    if (! array_key_exists($status, $summary)) {
                        $summary[$status] = 0;
                    }
                    $summary[$status]++;
                }

                // Absence Summary (C/S/I/A -> CUTI/SAKIT/IZIN/ALPA)
                if ($absence) {
                    $sumKey = Absence::TYPE_TO_SUMMARY_KEY[$absence->absence_type] ?? null;
                    if ($sumKey) {
                        if (! array_key_exists($sumKey, $summary)) {
                            $summary[$sumKey] = 0;
                        }
                        $summary[$sumKey]++;
                    }
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
                        'label' => $absence->label,
                        'summary_key' => Absence::TYPE_TO_SUMMARY_KEY[$absence->absence_type] ?? null,
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
                // jumlah schedule kerja (exclude OFF / is_off=true)
                'SCHEDULE_COUNT' => $scheduleCount,
                'HK' => ($summary['HADIR'] ?? 0) + ($summary['HADIR TELAT'] ?? 0),
                'OT' => $summary['OVERTIME_MINUTES'] ?? 0,
                // OFF dihitung berdasarkan assignment is_off=true
                'OFF' => $offCount,
                'SAKIT' => $summary['SAKIT'] ?? 0,
                'IZIN' => $summary['IZIN'] ?? 0,
                'CUTI' => $summary['CUTI'] ?? 0,
                'ALPA' => $summary['ALPA'] ?? 0,
            ];

            // Akumulasi summary keseluruhan
            $overallSummary['SCHEDULE_COUNT'] += $finalSummary['SCHEDULE_COUNT'];
            $overallSummary['HK'] += $finalSummary['HK'];
            $overallSummary['OT'] += $finalSummary['OT'];
            $overallSummary['OFF'] += $finalSummary['OFF'];
            $overallSummary['SAKIT'] += $finalSummary['SAKIT'];
            $overallSummary['IZIN'] += $finalSummary['IZIN'];
            $overallSummary['CUTI'] += $finalSummary['CUTI'];
            $overallSummary['ALPA'] += $finalSummary['ALPA'];

            $rows[] = [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->full_name ?? $user->name,
                    'team_id' => $firstSchedule->team_id,
                    'team_name' => optional($firstSchedule->team)->name,
                ],
                'summary' => $finalSummary,
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
            'overall_summary' => $overallSummary,
            'rows' => $rows,
        ];
    }
}