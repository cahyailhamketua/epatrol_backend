<?php

namespace App\Services\Progress;

use App\Models\Project;
use App\Models\Post;
use App\Models\Attendance;
use App\Models\Schedule;
use App\Models\User;
use App\Repositories\AttendanceRepository;
use App\Repositories\ScheduleRepository;
use App\Services\PatrolScanService;
use Carbon\Carbon;

class TeamProgressService
{
    public function __construct(
        private ActiveScheduleService $activeScheduleService,
        private ScheduleRepository $scheduleRepository,
        private AttendanceRepository $attendanceRepository,
        private PatrolScanService $patrolScanService
    ) {
    }

    public function getTeamProgress(User $user, Project $project, ?string $currentTime, ?int $filterAttendanceId = null): array
    {
        $projectTimezone = $project->timezone ?? $project->organization->timezone ?? 'Asia/Jakarta';
        $nowInProjectTz = $currentTime
            ? Carbon::createFromFormat('Y-m-d H:i:s', $currentTime, $projectTimezone)
            : Carbon::now($projectTimezone);

        $today = $nowInProjectTz->toDateString();
        $yesterday = $nowInProjectTz->copy()->subDay()->toDateString();

        $schedules = $this->scheduleRepository->getByProjectAndDates($project->id, [$today, $yesterday]);

        $activeSchedules = $schedules->filter(function ($schedule) use ($nowInProjectTz, $projectTimezone) {
            if (! $schedule->assignment) {
                return false;
            }

            $schDate = $schedule->date instanceof Carbon ? $schedule->date->format('Y-m-d') : Carbon::parse($schedule->date)->format('Y-m-d');
            $start = Carbon::createFromFormat('Y-m-d H:i:s', $schDate.' '.$schedule->assignment->start_time, $projectTimezone);
            $end = Carbon::createFromFormat('Y-m-d H:i:s', $schDate.' '.$schedule->assignment->end_time, $projectTimezone);

            if ($end->lessThanOrEqualTo($start)) {
                $end->addDay();
            }

            return $nowInProjectTz->greaterThanOrEqualTo($start) && $nowInProjectTz->lessThan($end);
        })->values();

        if ($activeSchedules->isEmpty()) {
            return $this->buildEmptyTeamProgressResponse($project, $today, $nowInProjectTz, $projectTimezone);
        }

        $regularActive = $activeSchedules->first(fn($s) => $s->assignment && ! $s->assignment->isOffDuty());
        $activeAssignment = $regularActive ? $regularActive->assignment : $activeSchedules->filter(fn($s) => $s->assignment)->first()?->assignment;

        $scheduleIds = $activeSchedules->pluck('id')->all();

        $attendances = $this->attendanceRepository->getLiveProgressAttendances($scheduleIds, $project->id);
        $attendances = $this->filterActiveAttendances($attendances, $activeSchedules, $nowInProjectTz, $projectTimezone);

        $visibleRoles = $this->resolveVisibleRoles($user->role);

        $activeScheduleIds = $activeSchedules->pluck('id')->all();

        $attendances = $attendances->filter(fn($a) => $a->user && in_array($a->user->role, $visibleRoles))
            ->groupBy('user_id')
            ->map(function ($userAttendances) use ($activeScheduleIds) {
                $activeAttendance = $userAttendances->first(fn ($attendance) => in_array($attendance->schedule_id, $activeScheduleIds, true));

                if ($activeAttendance) {
                    return $activeAttendance;
                }

                return $userAttendances->sortByDesc(fn ($attendance) => $attendance->check_in_at?->timestamp ?? 0)->first();
            })
            ->values();

        $adminProjectAttendances = $attendances->filter(function ($attendance) {
            return $attendance->user && $attendance->user->role === 'admin_project';
        })->values();

        $commanderAttendances = $attendances->filter(function ($attendance) use ($filterAttendanceId) {
            if (! ($attendance->user && $attendance->user->role === 'komandan_regu')) {
                return false;
            }

            if ($filterAttendanceId && $attendance->id !== $filterAttendanceId) {
                return false;
            }

            return true;
        });

        $commanderScanProgress = $this->patrolScanService->getMergedCommanderProgress($project, $commanderAttendances);

        $posts = Post::where('project_id', $project->id)
            ->whereIn('type', ['mobile', 'static'])
            ->orderBy('type')
            ->orderBy('id')
            ->get(['id', 'name', 'type']);

        $attendancesByPostId = $attendances
            ->filter(fn($a) => ! is_null($a->post_id) && $a->check_in_at)
            ->groupBy('post_id');

        $postSlots = collect();

        foreach ($posts as $post) {
            $postAttendances = $attendancesByPostId->get($post->id, collect());

            if ($postAttendances->isEmpty()) {
                $postSlots->push([
                    'users' => [],
                    'schedule' => null,
                    'post' => [
                        'post_id' => (int) $post->id,
                        'name' => $post->name,
                        'type' => $post->type,
                        'computed_status' => 'NOT_YET',
                    ],
                    'attendance' => null,
                    'checkin_status' => 'NOT_YET',
                    'scan_progress' => $this->patrolScanService->getProgressByPost($post->id),
                ]);
                continue;
            }

            $scanProgress = $this->patrolScanService->getMergedScanProgress($postAttendances, $post->id);

            $mappedUsers = $postAttendances->map(function ($att) {
                return [
                    'user_id' => (int) $att->user_id,
                    'full_name' => $att->user?->full_name,
                    'role' => $att->user?->role,
                    'attendance_id' => (int) $att->id,
                    'check_in_at' => $att->check_in_at?->toISOString(),
                    'assignment_code' => $this->resolveAssignmentCode($att),
                    'computed_status' => $att->computed_status,
                    'late_minutes' => (int) ($att->late_minutes ?? 0),
                ];
            })->values();

            $postSlots->push([
                'users' => $mappedUsers,
                'post' => [
                    'post_id' => (int) $post->id,
                    'name' => $post->name,
                    'type' => $post->type,
                ],
                'scan_progress' => $scanProgress,
            ]);
        }

        $postSlots = $postSlots->values();

        if (in_array($user->role, ['ho', 'admin_project', 'komandan_regu'])) {
            $adminWithoutPostAttendances = $adminProjectAttendances->filter(fn($att) => is_null($att->post_id));

            $adminMappedUsers = $adminWithoutPostAttendances->map(function ($att) {
                return [
                    'user_id' => (int) $att->user_id,
                    'full_name' => $att->user?->full_name,
                    'role' => $att->user?->role,
                    'attendance_id' => (int) $att->id,
                    'check_in_at' => $att->check_in_at?->toISOString(),
                    'assignment_code' => $this->resolveAssignmentCode($att),
                    'computed_status' => $att->computed_status,
                    'late_minutes' => (int) ($att->late_minutes ?? 0),
                ];
            })->values();

            if ($adminMappedUsers->isNotEmpty()) {
                $adminSlot = [
                    'users' => $adminMappedUsers,
                    'post' => [
                        'post_id' => null,
                        'name' => 'admin',
                        'type' => 'admin',
                    ],
                    'scan_progress' => [
                        'total' => 0,
                        'scanned' => 0,
                        'remaining' => 0,
                        'percentage' => 0,
                        'completed' => false,
                    ],
                ];

                $postSlots = collect([$adminSlot])->concat($postSlots)->values();
            }
        }

        if (in_array($user->role, ['ho', 'admin_project', 'komandan_regu'])) {
            $danruHasStandalonePost = $user->role === 'komandan_regu'
                ? true
                : $commanderAttendances->contains(fn ($attendance) => is_null($attendance->post_id));

            if ($danruHasStandalonePost) {
                $danruMappedUsers = $commanderAttendances->map(function ($att) {
                    return [
                        'user_id' => (int) $att->user_id,
                        'full_name' => $att->user?->full_name,
                        'role' => $att->user?->role,
                        'attendance_id' => (int) $att->id,
                        'check_in_at' => $att->check_in_at?->toISOString(),
                        'assignment_code' => $this->resolveAssignmentCode($att),
                        'computed_status' => $att->computed_status,
                        'late_minutes' => (int) ($att->late_minutes ?? 0),
                    ];
                })->values();

                $danruSlot = [
                    'users' => $danruMappedUsers,
                    'post' => [
                        'post_id' => null,
                        'name' => 'danru',
                        'type' => 'danru',
                        'danru_id' => $commanderAttendances->first()?->user_id,
                    ],
                    'scan_progress' => $commanderScanProgress,
                ];

                $postSlots = collect([$danruSlot])->concat($postSlots)->values();
            }
        }

        $totalPostCount = $posts->count();
        $postsCovered = $attendancesByPostId->count();
        $totalUsersCheckedIn = $attendances->filter(fn($a) => ! is_null($a->check_in_at))->count();
        $totalUsersScheduled = $activeSchedules->count() + $adminProjectAttendances->count();

        return [
            'message' => 'Progress assignment aktif berhasil diambil.',
            'project_id' => (int) $project->id,
            'date' => $today,
            'time' => $nowInProjectTz->format('H:i:s'),
            'timezone' => $projectTimezone,
            'assignment' => $activeAssignment ? [
                'id' => (int) $activeAssignment->id,
                'name' => $activeAssignment->name,
                'start_time' => $activeAssignment->start_time,
                'end_time' => $activeAssignment->end_time,
            ] : null,
            'danru' => [
                'attendance_id' => $commanderAttendances->first()?->id,
                'check_in_at' => $commanderAttendances->first()?->check_in_at?->toISOString(),
                'computed_status' => $commanderAttendances->first()?->computed_status,
                'scan_progress' => $commanderScanProgress,
                'attendances' => $commanderAttendances->map(fn($a) => [
                    'id' => $a->id,
                    'user_id' => $a->user_id,
                    'full_name' => $a->user?->full_name,
                    'check_in_at' => $a->check_in_at?->toISOString(),
                    'computed_status' => $a->computed_status,
                    'late_minutes' => (int) ($a->late_minutes ?? 0),
                ])->values(),
            ],
            'progress' => [
                'total_posts' => $totalPostCount,
                'posts_covered' => $postsCovered,
                'not_covered' => max(0, $totalPostCount - $postsCovered),
                'total_users_checked_in' => $totalUsersCheckedIn,
                'total_users_scheduled' => $totalUsersScheduled,
                'percentage' => $totalPostCount > 0 ? round(($postsCovered / $totalPostCount) * 100, 2) : 0,
            ],
            'members' => $postSlots,
        ];
    }

