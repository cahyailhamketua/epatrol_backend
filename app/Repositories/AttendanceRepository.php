<?php

namespace App\Repositories;

use App\Models\Attendance;

class AttendanceRepository
{
    public function getAttendancesByPost(int $postId, array $scheduleIds, ?int $userId = null)
    {
        return Attendance::with(['user', 'assignment', 'schedule'])
            ->whereIn('schedule_id', $scheduleIds)
            ->where('post_id', $postId)
            ->when($userId, fn($q) => $q->where('user_id', $userId))
            ->get();
    }

    public function getAttendancesByDanru(int $danruId, array $scheduleIds, ?int $userId = null)
    {
        return Attendance::with(['user', 'assignment', 'schedule'])
            ->whereIn('schedule_id', $scheduleIds)
            ->where('user_id', $danruId)
            ->whereNotNull('check_in_at')
            ->whereNull('check_out_at')
            ->when($userId, fn($q) => $q->where('user_id', $userId))
            ->get();
    }

    public function getAttendancesForProgress(array $scheduleIds, ?int $postId = null, ?int $danruId = null, ?int $userId = null)
    {
        $query = Attendance::with(['user', 'assignment', 'schedule', 'overtimeLog.workAssignment']);

        if (!empty($scheduleIds)) {
            $query->whereIn('schedule_id', $scheduleIds);
        }

        if ($postId) {
            $query->where('post_id', $postId);
        }

        if ($danruId) {
            $query->where('user_id', $danruId)
                ->whereNotNull('check_in_at')
                ->whereNull('check_out_at');
        }

        if ($userId) {
            $query->where('user_id', $userId);
        }

        return $query->get();
    }

        public function getLiveProgressAttendances(array $scheduleIds, int $projectId)
        {
            return Attendance::where(function ($query) use ($scheduleIds) {
                    $query->whereIn('schedule_id', $scheduleIds)
                        ->orWhere(function ($sub) {
                            $sub->whereNotNull('check_in_at')
                                ->whereNull('check_out_at')
                                ->whereHas('assignment', fn($q) => $q->whereRaw('1=1'));
                        });
                })
                ->whereHas('schedule', fn($q) => $q->where('project_id', $projectId))
                ->with(['user', 'post', 'assignment', 'overtimeLog', 'schedule'])
                ->get();
        }
    }
