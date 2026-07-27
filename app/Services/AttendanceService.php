<?php

namespace App\Services;

use App\Models\Absence;
use App\Models\Attendance;
use App\Models\Post;
use App\Models\Schedule;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use App\Jobs\ProcessAttendanceSelfie;
use App\Jobs\RebuildProjectReportCache;

class AttendanceService
{
    private const CHECK_IN_EARLY_MINUTES = 30;
    protected array $scheduleCache = [];

    /**
     * Resolve active schedule for a user based on current time (supports midnight shifts).
     */
    public function resolveActiveSchedule(User $user, string $currentTimeStr): array
    {
        $cacheKey = "{$user->id}_{$currentTimeStr}";
        if (isset($this->scheduleCache[$cacheKey])) {
            return $this->scheduleCache[$cacheKey];
        }

        $deviceDateTime = Carbon::createFromFormat('Y-m-d H:i:s', $currentTimeStr, 'Asia/Jakarta');
        $todayStr = $deviceDateTime->toDateString();
        $yesterdayStr = $deviceDateTime->copy()->subDay()->toDateString();

        $schedules = Schedule::where('user_id', $user->id)
            ->whereIn('date', [$todayStr, $yesterdayStr])
            ->with(['assignment', 'project.organization'])
            ->get();

        if ($schedules->isEmpty()) {
            return [null, $todayStr];
        }

        $validSchedules = [];

        foreach ($schedules as $sch) {
            if (!$sch->project || !$sch->assignment) continue;

            $projectTz = $sch->project->timezone ?? $sch->project->organization->timezone ?? 'Asia/Jakarta';
            $nowTz = Carbon::createFromFormat('Y-m-d H:i:s', $currentTimeStr, $projectTz)->setTimezone('UTC');

            $schDate = $sch->date instanceof Carbon ? $sch->date->format('Y-m-d') : Carbon::parse($sch->date)->format('Y-m-d');
            $start = Carbon::createFromFormat('Y-m-d H:i:s', $schDate . ' ' . $sch->assignment->start_time, $projectTz)->setTimezone('UTC');
            $end = Carbon::createFromFormat('Y-m-d H:i:s', $schDate . ' ' . $sch->assignment->end_time, $projectTz)->setTimezone('UTC');

            if ($end->lessThanOrEqualTo($start)) {
                $end->addDay();
            }

            // Support overnight shifts for both regular and off-duty assignments.
            // Accept if current time is within 4 hours before start until 18 hours after start.
            if ($nowTz->greaterThanOrEqualTo($start->copy()->subHours(4)) && $nowTz->lessThan($start->copy()->addHours(18))) {
                $validSchedules[] = ['schedule' => $sch, 'start' => $start];
            }
        }

        if (!empty($validSchedules)) {
            usort($validSchedules, fn($a, $b) => $b['start']->timestamp - $a['start']->timestamp);
            $best = $validSchedules[0]['schedule'];
            $bestDate = $best->date instanceof Carbon ? $best->date->format('Y-m-d') : Carbon::parse($best->date)->format('Y-m-d');
            return [$best, $bestDate];
        }

        $todaySch = $schedules->first(fn($s) => ( $s->date instanceof Carbon ? $s->date->format('Y-m-d') : Carbon::parse($s->date)->format('Y-m-d') ) === $todayStr);
        return $this->scheduleCache[$cacheKey] = [$todaySch, $todayStr];
    }

    /**
     * Calculate Haversine distance in meters.
     */
    public function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        if ($lat1 === 0.0 || $lon1 === 0.0 || $lat2 === 0.0 || $lon2 === 0.0) {
            return 99999999;
        }