    private function buildEmptyTeamProgressResponse(Project $project, string $today, Carbon $nowInProjectTz, string $projectTimezone): array
    {
        $posts = Post::where('project_id', $project->id)
            ->whereIn('type', ['mobile', 'static'])
            ->orderBy('type')
            ->orderBy('id')
            ->get(['id', 'name', 'type']);

        $postSlots = $posts->map(function ($post) {
            return [
                'users' => [],
                'schedule' => null,
                'post' => [
                    'post_id' => (int) $post->id,
                    'name' => $post->name,
                    'type' => $post->type,
                ],
                'attendance' => null,
                'checkin_status' => 'NOT_YET',
                'scan_progress' => $this->patrolScanService->getProgressByPost($post->id),
            ];
        })->values();

        return [
            'message' => 'Tidak ada assignment aktif saat ini.',
            'project_id' => (int) $project->id,
            'date' => $today,
            'time' => $nowInProjectTz->format('H:i:s'),
            'timezone' => $projectTimezone,
            'assignment' => null,
            'danru' => [
                'attendance_id' => null,
                'check_in_at' => null,
                'computed_status' => null,
                'scan_progress' => [
                    'total' => 0,
                    'scanned' => 0,
                    'remaining' => 0,
                    'percentage' => 0,
                    'completed' => false,
                ],
                'attendances' => collect([]),
            ],
            'progress' => [
                'total_posts' => $posts->count(),
                'posts_covered' => 0,
                'not_covered' => $posts->count(),
                'total_users_checked_in' => 0,
                'total_users_scheduled' => 0,
                'percentage' => 0,
            ],
            'members' => $postSlots,
        ];
    }

