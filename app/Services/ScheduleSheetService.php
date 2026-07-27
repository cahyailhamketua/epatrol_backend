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
        | 1️⃣ GET ALL SCHEDULES (INCLUDING team_id=NULL FOR SUMMARY CONSISTENCY)
        |--------------------------------------------------------------------------
        */
        $allSchedules = Schedule::query()
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
        | 1b️⃣ FILTER SCHEDULES FOR DISPLAY (team_id NOT NULL = user still active in team)
        |--------------------------------------------------------------------------
        */
        $schedules = $allSchedules->filter(fn($s) => $s->team_id !== null);

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
        | 3️⃣ GET ABSENCES (for ALL schedules - including team_id=NULL)
        |--------------------------------------------------------------------------
        */
        $allScheduleIds = $allSchedules->pluck('id');
        $absences = $allScheduleIds->isEmpty()
            ? collect()
            : Absence::query()
                ->select(['id', 'schedule_id', 'absence_type'])
                ->whereIn('schedule_id', $allScheduleIds)
                ->get()
                ->keyBy('schedule_id');

        /*
        |--------------------------------------------------------------------------
        | 4️⃣ GET OVERTIME (for ALL schedules - including team_id=NULL)
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
        | 5️⃣ GROUP SCHEDULE PER USER (FOR DISPLAY ONLY)
        |--------------------------------------------------------------------------
        */
        $grouped = $schedules->groupBy('user_id');
        
        /*
        |--------------------------------------------------------------------------
        | 6️⃣ BUILD SOC MAP FROM ALL SCHEDULES (for accuracy)
        |--------------------------------------------------------------------------
        */
        $socMap = $this->buildSocMap($allSchedules);

        $rows = [];

        // Summary total untuk seluruh project (bulan tersebut) - calculated from ALL schedules
        $overallSummary = [
            'SCHEDULE_COUNT' => 0, // jumlah schedule kerja (exclude off)
            'HK' => 0,
            'OT' => 0,
            'OFF' => 0,
            'SAKIT' => 0,
            'IZIN' => 0,
            'CUTI' => 0,
            'ALPA' => 0,
            'SOC_A' => 0,
        ];

        /*
        |--------------------------------------------------------------------------
        | 7️⃣ CALCULATE OVERALL SUMMARY FROM ALL SCHEDULES (for consistency)
        |--------------------------------------------------------------------------
        */
        $allSchedulesByUserAndDate = $allSchedules->groupBy('user_id')->map(function ($items) {
            return $items->keyBy(fn($schedule) => $schedule->date instanceof Carbon
                ? $schedule->date->format('Y-m-d')
                : (string) $schedule->date);
        });

        foreach ($allSchedulesByUserAndDate as $userId => $userAllSchedulesByDate) {
            foreach (CarbonPeriod::create($startDate, $endDate) as $date) {
                $dateString = $date->format('Y-m-d');
                $schedule = $userAllSchedulesByDate[$dateString] ?? null;

                if (!$schedule || !$schedule->assignment) {
                    continue;
                }

                $assignment = $schedule->assignment;
                $isOffScheduled = $assignment->isOffDuty();
                $key = $userId . '_' . $dateString;
                $attendance = $attendances[$key] ?? null;
                $absence = $absences[$schedule->id] ?? null;
                $overtime = $overtimes[$schedule->id] ?? null;

                // Count schedule kerja vs OFF
                if ($isOffScheduled) {
                    if (!$attendance) {
                        $overallSummary['OFF']++;
                    }
                } else {
                    $overallSummary['SCHEDULE_COUNT']++;
                }

                // Attendance status
                if ($attendance && $attendance->attendance_status !== 'DINAS' && !$overtime) {
                    $status = $attendance->attendance_status;
                    if ($status === 'HADIR' || $status === 'HADIR TELAT') {
                        $overallSummary['HK']++;
                    }
                }

                // Absence
                if ($absence) {
                    $sumKey = Absence::TYPE_TO_SUMMARY_KEY[$absence->absence_type] ?? null;
                    if ($sumKey) {
                        $overallSummary[$sumKey]++;
                    }
                }

                // Overtime
                if ($overtime) {
                    $overallSummary['OT']++;
                }
            }
        }

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

                $socLabel = $socMap[$schedule->id] ?? null;

                $cellAssignmentDisplay = $overtime && $overtime->display_code
                    ? $overtime->display_code
                    : ($socLabel ?? $assignmentCode);

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
                    'soc_label' => $socLabel,
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
                'SOC_A' => $summary['SOC_A'] ?? 0,
            ];

            // DO NOT accumulate overallSummary here - it's already calculated from allSchedules above
            // This ensures consistency: display only shows active team schedules,
            // but overall summary includes all schedules (even team_id=NULL)

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

    private function buildSocMap($schedules): array
    {
        $socMap = [];

        $schedulesByUserAndDate = $schedules
            ->groupBy('user_id')
            ->map(function ($items) {
                return $items->keyBy(fn($schedule) => $this->toDateString($schedule->date));
            });

        foreach ($schedules->groupBy('team_id') as $teamId => $teamSchedules) {
            if (!$teamId) {
                continue;
            }

            $memberIds = $teamSchedules
                ->pluck('user_id')
                ->unique()
                ->sort()
                ->values();

            if ($memberIds->isEmpty()) {
                continue;
            }

            $splitIndex = (int) ceil($memberIds->count() / 2);
            $socMMemberIds = $memberIds->slice(0, $splitIndex)->values()->all();
            $socPMemberIds = $memberIds->slice($splitIndex)->values()->all();

            $socMSet = array_fill_keys($socMMemberIds, true);
            $socPSet = array_fill_keys($socPMemberIds, true);

            foreach ($teamSchedules as $schedule) {
                $assignmentCode = strtoupper((string) optional($schedule->assignment)->code);
                if ($assignmentCode !== 'O') {
                    continue;
                }

                $date = Carbon::parse($this->toDateString($schedule->date));
                $prevDateString = $date->copy()->subDay()->format('Y-m-d');

                $prevSchedule = $schedulesByUserAndDate[$schedule->user_id][$prevDateString] ?? null;
                $prevCode = strtoupper((string) optional(optional($prevSchedule)->assignment)->code);
                $isSecondOffInStreak = $prevCode === 'O';

                $socLabel = null;
                if ($isSecondOffInStreak) {
                    if (isset($socPSet[$schedule->user_id])) {
                        $socLabel = 'SOC/P';
                    }
                } elseif (isset($socMSet[$schedule->user_id])) {
                    $socLabel = 'SOC/M';
                }

                // Rule: setelah shift malam, OFF berikutnya tidak boleh SOC/P.
                if ($socLabel === 'SOC/P' && $prevCode === 'M') {
                    $socLabel = 'SOC/M';
                }

                if ($socLabel) {
                    $socMap[$schedule->id] = $socLabel;
                }
            }
        }

        return $socMap;
    }

    private function toDateString($date): string
    {
        return $date instanceof Carbon
            ? $date->format('Y-m-d')
            : Carbon::parse((string) $date)->format('Y-m-d');
    }
}