        $earthRadius = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);
        
        return $earthRadius * (2 * atan2(sqrt($a), sqrt(1 - $a)));
    }

    /**
     * Core check-in logic with transaction and status calculation.
     */
    public function executeCheckIn(User $user, array $data, ?string $selfiePath = null): array
    {
        // 0. Idempotency Check (with fallback)
        $idempotencyKey = request()->header('X-Idempotency-Key');
        if ($idempotencyKey) {
            try {
                $lockKey = "idempotency:checkin:{$idempotencyKey}";
                if (!Redis::set($lockKey, 'processing', 'EX', 3600, 'NX')) {
                    return ['success' => false, 'message' => 'Permintaan sedang diproses.', 'status_code' => 409];
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("Redis down during check-in idempotency check: " . $e->getMessage());
                // In fallback mode, we bypass idempotency check to keep app running
            }
        }

        [$schedule, $today] = $this->resolveActiveSchedule($user, $data['current_time']);
        
        if (!$schedule) {
            return ['success' => false, 'message' => 'Anda tidak memiliki jadwal hari ini.', 'status_code' => 403];
        }

        // GUARD: Active Attendance Check
        $activeAttendance = Attendance::where('user_id', $user->id)
            ->whereNotNull('check_in_at')
            ->whereNull('check_out_at')
            ->orderByDesc('check_in_at')
            ->first();

        if ($activeAttendance && $activeAttendance->date->format('Y-m-d') !== $today) {
            return [
                'success' => false, 
                'message' => 'Anda masih memiliki attendance aktif yang belum di-close.', 
                'info' => 'Absen '.$activeAttendance->date->translatedFormat('d F Y').' belum check-out.',
                'status_code' => 403
            ];
        }

        $existingAttendance = ($activeAttendance && $activeAttendance->date->format('Y-m-d') === $today) ? $activeAttendance : null;
        $project = $schedule->project;
        $assignment = $schedule->assignment;
        $projectTimezone = $project->timezone ?? $project->organization->timezone ?? 'Asia/Jakarta';
        $nowUtc = Carbon::createFromFormat('Y-m-d H:i:s', $data['current_time'], $projectTimezone)->setTimezone('UTC');

        // Validation: Absence
        if (Absence::where('schedule_id', $schedule->id)->exists()) {
             return ['success' => false, 'message' => 'Hari ini tercatat Absence. Tidak dapat absen masuk.', 'status_code' => 403];
        }

        // Location Validation
        $distance = $this->calculateDistance(
            (float) $project->location_latitude, (float) $project->location_longitude,
            (float) $data['latitude'], (float) $data['longitude']
        );
        $maxRadius = (float) ($project->radius ?? 100);

        if ($distance > $maxRadius) {
            return [
                'success' => false,
                'message' => 'Anda berada di luar radius absen masuk.',
                'distance' => round($distance, 2) . ' meters',
                'status_code' => 403
            ];
        }

        // Time and Status Calculation
        $calc = $this->calculateStatusAndLate($schedule, $nowUtc, $today, $projectTimezone);
        if (!$calc['valid']) return $calc;

        // DB Transaction (MINIMAL)
        $attendance = DB::transaction(function () use ($schedule, $user, $data, $today, $nowUtc, $existingAttendance, $calc) {
            $params = [
                'project_id' => $schedule->project_id,
                'user_id' => $user->id,
                'schedule_id' => $schedule->id,
                'assignment_id' => $schedule->assignment_id,
                'post_id' => $data['post_id'] ?? null,
                'date' => $today,
                'check_in_at' => $nowUtc,
                'checkin_lat' => $data['latitude'],
                'checkin_lng' => $data['longitude'],
                'attendance_status' => $calc['attendance_status'],
                'computed_status' => $calc['computed_status'],
                'late_minutes' => $calc['late_minutes'],
                'overtime_minutes' => 0,
                'overtime_status' => $calc['overtime_status'],
            ];

            if ($existingAttendance) {
                $existingAttendance->update($params);
                $attendance = $existingAttendance;
            } else {
                $attendance = Attendance::create($params);
            }

            if ($calc['is_overtime'] && $calc['work_assignment']) {
                app(OffDayOvertimeService::class)->createFromCheckIn($schedule, $attendance, $calc['work_assignment']);
            }

            return $attendance;
        });

        // 4. Background Processing
        if ($selfiePath) {
            ProcessAttendanceSelfie::dispatch($attendance, $selfiePath);
        }
        RebuildProjectReportCache::dispatch($schedule->project_id);

        return [
            'success' => true,
            'attendance' => $attendance,
            'is_edit' => (bool)$existingAttendance,
            'today' => $today,
            'now_tz' => $nowUtc->copy()->setTimezone($projectTimezone),
            'timezone' => $projectTimezone,
            'distance' => round($distance, 2) . ' meters',
        ];
    }

    /**
     * Helper to calculate status, late minutes, and handle overtime/off-day.
     */
    private function calculateStatusAndLate(Schedule $schedule, Carbon $nowUtc, string $today, string $projectTimezone): array
    {
        $assignment = $schedule->assignment;
        $isOffDuty = $assignment->isOffDuty();
        $lateMinutes = 0;
        $attendanceStatus = 'HADIR';
        $computedStatus = 'HADIR';
        $overtimeStatus = 'NONE';
        $workAssignment = null;

        if (!$isOffDuty) {
            $startTime = Carbon::createFromFormat('Y-m-d H:i:s', $today.' '.$assignment->start_time, $projectTimezone)->setTimezone('UTC');
            $endTime = Carbon::createFromFormat('Y-m-d H:i:s', $today.' '.$assignment->end_time, $projectTimezone)->setTimezone('UTC');
            if ($endTime->lessThanOrEqualTo($startTime)) $endTime->addDay();

            $gracePeriod = $assignment->grace_period ?? 15;
            $earliest = $startTime->copy()->subMinutes(self::CHECK_IN_EARLY_MINUTES);
            $graceDeadline = $startTime->copy()->addMinutes($gracePeriod);
            $checkInDeadline = $endTime->copy()->addMinutes(60);//120->subHour() ini itu untuk deadline check in minimal 1 jam sebelum pulang

            if ($nowUtc->isBefore($earliest)) {
                return ['success' => false, 'valid' => false, 'message' => 'Belum waktunya absen masuk.', 'status_code' => 403];
            }

            if (!$nowUtc->isBefore($checkInDeadline)) {
                return ['success' => false, 'valid' => false, 'message' => 'Sudah melewati batas waktu absen masuk (minimal 1 jam sebelum pulang).', 'status_code' => 403];
            }

            if ($nowUtc->isAfter($graceDeadline)) {
                $lateMinutes = $nowUtc->diffInMinutes($startTime);
                $attendanceStatus = 'HADIR TELAT';
                $computedStatus = 'HADIR TELAT';
            }
        } else {
            // Off Duty / Overtime Handling
            $offDayService = app(OffDayOvertimeService::class);
            $nowInProjectTz = $nowUtc->copy()->setTimezone($projectTimezone);
            $intervalNow = $offDayService->resolveWorkAssignmentByTime($schedule->project_id, $nowInProjectTz);
            $intervalEarly = $offDayService->resolveWorkAssignmentByTime($schedule->project_id, $nowInProjectTz->copy()->addMinutes(self::CHECK_IN_EARLY_MINUTES));

            $interval = $intervalNow ?? $intervalEarly;
            if ($intervalNow && $intervalEarly && $intervalNow['assignment']->id !== $intervalEarly['assignment']->id) {
                 $interval = $intervalEarly['start']->isAfter($intervalNow['start']) ? $intervalEarly : $intervalNow;
            }

            $workAssignment = $interval['assignment'] ?? null;
            if (!$workAssignment) {
                return ['success' => false, 'valid' => false, 'message' => 'Jadwal hari ini OFF. Tidak ada assignment kerja yang cocok.', 'status_code' => 403];
            }

            // Check if already completed OT today
            $alreadyOT = Attendance::where('user_id', $schedule->user_id)->where('date', $today)->whereNotNull('check_out_at')
                ->whereHas('overtimeLog', fn($q) => $q->where('work_assignment_id', $workAssignment->id))->exists();
            if ($alreadyOT) {
                return ['success' => false, 'valid' => false, 'message' => 'Anda sudah menyelesaikan absensi untuk shift '.$workAssignment->code.' hari ini.', 'status_code' => 409];
            }

            $workStartUtc = $interval['start']->copy()->setTimezone('UTC');
            $workEndUtc = $interval['end']->copy()->setTimezone('UTC');
            $earliestWork = $workStartUtc->copy()->subMinutes(self::CHECK_IN_EARLY_MINUTES);
            $workDeadline = $workEndUtc->copy()->subHour();

            if ($nowUtc->isBefore($earliestWork)) {
                return ['success' => false, 'valid' => false, 'message' => 'Belum waktunya absen masuk.', 'status_code' => 403];
            }

            if (!$nowUtc->isBefore($workDeadline)) {
                return ['success' => false, 'valid' => false, 'message' => 'Sudah melewati batas waktu absen masuk.', 'status_code' => 403];
            }

            $graceDeadline = $workStartUtc->copy()->addMinutes($workAssignment->grace_period ?? 15);
            if ($nowUtc->isAfter($graceDeadline)) {
                $lateMinutes = $nowUtc->diffInMinutes($workStartUtc);
                $attendanceStatus = 'HADIR TELAT';
                $computedStatus = 'HADIR TELAT LEMBUR';
            } else {
                $computedStatus = 'HADIR LEMBUR';
            }
            $overtimeStatus = 'APPROVED';
        }

        return [
            'valid' => true,
            'late_minutes' => $lateMinutes,
            'attendance_status' => $attendanceStatus,
            'computed_status' => $computedStatus,
            'overtime_status' => $overtimeStatus,
            'is_overtime' => $isOffDuty,
            'work_assignment' => $workAssignment
        ];
    }
}
