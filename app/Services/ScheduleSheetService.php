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
        $schedules = Schedule::query()
            ->select([
                'id',
                'project_id',
                'user_id',
                'assignment_id',
                'team_id',
                'membership_status',
                'date',
            ])
            ->with([
                'user:id,full_name',
                'assignment:id,code,is_off',
                'team:id,name',
            ])
            ->where('project_id', $projectId)
            ->whereBetween('date', [$startDate, $endDate])
            ->get();

        /*
        |--------------------------------------------------------------------------
        | 2️⃣ GET ATTENDANCES
        |--------------------------------------------------------------------------
        */
        $attendances = Attendance::query()
            ->select([
                'id',
                'project_id',
                'user_id',
                'date',
                'attendance_status',
                'late_minutes',
                'check_in_at',
                'check_out_at',
            ])
            ->where('project_id', $projectId)
            ->whereBetween('date', [$startDate, $endDate])
            ->get()
            ->keyBy(fn($a) => $a->user_id . '_' . $a->date->format('Y-m-d'));

        /*
        |--------------------------------------------------------------------------
        | 3️⃣ GET ABSENCES (per schedule_id, relasi ke sel sheet)
        |--------------------------------------------------------------------------
        */
        $scheduleIds = $schedules->pluck('id');
        $absences = $scheduleIds->isEmpty()
            ? collect()
            : Absence::query()
                ->select(['id', 'schedule_id', 'absence_type'])
                ->whereIn('schedule_id', $scheduleIds)
                ->get()
                ->keyBy('schedule_id');

        /*
        |--------------------------------------------------------------------------
        | 4️⃣ GET OVERTIME (lembur hari OFF, keyed by schedule_id)
        |--------------------------------------------------------------------------
        */
        $overtimes = OvertimeLog::query()
            ->select([
                'id',
                'project_id',
                'schedule_id',
                'date',
                'display_code',
                'work_assignment_id',
            ])
            ->with('workAssignment:id,code')
            ->where('project_id', $projectId)
            ->whereBetween('date', [$startDate, $endDate])
            ->get()
            ->keyBy('schedule_id');

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
            $userSchedulesByDate = $userSchedules->keyBy(
                fn($schedule) => $schedule->date instanceof Carbon
                    ? $schedule->date->format('Y-m-d')
                    : (string) $schedule->date
            );

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
                'OVERTIME_COUNT' => 0,
            ];

            $days = [];
            $scheduleCount = 0; // hari kerja terjadwal (bukan OFF)
            $offCount = 0; // hari OFF murni (tidak masuk)

            foreach (CarbonPeriod::create($startDate, $endDate) as $date) {

                $dateString = $date->format('Y-m-d');

                $schedule = $userSchedulesByDate[$dateString] ?? null;

                if (!$schedule) {
                    continue;
                }

                $assignment = $schedule->assignment;
                $assignmentCode = strtoupper((string) $assignment->code);

                if (! array_key_exists($assignmentCode, $summary)) {
                    $summary[$assignmentCode] = 0;
                }
                $summary[$assignmentCode]++;

                $key = $userId . '_' . $dateString;

                $attendance = $attendances[$key] ?? null;
                $absence    = $absences[$schedule->id] ?? null;
                $overtime   = $overtimes[$schedule->id] ?? null;

                $isOffScheduled = $assignment->isOffDuty();

                // Count schedule kerja vs OFF: jika OFF tapi masuk (ada attendance/lembur) → bukan OFF murni
                if ($isOffScheduled) {
                    if (! $attendance) {
                        $offCount++;
                    }
                } else {
                    $scheduleCount++;
                }

                $cellAssignmentDisplay = $overtime && $overtime->display_code
                    ? $overtime->display_code
                    : $assignmentCode;

                // Attendance (DINAS tidak dimasukkan ke agregat)
                // Jika jadwal OFF tapi user hadir sebagai overtime (ada overtime log),
                // maka jangan dihitung sebagai HK/hari kerja, hanya masuk ke OT.
                // Requirement: jika overtime ada pada sel ini, jangan hitung HK/hari kerja.
                if ($attendance && $attendance->attendance_status !== 'DINAS' && ! $overtime) {
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

                // Overtime Summary: lembur dihitung per shift/assignment (bukan menit)
                if ($overtime) {
                    $summary['OVERTIME_COUNT']++;
                }

                $days[$dateString] = [
                    'schedule_id' => $schedule->id,
                    'assignment' => $cellAssignmentDisplay,
                    'scheduled_assignment_code' => $assignmentCode,
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
                        'display_code' => $overtime->display_code,
                        'work_assignment_code' => optional($overtime->workAssignment)->code,
                    ] : null,
                ];
            }

            $firstSchedule = $userSchedules->first();

            // Summary akhir yang dipakai frontend (HK, OT, OFF, dll)
            $finalSummary = [
                // jumlah schedule kerja (exclude OFF / is_off=true)
                'SCHEDULE_COUNT' => $scheduleCount,
                'HK' => ($summary['HADIR'] ?? 0) + ($summary['HADIR TELAT'] ?? 0),
                'OT' => $summary['OVERTIME_COUNT'] ?? 0,
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
                    'membership_status' => $firstSchedule->membership_status ?? Schedule::STATUS_FULL_EXISTING,
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