    private function filterActiveAttendances(
        \Illuminate\Support\Collection $attendances,
        \Illuminate\Support\Collection $activeSchedules,
        Carbon $now,
        string $timezone
    ): \Illuminate\Support\Collection {
        $activeScheduleIds = $activeSchedules->pluck('id')->all();

        return $attendances->filter(function ($attendance) use ($activeScheduleIds, $now, $timezone) {
            if (in_array($attendance->schedule_id, $activeScheduleIds, true)) {
                return true;
            }

            if (! $attendance->check_in_at || $attendance->check_out_at) {
                return false;
            }

            $assignment = $attendance->overtimeLog?->workAssignment ?: $attendance->assignment;
            $schedule = $attendance->schedule;
            if (! $assignment || ! $schedule) {
                return false;
            }

            $scheduleDate = $schedule->date instanceof Carbon
                ? $schedule->date->format('Y-m-d')
                : Carbon::parse($schedule->date)->format('Y-m-d');

            $start = Carbon::createFromFormat('Y-m-d H:i:s', $scheduleDate.' '.$assignment->start_time, $timezone);
            $end = Carbon::createFromFormat('Y-m-d H:i:s', $scheduleDate.' '.$assignment->end_time, $timezone);

            if ($end->lessThanOrEqualTo($start)) {
                $end->addDay();
            }

            return $now->greaterThanOrEqualTo($start) && $now->lessThan($end);
        })->values();
    }

    private function resolveAssignmentCode(Attendance $attendance, ?\Illuminate\Support\Collection $activeSchedules = null): ?string
    {
        $activeScheduleIds = $activeSchedules?->pluck('id')->all() ?? [];

        if (! empty($activeScheduleIds) && $attendance->schedule_id && in_array($attendance->schedule_id, $activeScheduleIds, true)) {
            return $attendance->schedule?->assignment?->code
                ?? $attendance->assignment?->code;
        }

        if ($attendance->overtimeLog?->workAssignment && ($attendance->schedule?->assignment?->isOffDuty() || $attendance->assignment?->isOffDuty())) {
            return $attendance->overtimeLog->workAssignment->code;
        }

        return $attendance->assignment?->code
            ?? $attendance->schedule?->assignment?->code;
    }

    private function resolveVisibleRoles(string $role): array
    {
        return match ($role) {
            'ho' => ['admin_project', 'komandan_regu', 'anggota'],
            'admin_project' => ['admin_project', 'komandan_regu', 'anggota'],
            'komandan_regu' => ['admin_project', 'anggota', 'komandan_regu'],
            default => [],
        };
    }
}
