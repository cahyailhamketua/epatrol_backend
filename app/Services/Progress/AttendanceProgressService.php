<?php

namespace App\Services\Progress;

use App\Models\Post;
use App\Models\Project;
use App\Repositories\ActivityRepository;
use App\Repositories\AttendanceRepository;
use App\Repositories\PatrolScanRepository;
use App\Services\Progress\ActiveScheduleService;
use App\Services\Progress\CommanderProgressService;
use App\Services\Progress\PatrolPointProgressService;
use App\Services\Progress\TimesheetService;
use App\Transformers\ScanDetailTransformer;
use Carbon\Carbon;

class AttendanceProgressService
{
    public function __construct(
        private ActiveScheduleService $activeScheduleService,
        private AttendanceRepository $attendanceRepository,
        private PatrolScanRepository $patrolScanRepository,
        private ActivityRepository $activityRepository,
        private TimesheetService $timesheetService,
        private PatrolPointProgressService $patrolPointProgressService,
        private CommanderProgressService $commanderProgressService
    ) {
    }

    public function progressAll(int $projectId, ?int $postId, ?int $danruId, ?int $userId, ?string $currentTime, string $timezone): array
    {
        $now = $currentTime
            ? Carbon::createFromFormat('Y-m-d H:i:s', $currentTime, $timezone)
            : Carbon::now($timezone);

        $project = Project::with('organization')->findOrFail($projectId);
        $context = $this->activeScheduleService->getActiveScheduleContext($projectId, $now, $timezone);

        $scheduleIds = $context['activeSchedules']->pluck('id')->all();
        $activeAssignment = $context['activeAssignment'];
        $activeAssignmentId = $context['activeAssignmentId'];

        if ($context['activeSchedules']->isEmpty() || ! $activeAssignmentId) {
            return [
                'success' => true,
                'data' => [
                    'user' => null,
                    'post_progress' => [
                        'post_id' => $postId,
                        'post_name' => $postId ? optional(Post::find($postId))->name : null,
                        'post_type' => null,
                        'project_id' => $projectId,
                        'assignment_id' => null,
                        'total_members' => 0,
                        'checked_in_members' => 0,
                        'not_checked_in_members' => 0,
                        'total_patrol_points' => 0,
                        'scanned_patrol_points' => 0,
                        'remaining_patrol_points' => 0,
                        'progress_percentage' => 0,
                    ],
                    'patrol_points' => [],
                    'activity_list' => [],
                    'user_timesheet' => [],
                    'scan_details' => [],
                ],
            ];
        }

        if ($danruId) {
            return $this->buildDanruData($project, $scheduleIds, $danruId, $userId, $now, $timezone, $activeAssignmentId);
        }

        return $this->buildPostData($project, $scheduleIds, $postId, $userId, $now, $timezone, $activeAssignmentId);
    }

    private function buildDanruData(Project $project, array $scheduleIds, int $danruId, ?int $userId, Carbon $now, string $timezone, ?int $activeAssignmentId): array
    {
        $attendances = $this->attendanceRepository->getAttendancesByDanru($danruId, $scheduleIds, $userId);
        $posts = Post::where('project_id', $project->id)
            ->where('type', 'static')
            ->with('patrolPoints')
            ->get();

        $activeUser = $userId ? $attendances->where('user_id', $userId)->first() : $attendances->first();
        $attendanceIds = $attendances->pluck('id')->all();
        $scans = $this->patrolScanRepository->getByAttendanceIds($attendanceIds, $userId);

        $allPoints = $posts->flatMap(function ($post) {
            return $post->patrolPoints->map(function ($point) use ($post) {
                return [
                    'post_id' => $post->id,
                    'post_name' => $post->name,
                    'post_type' => $post->type,
                    'model' => $point,
                ];
            });
        });

        $scanGroups = $this->patrolScanRepository->getPointScanGroups($attendanceIds, $userId);

        $patrolPoints = $allPoints->map(function ($item) use ($scanGroups) {
            $point = $item['model'];
            $group = $scanGroups->get($point->id);

            return [
                'post_id' => $item['post_id'],
                'post_name' => $item['post_name'],
                'post_type' => $item['post_type'],
                'id' => $point->id,
                'name' => $point->name,
                'sequence_order' => $point->sequence_order,
                'latitude' => $point->latitude,
                'longitude' => $point->longitude,
                'is_scanned' => $group !== null,
                'scanned_count' => $group ? $group->scan_count : 0,
                'last_scan_time' => $group ? $group->last_scan_time : null,
                'last_scan_note' => null,
                'last_scan_user' => null,
            ];
        })->values();

        $total = $patrolPoints->count();
        $scanned = $patrolPoints->where('is_scanned', true)->count();

        // Hitung total members dari schedule user di assignment aktif
        $schedules = \App\Models\Schedule::whereIn('id', $scheduleIds)->with('user')->get();
        $totalScheduledUsers = $schedules->pluck('user_id')->unique()->count();
        $checkedInCount = $attendances->count();
        $notCheckedInCount = max(0, $totalScheduledUsers - $checkedInCount);

        $activityList = $this->activityRepository->getActivitiesForCommander($project->id, $activeAssignmentId)
            ->map(function ($activity) {
                return [
                    'id' => $activity->id,
                    'name' => $activity->name,
                    'location' => $activity->location,
                    'assignment_times' => $activity->assignmentTimes->map(fn($t) => [
                        'id' => $t->id,
                        'assignment_id' => $t->assignment_id,
                        'assignment_name' => $t->assignment?->name,
                        'start_time' => $t->start_time,
                        'end_time' => $t->end_time,
                    ]),
                ];
            })->filter(fn($activity) => count($activity['assignment_times']) > 0)->values();

        $userIds = $userId ? [$userId] : [$danruId];
        $userTimesheets = $this->timesheetService->buildTimesheet($userIds, $timezone, $now);

        $scanDetails = $scans->map(fn($scan) => ScanDetailTransformer::transform($scan))->values();

        return [
            'success' => true,
            'data' => [
                'user' => $activeUser ? [
                    'id' => $activeUser->user_id,
                    'name' => $activeUser->user?->full_name,
                    'check_in_at' => $activeUser->check_in_at,
                    'check_out_at' => $activeUser->check_out_at,
                    'computed_status' => $activeUser->computed_status,
                ] : null,
                'post_progress' => [
                    'total_posts' => $posts->count(),
                    'total_members' => $totalScheduledUsers,
                    'checked_in_members' => $checkedInCount,
                    'not_checked_in_members' => $notCheckedInCount,
                    'total_patrol_points' => $total,
                    'scanned_patrol_points' => $scanned,
                    'remaining_patrol_points' => $total - $scanned,
                    'progress_percentage' => $total ? round($scanned / $total * 100, 2) : 0,
                ],
                'patrol_points' => $patrolPoints,
                'activity_list' => $activityList,
                'user_timesheet' => $userTimesheets,
                'scan_details' => $scanDetails,
            ],
        ];
    }

