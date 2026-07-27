<?php

namespace App\Services;

use App\Models\Post;
use App\Models\Attendance;
use App\Models\PatrolScan;
use App\Models\Schedule;
use Carbon\Carbon;

class ProgressService
{
    // ================= ASSIGNMENT AKTIF =================
    public function getActiveAssignment($projectId, $now = null)
    {
        $now = $now ? Carbon::parse($now) : now();

        $dates = [
            $now->toDateString(),
            $now->copy()->subDay()->toDateString(),
        ];

        $schedules = Schedule::where('project_id', $projectId)
            ->whereIn('date', $dates)
            ->with('assignment')
            ->get();

        $candidates = collect();

        foreach ($schedules as $schedule) {

            if (!$schedule->assignment) continue;

            $date = Carbon::parse($schedule->date)->format('Y-m-d');

            $start = Carbon::parse($date . ' ' . $schedule->assignment->start_time);
            $end = Carbon::parse($date . ' ' . $schedule->assignment->end_time);

            if ($end <= $start) $end->addDay();

            if ($now->greaterThanOrEqualTo($start) && $now->lessThan($end)) {
                $candidates->push([
                    'assignment' => $schedule->assignment,
                    'start' => $start
                ]);
            }
        }

        return $candidates->isNotEmpty()
            ? $candidates->sortByDesc('start')->first()['assignment']
            : null;
    }

    // ================= ATTENDANCE =================
    public function getAttendances($postIds, $assignmentId, $userId = null)
    {
        $query = Attendance::whereIn('post_id', $postIds)
            ->where('assignment_id', $assignmentId)
            ->whereNotNull('check_in_at');

        // fleksibel (tidak hilang setelah checkout)
        $query->where(function ($q) {
            $q->whereNull('check_out_at')
              ->orWhere('check_out_at', '>=', now()->subHours(12));
        });

        if ($userId) {
            $query->where('user_id', $userId);
        }

        return $query->with('user')->get();
    }

    // ================= SCAN =================
    public function getScans($attendanceIds)
    {
        if (empty($attendanceIds)) return collect();

        return PatrolScan::with([
                'qrCode.patrolPoint',
                'attendance.user',
                'attendance.post'
            ])
            ->whereIn('attendance_id', $attendanceIds)
            ->get();
    }

    // ================= PATROL POINT =================
    public function buildPatrolPoints($post, $scans)
    {
        $points = $post->patrolPoints()->orderBy('sequence_order')->get();

        return $points->map(function ($point) use ($scans) {

            $pointScans = $scans
                ->filter(fn($scan) => $scan->qrCode?->patrolPoint?->id === $point->id)
                ->sortBy('scan_time');

            return [
                'id' => $point->id,
                'name' => $point->name,
                'sequence_order' => $point->sequence_order,
                'latitude' => $point->latitude,
                'longitude' => $point->longitude,

                'is_scanned' => $pointScans->isNotEmpty(),
                'scanned_count' => $pointScans->count(),

                'last_scan_time' => $pointScans->last()?->scan_time,
                'last_scan_note' => $pointScans->last()?->note,
                'last_scan_user' => $pointScans->last()?->attendance?->user?->full_name,
            ];
        });
    }
}