    private function buildPostData(Project $project, array $scheduleIds, ?int $postId, ?int $userId, Carbon $now, string $timezone, ?int $activeAssignmentId): array
    {
        $post = Post::with('patrolPoints')->findOrFail($postId);
        $attendances = $this->attendanceRepository->getAttendancesByPost($post->id, $scheduleIds, $userId);

        $activeUser = $userId
            ? $attendances->where('user_id', $userId)->whereNotNull('check_in_at')->first()
            : $attendances->whereNotNull('check_in_at')->first();

        $attendanceIds = $attendances->pluck('id')->all();
        $scans = $this->patrolScanRepository->getByAttendanceIds($attendanceIds, $userId);
        $patrolPoints = $this->patrolPointProgressService->buildPointsForPost($post, $attendanceIds, $userId);

        $total = count($patrolPoints);
        $scanned = collect($patrolPoints)->where('is_scanned', true)->count();

        // Hitung total members dari schedule user di assignment aktif
        $schedules = \App\Models\Schedule::whereIn('id', $scheduleIds)->with('user')->get();
        $totalScheduledUsers = $schedules->pluck('user_id')->unique()->count();
        $checkedInCount = $attendances->whereNotNull('check_in_at')->count();
        $notCheckedInCount = max(0, $totalScheduledUsers - $checkedInCount);

        $activityList = $this->activityRepository->getActivitiesForPost($post->id, $activeAssignmentId)
            ->map(function ($activity) {
                return [
                    'id' => $activity->id,
                    'name' => $activity->name,
                    'location' => $activity->location,
                    'assignment_times' => $activity->assignmentTimes->map(fn($t) => [
                        'id' => $t->id,
                        'assignment_id' => $t->assignment_id,
                        'assignment_name' => $t->assignment?->name,
                        'start_time' => $t->start_time,
                        'end_time' => $t->end_time,
                    ]),
                ];
            })->filter(fn($activity) => count($activity['assignment_times']) > 0)->values();

        $userIds = $userId ? [$userId] : $attendances->pluck('user_id')->unique()->values()->all();
        $userTimesheets = $this->timesheetService->buildTimesheet($userIds, $timezone, $now);

        $scanDetails = $scans->map(fn($scan) => ScanDetailTransformer::transform($scan))->values();

        return [
            'success' => true,
            'data' => [
                'user' => $activeUser ? [
                    'id' => $activeUser->user_id,
                    'name' => $activeUser->user?->full_name,
                    'check_in_at' => $activeUser->check_in_at,
                    'check_out_at' => $activeUser->check_out_at,
                    'computed_status' => $activeUser->computed_status,
                ] : null,
                'post_progress' => [
                    'post_id' => $post->id,
                    'post_name' => $post->name,
                    'post_type' => $post->type,
                    'project_id' => $post->project_id,
                    'assignment_id' => $activeAssignmentId,
                    'total_members' => $totalScheduledUsers,
                    'checked_in_members' => $checkedInCount,
                    'not_checked_in_members' => $notCheckedInCount,
                    'total_patrol_points' => $total,
                    'scanned_patrol_points' => $scanned,
                    'remaining_patrol_points' => $total - $scanned,
                    'progress_percentage' => $total ? round($scanned / $total * 100, 2) : 0,
                ],
                'patrol_points' => $patrolPoints,
                'activity_list' => $activityList,
                'user_timesheet' => $userTimesheets,
                'scan_details' => $scanDetails,
            ],
        ];
    }
}
