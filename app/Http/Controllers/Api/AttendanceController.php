<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Absence;
use App\Models\Activity;
use App\Models\Assignment;
use App\Models\Attendance;
use App\Models\AttendanceProgressSnapshot;
use App\Models\OvertimeLog;
use App\Models\PatrolPoint;
use App\Models\PatrolScan;
use App\Models\Post;
use App\Models\Project;
use App\Models\Schedule;
use App\Models\User;
use App\Services\ImageWebpService;
use App\Services\OffDayOvertimeService;
use App\Services\PatrolScanService;
use App\Services\OfflineSyncService;
use App\Services\ProgressPdfExportService;
use App\Support\SignedMediaUrl;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use App\Services\AttendanceService;
use App\Services\ScheduleCacheService;
use App\Services\PayrollService;
use App\Jobs\AutoCheckoutAttendance;
use App\Events\AttendanceUpdated;
use App\Services\ProgressService;
use App\Services\Progress\AttendanceProgressService;
use App\Services\Progress\TeamProgressService;
use App\Transformers\ProgressTransformer;

class AttendanceController extends Controller
{
    protected AttendanceService $attendanceService;

    protected ScheduleCacheService $scheduleCacheService;

    protected AttendanceProgressService $attendanceProgressService;

    protected TeamProgressService $teamProgressService;

    protected PayrollService $payrollService;

    public function __construct(
        AttendanceService $attendanceService,
        ScheduleCacheService $scheduleCacheService,
        AttendanceProgressService $attendanceProgressService,
        TeamProgressService $teamProgressService,
        PayrollService $payrollService
    ) {
        $this->attendanceService = $attendanceService;
        $this->scheduleCacheService = $scheduleCacheService;
        $this->attendanceProgressService = $attendanceProgressService;
        $this->teamProgressService = $teamProgressService;
        $this->payrollService = $payrollService;
    }
    /**
     * LIST ATTENDANCE
     * GET /attendances
     */
    public function index(Request $request)
    {
        // ========= AUTHORIZATION: User harus punya permission view attendance
        $this->authorize('viewAny', Attendance::class);

        $user = Auth::user();

        // Query builder berdasarkan role
        $query = Attendance::with([
            'assignment',
            'post',
            'user',
            'schedule',
            'project.organization',
            'overtimeLog.workAssignment',
            'overtimeLog.scheduledAssignment',
        ]);

        // DEV bisa lihat semua
        if ($user->role === 'dev') {
            // No filter
        }
        // HO lihat attendance dalam organization miliknya
        elseif ($user->role === 'ho') {
            $query->whereHas('project', function ($q) use ($user) {
                $q->where('organization_id', $user->organization_id);
            });
        }
        // Admin project lihat attendance di project miliknya
        elseif ($user->role === 'admin_project') {
            $query->where('project_id', $user->project_id);
        }
        // Komandan regu lihat attendance di project
        elseif ($user->role === 'komandan_regu') {
            $query->where('project_id', $user->project_id);
        }
        // Anggota hanya lihat milik sendiri
        elseif ($user->role === 'anggota') {
            $query->where('user_id', $user->id);
        }

        // Filter by date jika ada
if ($request->filled('date')) {

    $dateTime = Carbon::parse($request->date);

    $dates = [$dateTime->toDateString()];

    if ($dateTime->hour < 7) {
        $dates[] = $dateTime->copy()->subDay()->toDateString();
    }

    $query->whereIn('date', $dates);
}

        // Filter by user_id jika ada
        if ($request->has('user_id') && $user->role === 'dev') {
            $query->where('user_id', $request->query('user_id'));
        }

        // Paginate
        $attendances = $query->paginate(15);

        return response()->json([
            'data' => collect($attendances->items())
                ->map(fn ($attendance) => $this->formatAttendanceResponse($attendance))
                ->values(),
            'pagination' => [
                'total' => $attendances->total(),
                'per_page' => $attendances->perPage(),
                'current_page' => $attendances->currentPage(),
                'last_page' => $attendances->lastPage(),
            ],
        ]);
    }





   
    /**
     * CHECK-IN
     * 🔐 Concurrency Safety: Redis Lock ensures one check-in per user.
     */
    public function checkIn(Request $request)
    {
        $this->authorize('create', Attendance::class);
        $user = Auth::user();

        // 1. Validation
        $rules = [
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'current_time' => 'required|date_format:Y-m-d H:i:s',
        ];

        $selfieRequired = in_array($user->role, ['komandan_regu', 'anggota'], true);
        $rules['selfie_photo'] = $selfieRequired ? 'required|image|max:1024' : 'sometimes|image|max:1024';

        if (! in_array($user->role, ['komandan_regu', 'admin_project'], true)) {
            $rules['post_id'] = 'sometimes|integer';
            $rules['post_type'] = 'required_without:post_id|string|in:static,mobile';
            $rules['post_name'] = 'required_without:post_id|string';
        }

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) return response()->json($validator->errors(), 422);

        // 2. Resolve Post
        $user->load(['schedules.assignment', 'schedules.project']);
        $post = null;
        if (! in_array($user->role, ['komandan_regu', 'admin_project'], true)) {
            $post = Post::where('project_id', $user->project_id)
                ->when($request->filled('post_id'), fn($q) => $q->where('id', $request->post_id))
                ->when(!$request->filled('post_id'), fn($q) => $q->where('type', $request->post_type)->where('name', $request->post_name))
                ->first();

            if (! $post) return response()->json(['message' => 'Pos tidak ditemukan.'], 404);
        }

        // 3. Execution with Atomic Lock (Redis)
        $lockKey = 'checkin_lock_user_' . $user->id;
        try {
            // PRODUCTION TUNING: 5s TTL, 1s block for check-in
            return Cache::lock($lockKey, 5)->block(1, function () use ($user, $request, $post) {
                // PERFORMANCE: Store raw temp file, processing happens in background job
                $tempPath = null;
                if ($request->hasFile('selfie_photo')) {
                    $tempPath = $request->file('selfie_photo')->store('temp/attendance', 'public');
                }

                $data = $request->except(['selfie_photo']);
                $data['post_id'] = $post?->id;

                try {
                    $result = $this->attendanceService->executeCheckIn($user, $data, $tempPath);
                } catch (\Illuminate\Database\QueryException $e) {
                    if ($tempPath) Storage::disk('public')->delete($tempPath);
                    if ($e->getCode() == '23000') {
                        return response()->json(['success' => false, 'message' => 'Anda sudah melakukan check-in untuk jadwal ini.'], 409);
                    }
                    throw $e;
                }

                if (!$result['success']) {
                    if ($tempPath) Storage::disk('public')->delete($tempPath);
                    return response()->json($result, $result['status_code'] ?? 403);
                }

                $attendance = $result['attendance'];
                $isEdit = $result['is_edit'];

                $this->scheduleCacheService->bumpScheduleSheetCacheVersion($attendance->project_id);

                // 🔄 AUTO RECALCULATE PAYROLL: Trigger payroll recalculation for attendance month
                // This ensures payroll data is fresh when admin views the sheet
                try {
                    $month = $attendance->date->format('Y-m');
                    $project = Project::findOrFail($attendance->project_id);
                    $this->payrollService->generateOrRefreshDraft($project, $month, true);
                } catch (\Throwable $e) {
                    // Log error but don't block checkin response
                    \Log::warning("Payroll auto-recalculate failed: {$e->getMessage()}");
                }

                // Broadcast event setelah check-in berhasil
                // broadcast(new AttendanceUpdated(
                //     userId: $attendance->user_id,
                //     status: 'scan',
                //     timestamp: now('UTC'),
                //     assignmentId: $attendance->assignment_id
                // ))->onQueue('default');

                // 🔄 AUTO CHECKOUT: Dispatch job to auto-checkout 2 hours after assignment end_time
if (!$isEdit && !$attendance->check_out_at) {
    $attendance->load('assignment', 'project.organization', 'overtimeLog.workAssignment');

    $assignment = $attendance->assignment;
    $project = $attendance->project;

    if ($assignment && $project) {
        $projectTimezone = $project->timezone ?? $project->organization->timezone ?? 'Asia/Jakarta';
        $attendanceDate = $attendance->date->format('Y-m-d');

        $autoCheckoutTime = null;
        $startTime = null;

        if ($assignment->isOffDuty()) {
            // 🔵 OFF DUTY (LEMBUR)
            $otLog = $attendance->overtimeLog;

            if ($otLog?->workAssignment) {
                $workAsgn = $otLog->workAssignment;

                $startTime = Carbon::createFromFormat(
                    'Y-m-d H:i:s',
                    $attendanceDate . ' ' . $workAsgn->start_time,
                    $projectTimezone
                );

                $autoCheckoutTime = Carbon::createFromFormat(
                    'Y-m-d H:i:s',
                    $attendanceDate . ' ' . $workAsgn->end_time,
                    $projectTimezone
                )->addMinutes(120); // ⬅️ +2 JAM
            }
        } else {
            // 🟢 REGULAR SHIFT
            $startTime = Carbon::createFromFormat(
                'Y-m-d H:i:s',
                $attendanceDate . ' ' . $assignment->start_time,
                $projectTimezone
            );

            $autoCheckoutTime = Carbon::createFromFormat(
                'Y-m-d H:i:s',
                $attendanceDate . ' ' . $assignment->end_time,
                $projectTimezone
            )->addMinutes(120); // ⬅️ +2 JAM
        }

        // 🌙 HANDLE SHIFT MALAM (overnight)
        if ($startTime && $autoCheckoutTime && $autoCheckoutTime->lessThanOrEqualTo($startTime)) {
            $autoCheckoutTime->addDay();
        }

        // 🚀 DISPATCH JOB
        if ($autoCheckoutTime) {
            \Log::info('AUTO CHECKOUT SCHEDULED: '.$autoCheckoutTime);

            AutoCheckoutAttendance::dispatch($attendance->id)
                ->delay($autoCheckoutTime); // ⬅️ LANGSUNG CARBON (JANGAN TIMESTAMP)
        }
    }
}

                // �💡 FINAL RESPONSE (Including attendance_id and matched previous format)
                return response()->json([
                    'message' => $isEdit ? 'Check-in berhasil diperbarui.' : 'Absen masuk berhasil.',
                    'attendance_id' => $attendance->id,
                    'date' => $result['today'],
                    'time' => $result['now_tz']->format('H:i:s'),
                    'status' => $attendance->computed_status,
                    'late_minutes' => (int) $attendance->late_minutes,
                    'distance' => $result['distance'] ?? null,
                    'data' => $this->formatAttendanceResponse($attendance),
                ], $isEdit ? 200 : 201);
            });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            return response()->json(['message' => 'Permintaan sedang diproses. Mohon tunggu sejenak.'], 423);
        }
    }

    /**
     * PROGRESS per assignment aktif (project milik user)
     * GET /api/attendances/progress
     *
     * Query/body:
     * - current_time (optional): Y-m-d H:i:s (device time). Jika tidak dikirim, pakai "now" project timezone.
     *
     * Output:
     * - assignment aktif saat ini
     * - progress check-in (berapa yang sudah check-in)
     * - progress patrol scan per member (jika sudah check-in)
     */
    public function progress(Request $request)
    {
        return $this->progressTeamByPost($request);

       
    }



    /**
     * Per-post progress detail endpoint for danru/admin_project/ho (attendance controller)
     * GET /api/posts/{post}/attendance/progress-detail
     * 
     * Features:
     * - Redis caching for performance
     * - Support for both mobile and static posts
     * - Static posts don't include scan_detail
     */
    public function progressPostDetail(Request $request, Post $post)
    {
        $this->authorize('progress', Attendance::class);

        $user = Auth::user();

        // Project scope for HO atau user join
        if ($user->role === 'ho') {
            $projectId = (int) $request->query('project_id', 0);
            if ($projectId <= 0) {
                return response()->json(['message' => 'project_id wajib dikirim untuk HO.'], 422);
            }
        } else {
            $projectId = (int) ($user->project_id ?? 0);
            if ($projectId <= 0) {
                return response()->json(['message' => 'User tidak memiliki project.'], 422);
            }
        }

        if ((int) $post->project_id !== $projectId && $user->role !== 'dev') {
            return response()->json(['message' => 'Post tidak berada dalam project Anda.'], 403);
        }

        $project = Project::with('organization')->find($projectId);
        if (! $project) {
            return response()->json(['message' => 'Project tidak ditemukan.'], 404);
        }

        $projectTimezone = $project->timezone ?? $project->organization->timezone ?? 'Asia/Jakarta';
        $nowInProjectTz = $request->filled('current_time')
            ? Carbon::createFromFormat('Y-m-d H:i:s', $request->current_time, $projectTimezone)
            : now($projectTimezone);

        $requestUserId = $request->query('user_id') ? (int) $request->query('user_id') : null;

        // ==================== Data Retrieval ====================
        $today = $nowInProjectTz->toDateString();
        $yesterday = $nowInProjectTz->copy()->subDay()->toDateString();

        // Ambil schedule dari hari ini dan kemarin (untuk support lintas malam)
        $schedulesToday = Schedule::where('project_id', $projectId)
            ->whereIn('date', [$today, $yesterday])
            ->with(['assignment', 'user'])
            ->get();

        // Filter schedule berdasarkan assignment yang sedang aktif saat ini
        $activeSchedules = $schedulesToday->filter(function ($schedule) use ($nowInProjectTz, $projectTimezone) {
            if (! $schedule->assignment) {
                return false;
            }

            $schDate = $schedule->date instanceof \Carbon\Carbon ? $schedule->date->format('Y-m-d') : \Carbon\Carbon::parse($schedule->date)->format('Y-m-d');
            $start = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $schDate.' '.$schedule->assignment->start_time, $projectTimezone);
            $end = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $schDate.' '.$schedule->assignment->end_time, $projectTimezone);

            if ($end->lessThanOrEqualTo($start)) {
                $end->addDay();
            }

            return $nowInProjectTz->greaterThanOrEqualTo($start) && $nowInProjectTz->lessThan($end);
        })->values();

        $activeAssignmentId = null;
        if ($activeSchedules->isNotEmpty()) {
            $regularActive = $activeSchedules->filter(fn($s) => ! $s->assignment->isOffDuty())->first();
            if ($regularActive) {
                $activeAssignmentId = $regularActive->assignment_id;
            } else {
                $activeAssignmentId = $activeSchedules->first()->assignment_id ?? null;
            }
        }

        // CACHE KEY: reset cache for each shift by using the active assignment id as part of the key
        $cacheKey = 'progress_post_' . $post->id . '_' . $nowInProjectTz->format('Y-m-d H:i') . '_shift_' . ($activeAssignmentId ?? 'none');
        if ($requestUserId) {
            $cacheKey .= '_user_' . $requestUserId;
        }
        $cacheMinutes = 5;

        if ($activeSchedules->isEmpty()) {
            $postPoints = $post->patrolPoints()->orderBy('sequence_order')->get();
            $patrolPoints = $postPoints->map(function ($point) {
                return [
                    'id' => $point->id,
                    'name' => $point->name,
                    'sequence_order' => $point->sequence_order,
                    'latitude' => $point->latitude,
                    'longitude' => $point->longitude,
                    'is_scanned' => false,
                    'scanned_count' => 0,
                    'last_scan_time' => null,
                    'last_scan_note' => null,
                    'last_scan_user' => null,
                ];
            });

            $postProgress = [
                'post_id' => $post->id,
                'post_name' => $post->name,
                'post_type' => $post->type,
                'project_id' => $post->project_id,
                'assignment_id' => null,
                'total_members' => 0,
                'checked_in_members' => 0,
                'not_checked_in_members' => 0,
                'total_patrol_points' => $postPoints->count(),
                'scanned_patrol_points' => 0,
                'remaining_patrol_points' => $postPoints->count(),
                'progress_percentage' => 0,
            ];

            $responseData = [
                'user' => null,
                'post_progress' => $postProgress,
                'patrol_points' => $patrolPoints,
                'activity_list' => [],
                'user_timesheet' => [],
            ];

            if ($post->type === 'mobile') {
                $responseData['scan_details'] = [];
            }

            return response()->json([
                'success' => true,
                'data' => $responseData,
            ], 200);
        }

        // Try to get from cache first (for non-static posts or if cache available)
        $cachedData = Cache::get($cacheKey);
        if ($cachedData && $post->type === 'mobile') {
            return response()->json(['success' => true, 'data' => $cachedData], 200);
        }

        if ($activeSchedules->isEmpty()) {
            $postPoints = $post->patrolPoints()->orderBy('sequence_order')->get();
            $patrolPoints = $postPoints->map(function ($point) {
                return [
                    'id' => $point->id,
                    'name' => $point->name,
                    'sequence_order' => $point->sequence_order,
                    'latitude' => $point->latitude,
                    'longitude' => $point->longitude,
                    'is_scanned' => false,
                    'scanned_count' => 0,
                    'last_scan_time' => null,
                    'last_scan_note' => null,
                    'last_scan_user' => null,
                ];
            });

            $postProgress = [
                'post_id' => $post->id,
                'post_name' => $post->name,
                'post_type' => $post->type,
                'project_id' => $post->project_id,
                'assignment_id' => null,
                'total_members' => 0,
                'checked_in_members' => 0,
                'not_checked_in_members' => 0,
                'total_patrol_points' => $postPoints->count(),
                'scanned_patrol_points' => 0,
                'remaining_patrol_points' => $postPoints->count(),
                'progress_percentage' => 0,
            ];

            $responseData = [
                'user' => null,
                'post_progress' => $postProgress,
                'patrol_points' => $patrolPoints,
                'activity_list' => [],
                'user_timesheet' => [],
            ];

            if ($post->type === 'mobile') {
                $responseData['scan_details'] = [];
                Cache::put($cacheKey, $responseData, now()->addMinutes($cacheMinutes));
            }

            return response()->json([
                'success' => true,
                'data' => $responseData,
            ], 200);
        }

        if ($user->role === 'komandan_regu') {
            $activeSchedules = $activeSchedules->filter(function ($schedule) use ($user) {
                if (! $schedule->user) {
                    return false;
                }
                if ($schedule->user->role === 'komandan_regu') {
                    return (int) $schedule->user_id === (int) $user->id;
                }

                return true;
            })->values();
        }

        $scheduleIds = $activeSchedules->pluck('id')->all();

        $attendances = Attendance::where(function($q) use ($scheduleIds) {
                $q->whereIn('schedule_id', $scheduleIds);
            })
            ->where('post_id', $post->id)
            ->when($requestUserId, fn($q) => $q->where('user_id', $requestUserId))
            ->with(['user', 'post', 'schedule.assignment', 'project.organization', 'patrolScans.qrCode.patrolPoint', 'overtimeLog', 'assignment'])
            ->get()
            ->filter(function($att) use ($activeSchedules, $projectTimezone, $nowInProjectTz) {
                if ($activeSchedules->isEmpty()) {
                    return false;
                }

                if (in_array($att->schedule_id, $activeSchedules->pluck('id')->all())) {
                    return true;
                }

                return false;
            });

        $totalMembers = $attendances->count();
        $checkedIn = $attendances->whereNotNull('check_in_at')->count();
        $notCheckedIn = max(0, $totalMembers - $checkedIn);

        // Patrol points progress
        $postPoints = $post->patrolPoints()->orderBy('sequence_order')->get();
        $attendanceIds = $attendances->pluck('id')->all();

        $allScans = PatrolScan::with(['qrCode.patrolPoint', 'attendance.user', 'photos'])
            ->whereIn('attendance_id', $attendanceIds)
            ->get();

        $patrolPoints = $postPoints->map(function ($point) use ($allScans) {
            $pointScans = $allScans->filter(function ($scan) use ($point) {
                return $scan->qrCode?->patrolPoint?->id === $point->id;
            })->sortBy('scan_time');

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

        $activityAssignmentId = $request->query('assignment_id');

        if (! $activityAssignmentId) {
            $liveAtt = $attendances->whereNotNull('check_in_at')->first();
            if ($liveAtt) {
                if ($liveAtt->assignment?->isOffDuty()) {
                    $activityAssignmentId = $liveAtt->overtimeLog?->work_assignment_id;
                } else {
                    $activityAssignmentId = $liveAtt->assignment_id;
                }
            }
        }

        if (! $activityAssignmentId && $activeAssignmentId) {
            $activityAssignmentId = $activeAssignmentId;
        }

        $activityQuery = Activity::where('active', true)
            ->where('post_id', $post->id);

        if ($activityAssignmentId) {
            $activityQuery->whereHas('assignmentTimes', function ($q) use ($activityAssignmentId) {
                $q->where('assignment_id', $activityAssignmentId);
            });
        }

        $activityList = $activityQuery
            ->with('assignmentTimes.assignment')
            ->orderBy('name')
            ->get()
            ->map(function ($activity) use ($activityAssignmentId) {
                $filteredTimes = $activity->assignmentTimes->where('assignment_id', $activityAssignmentId);

                return [
                    'id' => $activity->id,
                    'name' => $activity->name,
                    'location' => $activity->location,
                    'assignment_times' => $filteredTimes->map(function ($t) {
                        return [
                            'id' => $t->id,
                            'assignment_id' => $t->assignment_id,
                            'assignment_name' => $t->assignment?->name,
                            'start_time' => $t->start_time,
                            'end_time' => $t->end_time,
                        ];
                    })->values(),
                ];
            })
            ->filter(fn ($act) => $act['assignment_times']->isNotEmpty())
            ->values();

        // Timesheet user for checked-in attendance users in this post
        if ($requestUserId) {
            $userIds = collect([$requestUserId]);
        } else {
            $userIds = $attendances->pluck('user_id')->unique()->values();
        }

        $today = Carbon::now($projectTimezone)->toDateString();
        $yesterday = Carbon::now($projectTimezone)->copy()->subDay()->toDateString();
        $tomorrow = Carbon::now($projectTimezone)->copy()->addDay()->toDateString();

        $allSchedules = Schedule::whereIn('user_id', $userIds)
            ->whereIn('date', [$yesterday, $today, $tomorrow])
            ->with(['assignment', 'absence', 'attendance.overtimeLog.workAssignment', 'user'])
            ->orderBy('date')
            ->get()
            ->groupBy('user_id');

        $userTimesheets = collect();
        foreach ($allSchedules as $uId => $uSchedules) {
            $uName = null;
            foreach ($uSchedules as $sch) {
                if (! $uName) $uName = $sch->user?->full_name;

                $scheduleDate = $sch->date instanceof Carbon
                    ? $sch->date->copy()->setTimezone($projectTimezone)
                    : Carbon::parse($sch->date, $projectTimezone);

                $att = $sch->attendance;
                $isOff = $sch->assignment?->isOffDuty() ?? true;
                $statusStr = '';
                $checkIn = null;
                $checkOut = null;

                if ($att) {
                    $checkIn = $att->check_in_at ? $att->check_in_at->setTimezone($projectTimezone)->format('H:i') : null;
                    $checkOut = $att->check_out_at ? $att->check_out_at->setTimezone($projectTimezone)->format('H:i') : null;
                    $statusStr = in_array($att->computed_status, ['HADIR TELAT', 'HADIR TELAT LEMBUR']) ? 'Telat' : 'Tepat Waktu';
                } elseif ($sch->absence) {
                    $statusStr = $sch->absence->label;
                } else {
                    $statusStr = $scheduleDate->greaterThanOrEqualTo(now($projectTimezone)->startOfDay()) ? 'Belum Absen' : ($isOff ? 'Libur' : 'Tidak Absen');
                }

                $userTimesheets->push([
                    'user_id' => $uId,
                    'user_name' => $uName,
                    'date' => $scheduleDate->format('Y-m-d'),
                    'check_in_at' => $checkIn,
                    'check_out_at' => $checkOut,
                    'status' => $statusStr,
                    'assignment_code' => $att ? ($isOff ? ($att->overtimeLog?->workAssignment?->code ?? $att->assignment?->code) : $att->assignment?->code) : ($sch->assignment?->code ?? '-'),
                ]);
            }
        }

        $postProgress = [
            'post_id' => $post->id,
            'post_name' => $post->name,
            'post_type' => $post->type,
            'project_id' => $post->project_id,
            'assignment_id' => $activeAssignmentId,
            'total_members' => $totalMembers,
            'checked_in_members' => $checkedIn,
            'not_checked_in_members' => $notCheckedIn,
            'total_patrol_points' => $postPoints->count(),
            'scanned_patrol_points' => $patrolPoints->where('is_scanned', true)->count(),
            'remaining_patrol_points' => $patrolPoints->where('is_scanned', false)->count(),
            'progress_percentage' => $postPoints->count() > 0 ? round($patrolPoints->where('is_scanned', true)->count() / $postPoints->count() * 100, 2) : 0,
        ];

        // For static posts, don't include scan_detail
        $responseData = [
            'user' => $attendances->whereNotNull('check_in_at')->first() ? [
                'id' => $attendances->whereNotNull('check_in_at')->first()->user_id,
                'name' => $attendances->whereNotNull('check_in_at')->first()->user?->full_name,
                'check_in_at' => $attendances->whereNotNull('check_in_at')->first()->check_in_at,
                'check_out_at' => $attendances->whereNotNull('check_in_at')->first()->check_out_at,
                'computed_status' => $attendances->whereNotNull('check_in_at')->first()->computed_status,
            ] : null,
            'post_progress' => $postProgress,
            'patrol_points' => $patrolPoints,
            'activity_list' => $activityList,
            'user_timesheet' => $userTimesheets,
        ];

        // Only include scan_detail for mobile posts
        if ($post->type === 'mobile') {
            $scanDetails = $allScans->map(function ($scan) {
                return [
                    'id' => $scan->id,
                    'attendance_id' => $scan->attendance_id,
                    'patrol_point_id' => $scan->qrCode->patrolPoint->id,
                    'patrol_point_name' => $scan->qrCode->patrolPoint->name,
                    'scan_time' => $scan->scan_time,
                    'note' => $scan->note,
                    'photos' => $scan->photos->map(function ($photo) {
                        return [
                            'id' => $photo->id,
                            'url' => SignedMediaUrl::patrolScanPhoto($photo),
                            'storage_url_legacy' => Storage::disk('public')->url($photo->photo),
                            'api_inline_url' => url('/api/patrol-scan-photo/'.$photo->id.'/inline'),
                        ];
                    }),
                    'scan_user' => [
                        'id' => $scan->attendance->user_id,
                        'full_name' => $scan->attendance->user?->full_name,
                    ],
                ];
            });
            $responseData['scan_details'] = $scanDetails;
        }

        // Cache the response for mobile posts only
        if ($post->type === 'mobile') {
            Cache::put($cacheKey, $responseData, now()->addMinutes($cacheMinutes));
        }

        return response()->json([
            'success' => true,
            'data' => $responseData,
        ], 200);
    }

   

    public function progressall(Request $request)
    {   
        $this->authorize('progress', Attendance::class);
        $this->authorize('viewAny', Attendance::class);

        $user = Auth::user();
        $postId = $request->query('post_id');
        $danruId = $request->query('danru_id');
        $userId = $request->query('user_id');

        if (!$postId && !$danruId) {
            return response()->json(['message' => 'Harus kirim post_id atau danru_id'], 422);
        }

        if ($postId && $danruId) {
            return response()->json(['message' => 'Gunakan salah satu saja'], 422);
        }

        if ($user->role === 'ho') {
            $projectId = (int) $request->query('project_id', 0);
            if ($projectId <= 0) {
                return response()->json(['message' => 'project_id wajib'], 422);
            }
        } else {
            $projectId = (int) ($user->project_id ?? 0);
        }

        $project = Project::with('organization')->find($projectId);
        if (! $project) {
            return response()->json(['message' => 'Project tidak ditemukan'], 404);
        }

        $projectTimezone = $project->timezone ?? $project->organization->timezone ?? 'Asia/Jakarta';
        $currentTime = $request->query('current_time');

        $response = $this->attendanceProgressService->progressAll(
            $projectId,
            $postId,
            $danruId,
            $userId,
            $currentTime,
            $projectTimezone
        );

        return response()->json($response, 200);
    }
    /**
     * DELETE /api/attendances/check-in/{attendance}
     * Hapus attendance jika check-in salah input.
     */
    public function deleteCheckIn(Request $request, Attendance $attendance)
    {
        $this->authorize('deleteCheckIn', $attendance);

        if (! $attendance->check_in_at) {
            return response()->json([
                'message' => 'Attendance tidak valid.',
            ], 400);
        }

        if ($attendance->check_out_at) {
            return response()->json([
                'message' => 'anda sudah check-out.',
            ], 409);
        }

        $projectId = $attendance->project_id;

        // Hapus attendance (beserta data turunan scan via FK jika ada)
        $attendance->delete();

        $this->scheduleCacheService->bumpScheduleSheetCacheVersion($projectId);

        return response()->json([
            'message' => 'Check-in berhasil dihapus.',
        ], 200);
    }

    /**
     * Progress tim berbasis Post (slot = jumlah post project).
     * - Anggota: tidak memiliki akses (dibatasi policy).
     * - Danru (komandan_regu) & admin_project: hanya project miliknya.
     * - HO: bisa pilih `project_id` pada request body/query.
     * Optional: `attendance_id` untuk filter danru tertentu.
     */
    private function progressTeamByPost(Request $request)
    {
        $this->authorize('progress', Attendance::class);

        $user = Auth::user();

        $projectId = null;
        if ($user->role === 'ho') {
            $projectId = (int) $request->input('project_id', 0);
            if ($projectId <= 0) {
                return response()->json([
                    'message' => 'project_id wajib dikirim untuk HO.',
                ], 422);
            }
        } else {
            $projectId = (int) ($user->project_id ?? 0);
            if ($projectId <= 0) {
                return response()->json([
                    'message' => 'User tidak memiliki project.',
                ], 422);
            }
        }

        $project = Project::with('organization')->find($projectId);
        if (! $project) {
            return response()->json([
                'message' => 'Project tidak ditemukan.',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'current_time' => 'sometimes|date_format:Y-m-d H:i:s',
        ]);
        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $response = $this->teamProgressService->getTeamProgress(
            $user,
            $project,
            $request->query('current_time'),
            $request->query('attendance_id') ? (int) $request->query('attendance_id') : null
        );

        return response()->json($response, 200);
    }



    public function checkOut(Request $request)
    {
        $validator = Validator::make($request->all(), [
            // OLD: 'attendance_id' => 'required|exists:attendances,id',
            'attendance_id' => 'sometimes|integer', // MODIFIED: Now optional, can get from token
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'current_time' => 'required|date_format:Y-m-d H:i:s', // Device time
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $user = Auth::user();

        // MODIFIED: FORCE fetch active attendance using token
        // Client says "checkout directly by sending user token and server resolves active pending checkout"
        $attendance = Attendance::where('user_id', $user->id)
            ->whereNotNull('check_in_at')
            ->whereNull('check_out_at')
            ->orderBy('date', 'desc')
            ->orderBy('check_in_at', 'desc')
            ->with('assignment', 'post')
            ->first();

        // Tembakan fallback bila secara mengejutkan user tidak terdeteksi punya unclosed, tetapi manual request memintanya
        if (! $attendance && $request->has('attendance_id')) {
            $attendance = Attendance::where('id', $request->attendance_id)
                ->where('user_id', $user->id)
                ->with('assignment', 'post')
                ->first();
        }

        if (! $attendance) {
            $activeAttendance = Attendance::where('user_id', $user->id)
                ->whereNotNull('check_in_at')
                ->whereNull('check_out_at')
                ->orderByDesc('check_in_at')
                ->first();

            return response()->json([
                'message' => 'Anda sudah absen pulang',
                'active_attendance_id' => $activeAttendance ? (int) $activeAttendance->id : null,
            ], 404);
        }

        // Attendance harus aktif untuk check-out (sudah check-in & belum check-out)
        if (! $attendance->check_in_at || $attendance->check_out_at) {
            $activeAttendance = Attendance::where('user_id', $user->id)
                ->whereNotNull('check_in_at')
                ->whereNull('check_out_at')
                ->orderByDesc('check_in_at')
                ->first();

            if ($activeAttendance) {
                return response()->json([
                    'message' => 'Attendance tidak valid. Gunakan attendance aktif yang belum check-out.',
                    'active_attendance_id' => (int) $activeAttendance->id,
                ], 409);
            }

            return response()->json(['message' => 'Attendance tidak valid'], 400);
        }

        // ========= AUTHORIZATION: User hanya bisa checkout attendance miliknya
        $this->authorize('checkout', $attendance);

        // GUARD: Harus sudah check-in dulu
        if (! $attendance->check_in_at) {
            return response()->json(['message' => 'Anda belum absen masuk.'], 403);
        }

        // GUARD: Sudah check-out sebelumnya
        if ($attendance->check_out_at) {
            return response()->json(['message' => 'Anda sudah absen pulang.'], 409);
        }

        $assignment = $attendance->assignment;
        $post = $attendance->post;
        $project = $attendance->project;
        $projectTimezone = $project->timezone ?? $project->organization->timezone ?? 'Asia/Jakarta';
        $now = Carbon::createFromFormat('Y-m-d H:i:s', $request->current_time, $projectTimezone);
        // Convert ke UTC untuk internal logic
        $now->setTimezone('UTC');

        // GUARD: Project harus memiliki location
        if (! $project || ! $project->location_latitude || ! $project->location_longitude) {
            return response()->json([
                'message' => 'Project tidak memiliki data lokasi. Hubungi administrator.',
                'project_id' => $attendance->project_id,
            ], 403);
        }

        // ========== LOCATION VERIFICATION for CHECK-OUT ==========
        // Same reference location logic as check-in: Always use PROJECT location
        $globalRadius = (float) ($project->radius ?? 100);

        $deviceLatitude = (float) $request->latitude;
        $deviceLongitude = (float) $request->longitude;

        // REFERENCE location: Always from PROJECT (fixed office location)
        $referenceLatitude = (float) $project->location_latitude;
        $referenceLongitude = (float) $project->location_longitude;

        $distance = $this->attendanceService->calculateDistance(
            $referenceLatitude,
            $referenceLongitude,
            $deviceLatitude,
            $deviceLongitude
        );

        if ($distance > $globalRadius) {
            return response()->json([
                'message' => 'Anda berada di luar radius absen pulang.',
                'your_location' => [
                    'latitude' => round($deviceLatitude, 6),
                    'longitude' => round($deviceLongitude, 6),
                ],
                'distance' => round($distance, 2).' meters',
                'allowed_radius' => $globalRadius.' meters',
            ], 403);
        }

        $isOffDutyAssignment = $assignment->isOffDuty(); // termasuk code 'O'
        $overtimeMinutes = 0;
        $overtimeStatus = 'NONE';
        $hasOffDayOvertime = false;

        // Assignment O/OFF: tidak perlu overtime log approval, dan overtime dihitung dari durasi kerja (check-in -> check-out)
        if ($isOffDutyAssignment) {
            $otLog = $attendance->overtimeLog()->with('workAssignment')->first();

            // Validasi end_time dari workAssignment sebelum mengizinkan checkout
            if ($otLog?->workAssignment) {
                $workAsgn     = $otLog->workAssignment;
                $workDate     = $attendance->date->format('Y-m-d');
                $workEndUtc   = Carbon::createFromFormat('Y-m-d H:i:s', $workDate.' '.$workAsgn->end_time, $projectTimezone)->setTimezone('UTC');
                $workStartUtc = Carbon::createFromFormat('Y-m-d H:i:s', $workDate.' '.$workAsgn->start_time, $projectTimezone)->setTimezone('UTC');

                if ($workEndUtc->lessThanOrEqualTo($workStartUtc)) {
                    $workEndUtc->addDay();
                }

                if ($now->isBefore($workEndUtc)) {
                    return response()->json([
                        'message'      => 'Belum waktunya absen pulang.',
                        'end_time'     => $workEndUtc->copy()->setTimezone($projectTimezone)->format('H:i:s'),
                        'current_time' => $now->copy()->setTimezone($projectTimezone)->format('H:i:s'),
                        'timezone'     => $projectTimezone,
                    ], 403);
                }
            }

            $overtimeMinutes   = (int) ($otLog?->workAssignment?->getDurationInMinutes() ?? 0);
            $overtimeStatus    = $otLog ? 'APPROVED' : 'NONE';
            $hasOffDayOvertime = (bool) $otLog;
        } else {
            // Tentukan end_time dari assignment
            $endTime = Carbon::createFromFormat('Y-m-d H:i:s', $attendance->date->format('Y-m-d').' '.$assignment->end_time, $projectTimezone);
            $endTime->setTimezone('UTC');

            // Handle midnight shift - gunakan COPY untuk perbandingan, jangan ubah original
            $startTime = Carbon::createFromFormat('Y-m-d H:i:s', $attendance->date->format('Y-m-d').' '.$assignment->start_time, $projectTimezone);
            $startTime->setTimezone('UTC');

            // HANDLE MIDNIGHT SHIFT
            if ($endTime->lessThanOrEqualTo($startTime)) {
                $endTime->addDay();
            }

            $endTimeForComparison = $endTime->copy();

            if ($now->isBefore($endTimeForComparison)) {
                $nowInProjectTz = $now->copy()->setTimezone($projectTimezone);
                $endTimeInProjectTz = $endTime->copy()->setTimezone($projectTimezone);  // ← Use ORIGINAL!

                return response()->json([
                    'message' => 'Belum waktunya absen pulang.',
                    'end_time' => $endTimeInProjectTz->format('H:i:s'),  // ← Tetap original
                    'current_time' => $nowInProjectTz->format('H:i:s'),
                    'timezone' => $projectTimezone,
                ], 403);
            }

            // Untuk perhitungan overtime, gunakan endTimeForComparison yang sudah adjusted
            if ($now->isAfter($endTimeForComparison)) {
                $overtimeMinutes = $now->diffInMinutes($endTimeForComparison);
                // Status tetap NONE sampai di-approve via OvertimeLog
                $overtimeStatus = 'NONE';
            }
        }

        // Verify all patrol scans completed sebelum check-out
        // - Member: wajib menyelesaikan semua patrol point pada post yang dipakai attendance.
        // - Komandan regu: wajib menyelesaikan semua patrol point dari semua post STATIC di project.
        // if ($attendance->isCommanderAttendance()) {
        //     $staticPostIds = $attendance->project?->posts()
        //         ->where('type', 'static')
        //         ->pluck('id')
        //         ->all() ?? [];

        //     $totalPoints = empty($staticPostIds)
        //         ? 0
        //         : PatrolPoint::whereIn('post_id', $staticPostIds)->count();

        //     if ($totalPoints > 0) {
        //         $scannedPoints = PatrolScan::where('attendance_id', $attendance->id)->count();

        //         if ($scannedPoints < $totalPoints) {
        //             return response()->json([
        //                 'message' => 'Anda harus menyelesaikan semua scan titik patroli.',
        //                 'scanned' => $scannedPoints,
        //                 'total' => $totalPoints,
        //             ], 403);
        //         }
        //     }
        // } elseif ($post) {
        //     $totalPoints = PatrolPoint::where('post_id', $post->id)->count();
        //     if ($totalPoints > 0) {
        //         $scannedPoints = PatrolScan::where('attendance_id', $attendance->id)->count();

        //         if ($scannedPoints < $totalPoints) {
        //             return response()->json([
        //                 'message' => 'Anda harus menyelesaikan semua scan titik patroli.',
        //                 'scanned' => $scannedPoints,
        //                 'total' => $totalPoints,
        //             ], 403);
        //         }
        //     }
        // }

        // Update computed_status (overtimeMinutes dan overtimeStatus sudah dihitung di atas)
        $computedStatus = $attendance->computed_status; // Start dari status check-in

        if (($hasOffDayOvertime || $overtimeMinutes > 0) && strpos($computedStatus, 'LEMBUR') === false) {
            $computedStatus .= ' LEMBUR';
        }

        $attendance->update([
            'check_out_at' => $now,
            'checkout_lat' => $request->latitude,
            'checkout_lng' => $request->longitude,
            'overtime_minutes' => $overtimeMinutes,
            'overtime_status' => $overtimeStatus,
            'computed_status' => $computedStatus,
        ]);

        // Batalkan cache laporan project ini dengan update versi
        Cache::forever('project_reports_'.$project->id.'_v', time());

        $projectTimezone = $project->timezone ?? $project->organization->timezone ?? 'Asia/Jakarta';
        $nowInProjectTz = $now->copy()->setTimezone($projectTimezone);

        return response()->json([
            'message' => 'Absen pulang berhasil.',
            'date' => $attendance->date->format('Y-m-d'),
            'time' => $nowInProjectTz->format('H:i:s'),
            'timezone' => $projectTimezone,
            'status' => $computedStatus,
            'overtime_minutes' => (int) $overtimeMinutes,
            'data' => $this->formatAttendanceResponse($attendance->fresh()),
        ], 200);
    }

    /**
     * AUTO CHECKOUT - triggered by scheduled job or client request
     * POST /api/attendances/auto-checkout
     * Automatically checkout 2 hours after assignment end_time
     */
    public function autoCheckout(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'attendance_id' => 'required|integer|exists:attendances,id',
            'current_time' => 'required|date_format:Y-m-d H:i:s',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $user = Auth::user();
        $attendance = Attendance::where('id', $request->attendance_id)
            ->where('user_id', $user->id)
            ->with('assignment', 'post', 'project.organization', 'overtimeLog.workAssignment')
            ->first();

        if (!$attendance) {
            return response()->json(['message' => 'Attendance tidak ditemukan.'], 404);
        }

        // Guard: Sudah check-out sebelumnya
        if ($attendance->check_out_at) {
            return response()->json(['message' => 'Attendance sudah check-out.'], 409);
        }

        // Guard: Belum check-in
        if (!$attendance->check_in_at) {
            return response()->json(['message' => 'Attendance belum check-in.'], 400);
        }

        $assignment = $attendance->assignment;
        $project = $attendance->project;
        $projectTimezone = $project->timezone ?? $project->organization->timezone ?? 'Asia/Jakarta';

        // Validasi waktu: pastikan sudah 2 jam lebih dari end_time
        $deviceTime = Carbon::createFromFormat('Y-m-d H:i:s', $request->current_time, $projectTimezone)->setTimezone('UTC');
        
        $isOffDutyAssignment = $assignment->isOffDuty();
        $targetEndTime = null;

        if ($isOffDutyAssignment) {
            $otLog = $attendance->overtimeLog;
            if ($otLog?->workAssignment) {
                $workDate = $attendance->date->format('Y-m-d');
                $targetEndTime = Carbon::createFromFormat('Y-m-d H:i:s', $workDate . ' ' . $otLog->workAssignment->end_time, $projectTimezone)->setTimezone('UTC');
                
                if ($targetEndTime->lessThanOrEqualTo(Carbon::createFromFormat('Y-m-d H:i:s', $workDate . ' ' . $otLog->workAssignment->start_time, $projectTimezone)->setTimezone('UTC'))) {
                    $targetEndTime->addDay();
                }
            }
        } else {
            $workDate = $attendance->date->format('Y-m-d');
            $targetEndTime = Carbon::createFromFormat('Y-m-d H:i:s', $workDate . ' ' . $assignment->end_time, $projectTimezone)->setTimezone('UTC');
            
            $startTime = Carbon::createFromFormat('Y-m-d H:i:s', $workDate . ' ' . $assignment->start_time, $projectTimezone)->setTimezone('UTC');
            if ($targetEndTime->lessThanOrEqualTo($startTime)) {
                $targetEndTime->addDay();
            }
        }

        // Calculate 2 hours after target end time
        $autoCheckoutTime = $targetEndTime->copy()->addHours(2);

        // Validasi: device time harus >= 2 jam setelah end_time
        if ($deviceTime->isBefore($autoCheckoutTime)) {
            return response()->json([
                'message' => 'Belum waktunya auto checkout.',
                'auto_checkout_at' => $autoCheckoutTime->copy()->setTimezone($projectTimezone)->format('H:i:s'),
                'current_time' => $deviceTime->copy()->setTimezone($projectTimezone)->format('H:i:s'),
                'timezone' => $projectTimezone,
            ], 403);
        }

        // Set default checkout location to project location (or checkin location)
        $checkoutLat = $attendance->checkout_lat ?? $attendance->checkin_lat ?? $project->location_latitude;
        $checkoutLng = $attendance->checkout_lng ?? $attendance->checkin_lng ?? $project->location_longitude;

        // Calculate overtime
        $overtimeMinutes = 0;
        $overtimeStatus = 'NONE';
        $hasOffDayOvertime = false;

        if ($isOffDutyAssignment) {
            $otLog = $attendance->overtimeLog;
            $overtimeMinutes = (int) ($otLog?->workAssignment?->getDurationInMinutes() ?? 0);
            $overtimeStatus = $otLog ? 'APPROVED' : 'NONE';
            $hasOffDayOvertime = (bool) $otLog;
        } else {
            if ($deviceTime->isAfter($targetEndTime)) {
                $overtimeMinutes = $deviceTime->diffInMinutes($targetEndTime);
                $overtimeStatus = 'NONE';
            }
        }

        // Update computed_status
        $computedStatus = $attendance->computed_status;
        if (($hasOffDayOvertime || $overtimeMinutes > 0) && strpos($computedStatus, 'LEMBUR') === false) {
            $computedStatus .= ' LEMBUR';
        }

        // Update attendance dengan auto-checkout
        $attendance->forceFill([
            'check_out_at' => $deviceTime,
            'checkout_lat' => $checkoutLat,
            'checkout_lng' => $checkoutLng,
            'overtime_minutes' => $overtimeMinutes,
            'overtime_status' => $overtimeStatus,
            'computed_status' => $computedStatus,
        ])->save();

        $attendance->refresh();

        // Invalidate project reports cache
        Cache::forget('project_reports_' . $project->id . '_v');
        Cache::forever('project_reports_' . $project->id . '_v', time());

        $deviceTimeInProjectTz = $deviceTime->copy()->setTimezone($projectTimezone);

        return response()->json([
            'message' => 'Auto checkout berhasil.',
            'date' => $attendance->date->format('Y-m-d'),
            'time' => $deviceTimeInProjectTz->format('H:i:s'),
            'timezone' => $projectTimezone,
            'status' => $computedStatus,
            'overtime_minutes' => (int) $overtimeMinutes,
            'data' => $this->formatAttendanceResponse($attendance->fresh()),
        ], 200);
    }

    /**
     * TIMESHEET
     * GET /attendances/timesheet
     * Menampilkan riwayat schedule dan attendance selama 1 bulan
     */
    public function timesheet(Request $request)
    {
        $user = Auth::user();
    
        $project = $user->project()->with('organization')->first();
        $timezone = $project?->timezone ?? $project?->organization?->timezone ?? 'Asia/Jakarta';
    
        $mode = $request->query('mode', 'monthly');
    
        // 🔥 ambil waktu dari device (kalau ada), kalau tidak pakai server time
        $now = $request->filled('current_time')
            ? Carbon::createFromFormat('Y-m-d H:i:s', $request->current_time, $timezone)
            : now($timezone);
    
        // =======================
        // DATE RANGE
        // =======================
        if ($mode === '3days') {
            $dates = [
                $now->copy()->subDay(),
                $now->copy(),
                $now->copy()->addDay(),
            ];
        } else {
            // 🔥 langsung dari device time (tanpa request month)
            $startDate = $now->copy()->startOfMonth();
            $endDate = $now->copy()->endOfMonth();
    
            $dates = [];
            for ($d = $startDate->copy(); $d <= $endDate; $d->addDay()) {
                $dates[] = $d->copy();
            }
        }
    
        $dateStrings = collect($dates)->map(fn($d) => $d->toDateString())->toArray();
    
        // =======================
        // QUERY
        // =======================
        $schedules = Schedule::where('user_id', $user->id)
            ->whereIn('date', $dateStrings)
            ->with(['assignment', 'absence'])
            ->get()
            ->keyBy(fn ($s) => Carbon::parse($s->date)->format('Y-m-d'));
    
        $attendances = Attendance::where('user_id', $user->id)
            ->whereIn('date', $dateStrings)
            ->with(['assignment', 'overtimeLog.workAssignment'])
            ->get()
            ->keyBy(fn ($a) => Carbon::parse($a->date)->format('Y-m-d'));
    
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

            $schedule = $schedules->get($dateStr);
            $attendance = $attendances->get($dateStr);

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
                // Count absence as tidak_absen
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
    
            $isPastOrToday = $currentDate->lte($now->toDateString());

            if (! $attendanceCodeStr) {
                if ($scheduleCode === 'o' && $isPastOrToday) {
                    $attendanceCodeStr = 'o';
                } else {
                    $attendanceCodeStr = '-';
                }
            }
    
            $history[] = [
                'date' => $currentDate->format('d'),
                'day_name' => $currentDate->translatedFormat('l'),
                'full_date' => $dateStr,
                'schedule_code' => $scheduleCode ?? '-',
                'attendance_code' => $attendanceCodeStr ?? '-',
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'status' => $statusStr,
            ];
        }
    
        return response()->json([
            'meta' => [
                'current_time' => $now->format('Y-m-d H:i:s'),
                'timezone' => $timezone,
                'month' => $now->format('Y-m'), // 🔥 otomatis dari device
            ],
            'data' => [
                [
                    'user_id' => $user->id,
                    'user_name' => $user->full_name,
                    'summary' => $summary,
                    'history' => $history,
                ]
            ]
        ]);
    }

    public function timesheetThreeDays(Request $request)
    {
        $user = Auth::user();
    
        $project = $user->project()->with('organization')->first();
        $timezone = $project?->timezone ?? $project?->organization?->timezone ?? 'Asia/Jakarta';
    
        // ambil waktu dari device, fallback server
        $now = $request->filled('current_time')
            ? Carbon::createFromFormat('Y-m-d H:i:s', $request->current_time, $timezone)
            : now($timezone);
    
        // =======================
        // RANGE 3 HARI
        // kemarin - hari ini - besok
        // =======================
        $dates = [
            $now->copy()->subDay(),
            $now->copy(),
            $now->copy()->addDay(),
        ];
    
        $dateStrings = collect($dates)
            ->map(fn($d) => $d->toDateString())
            ->toArray();
    
        // =======================
        // QUERY
        // =======================
        $schedules = Schedule::where('user_id', $user->id)
            ->whereIn('date', $dateStrings)
            ->with(['assignment', 'absence'])
            ->get()
            ->keyBy(fn($s) => Carbon::parse($s->date)->format('Y-m-d'));
    
        $attendances = Attendance::where('user_id', $user->id)
            ->whereIn('date', $dateStrings)
            ->with(['assignment', 'overtimeLog.workAssignment'])
            ->get()
            ->keyBy(fn($a) => Carbon::parse($a->date)->format('Y-m-d'));
    
        $history = [];
    
        $summary = [
            'total_hari' => 3,
            'schedule_kerja' => 0,
            'kode_kehadiran' => 0,
            'telat' => 0,
            'tidak_absen' => 0,
        ];
    
        foreach ($dates as $currentDate) {
            $dateStr = $currentDate->toDateString();
    
            $schedule = $schedules->get($dateStr);
            $attendance = $attendances->get($dateStr);
    
            $scheduleCode = $schedule?->assignment?->code;
            $isOffSchedule = $schedule ? $schedule->assignment->isOffDuty() : true;
    
            $attendanceCodeStr = '-';
            $checkIn = '--:--';
            $checkOut = '--:--';
            $statusStr = '';
    
            if ($schedule && !$isOffSchedule) {
                $summary['schedule_kerja']++;
            }
    
            if ($attendance) {
                $summary['kode_kehadiran']++;

                if ($isOffSchedule) {
                    $attendanceCodeStr =
                        $attendance->overtimeLog?->workAssignment?->code
                        ?? $attendance->assignment?->code
                        ?? '-';
                } else {
                    $attendanceCodeStr =
                        $attendance->assignment?->code
                        ?? $scheduleCode
                        ?? '-';
                }

                $checkIn = $attendance->check_in_at
                    ? $attendance->check_in_at->copy()->setTimezone($timezone)->format('H:i')
                    : '--:--';

                $checkOut = $attendance->check_out_at
                    ? $attendance->check_out_at->copy()->setTimezone($timezone)->format('H:i')
                    : '--:--';

                if (in_array($attendance->computed_status, [
                    'HADIR TELAT',
                    'HADIR TELAT LEMBUR',
                ])) {
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
    
                if ($schedule && !$isOffSchedule) {
                    $statusStr = 'Tidak Absen';
                    $summary['tidak_absen']++;
                } else {
                    $statusStr = 'Libur';
                }
            }
    
            $isPastOrToday = $currentDate->lte($now->toDateString());

            if (! $attendanceCodeStr) {
                if ($scheduleCode === 'o' && $isPastOrToday) {
                    $attendanceCodeStr = 'o';
                } else {
                    $attendanceCodeStr = '-';
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
    
        return response()->json([
            'meta' => [
                'mode' => '3days',
                'current_time' => $now->format('Y-m-d H:i:s'),
                'timezone' => $timezone,
            ],
            'data' => [
                [
                    'user_id' => $user->id,
                    'user_name' => $user->full_name,
                    'summary' => $summary,
                    'history' => $history,
                ]
            ]
        ]);
    }
  
private function buildTimesheet($userIds, $timezone, $now)
{
    // ===== 3 HARI =====
    $dates = [
        $now->copy()->subDay(),
        $now->copy(),
        $now->copy()->addDay(),
    ];

    $dateStrings = collect($dates)->map(fn($d) => $d->toDateString())->toArray();

    // ===== PRELOAD USER (hindari N+1) =====
    $users = User::whereIn('id', $userIds)->get()->keyBy('id');

    // ===== SCHEDULE =====
    $schedules = Schedule::whereIn('user_id', $userIds)
        ->whereIn('date', $dateStrings)
        ->with(['assignment', 'absence'])
        ->get()
        ->groupBy('user_id');

    // ===== ATTENDANCE (PAKAI DATE) =====
    $attendances = Attendance::whereIn('user_id', $userIds)
        ->whereIn('date', $dateStrings)
        ->with(['assignment', 'overtimeLog.workAssignment'])
        ->get()
        ->groupBy('user_id');

    $result = [];

    foreach ($userIds as $userId) {

        $userSchedules = $schedules->get($userId, collect())
            ->keyBy(fn($s) => Carbon::parse($s->date)->format('Y-m-d'));

        $userAttendances = $attendances->get($userId, collect())
            ->keyBy(fn($a) => Carbon::parse($a->date)->format('Y-m-d'));

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

                if (in_array($schedule->absence->absence_type, ['S','I','C','A'])) {
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

            $isPastOrToday = $currentDate->lte($now);

            if (! $attendanceCodeStr) {
                if ($scheduleCode === 'o' && $isPastOrToday) {
                    $attendanceCodeStr = 'o';
                } else {
                    $attendanceCodeStr = '-';
                }
            }

            $history[] = [
                'date' => $currentDate->format('d'),
                'day_name' => $currentDate->translatedFormat('l'),
                'full_date' => $dateStr,
                'schedule_code' => $scheduleCode ?? '-',
                'attendance_code' => $attendanceCodeStr ?? '-',
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'status' => $statusStr,
            ];
        }

        $result[] = [
            'user_id' => $userId,
            'user_name' => $users[$userId]?->full_name ?? null,
            'summary' => $summary,
            'history' => $history,
        ];
    }

    return $result;
}

    public function memberTimesheet(Request $request, $userid)
    {
        $this->authorize('progress', Attendance::class);
        $userId = User::find($userid)->id;
        $validator = Validator::make($request->query(), [
            // 'current_time' => 'required|date_format:Y-m-d H:i:s',
            'project_id' => 'sometimes|integer',
            // 'user_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $user = Auth::user();
        $targetUser = User::find($userid);

        if (! $targetUser) {
            return response()->json([
                'message' => 'User tidak ditemukan.'
            ], 404);
        }

        // ================= PROJECT SCOPE =================
        if ($user->role === 'ho') {
            $projectId = (int) $request->query('project_id', 0);
            if ($projectId <= 0) {
                return response()->json(['message' => 'project_id wajib dikirim untuk HO.'], 422);
            }
        } else {
            $projectId = (int) ($user->project_id ?? 0);
            if ($projectId <= 0) {
                return response()->json(['message' => 'User tidak memiliki project.'], 422);
            }
        }

        if ((int) $targetUser->project_id !== $projectId && $user->role !== 'dev') {
            return response()->json(['message' => 'User target tidak berada dalam project Anda.'], 403);
        }

        $project = Project::with('organization')->find($projectId);
        if (! $project) {
            return response()->json(['message' => 'Project tidak ditemukan.'], 404);
        }

        // ================= TIMEZONE =================
        $timezone = $project->timezone ?? $project->organization->timezone ?? 'Asia/Jakarta';

$nowInProjectTz = now()->setTimezone($timezone);

        $startDate = $nowInProjectTz->copy()->startOfMonth();
        $endDate = $nowInProjectTz->copy()->endOfMonth();

        // ================= GET ATTENDANCE =================
        $attendanceRows = Attendance::where('user_id', $targetUser->id)
            ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->with(['user', 'assignment', 'overtimeLog.workAssignment'])
            ->get();

        // ================= GET USER IDS =================
        $userIds = collect([$targetUser->id]);

        if ($userIds->isEmpty()) {
            return response()->json([
                'success' => true,
'meta' => [
    'current_time' => $nowInProjectTz->format('Y-m-d H:i:s'),
    'timezone' => $timezone,
    'month' => $startDate->format('Y-m'),
],
                'data' => [],
            ]);
        }

        // ================= GET SCHEDULE =================
        $schedules = Schedule::whereIn('user_id', $userIds)
            ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->with('assignment')
            ->get()
            ->groupBy('user_id');

        // ================= GROUP BY USER =================
        $byUser = $attendanceRows->groupBy('user_id');

        $result = [];

        foreach ($byUser as $userId => $attendances) {

            $attendanceByDate = $attendances->keyBy(fn ($a) => \Carbon\Carbon::parse($a->date)->format('Y-m-d')
            );

            $userSchedules = $schedules->get($userId)?->keyBy(fn ($s) => \Carbon\Carbon::parse($s->date)->format('Y-m-d')
            ) ?? collect();

            $history = [];

            $summary = [
                'total_hari' => $startDate->daysInMonth,
                'schedule_kerja' => 0,
                'kode_kehadiran' => 0,
                'telat' => 0,
                'tidak_absen' => 0,
            ];

            for ($day = 1; $day <= $startDate->daysInMonth; $day++) {

                $currentDate = $startDate->copy()->day($day);
                $dateStr = $currentDate->toDateString();

                $attendance = $attendanceByDate->get($dateStr);
                $schedule = $userSchedules->get($dateStr);

                $scheduleCode = $schedule?->assignment?->code;
                $isOffSchedule = $schedule ? $schedule->assignment->isOffDuty() : true;

                $checkIn = '--:--';
                $checkOut = '--:--';
                $statusStr = '';
                $attendanceCodeStr = null;

                // ================= SUMMARY SCHEDULE =================
                if ($schedule && ! $isOffSchedule) {
                    $summary['schedule_kerja']++;
                }

                // ================= ADA ATTENDANCE =================
                if ($attendance) {
                    $summary['kode_kehadiran']++;

                    if ($isOffSchedule) {
                        $attendanceCodeStr = $attendance->overtimeLog?->workAssignment?->code
                            ?? $attendance->assignment?->code;
                    } else {
                        $attendanceCodeStr = $attendance->assignment?->code ?? $scheduleCode;
                    }

                    $checkIn = $attendance->check_in_at
                        ? $attendance->check_in_at->setTimezone($timezone)->format('H:i')
                        : '--:--';

                    $checkOut = $attendance->check_out_at
                        ? $attendance->check_out_at->setTimezone($timezone)->format('H:i')
                        : '--:--';

                    if (in_array($attendance->computed_status, [
                        'HADIR TELAT',
                        'HADIR TELAT LEMBUR',
                    ])) {
                        $statusStr = 'Telat';
                        $summary['telat']++;
                    } else {
                        $statusStr = 'Tepat Waktu';
                    }

                } elseif ($schedule && $schedule->absence) {
                    // ================= ADA ABSENCE =================
                    $statusStr = $schedule->absence->label;
                    $attendanceCodeStr = $schedule->absence->absence_type;
                    // Count absence as tidak_absen
                    if (in_array($schedule->absence->absence_type, ['S', 'I', 'C', 'A'])) {
                        $summary['tidak_absen']++;
                    }
                } else {
                    // ================= TIDAK ADA ATTENDANCE =================
                    if ($currentDate->greaterThanOrEqualTo($nowInProjectTz->copy()->startOfDay())) {
                        $statusStr = 'Belum Absen';
                    } else {
                        if ($schedule && ! $isOffSchedule) {
                            $statusStr = 'Tidak Absen';
                            $summary['tidak_absen']++;
                        } else {
                            $statusStr = 'Libur';
                        }
                    }
                }

                $isPastOrToday = $currentDate->lte($nowInProjectTz->copy()->startOfDay()); //ini tidak memakai $now

                if (! $attendanceCodeStr) {
                    if ($scheduleCode === 'o' && $isPastOrToday) {
                        $attendanceCodeStr = 'o';
                    } else {
                        $attendanceCodeStr = '-';
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
                'user_id' => (int) $userId,
                'user_name' => $attendances->first()->user?->full_name ?? 'Unknown',
                'summary' => $summary,
                'history' => $history,
            ];
        }

        // ================= SORT =================
        usort($result, fn ($a, $b) => strcmp($a['user_name'], $b['user_name']));

        return response()->json([
            'success' => true,
            'meta' => [
                'current_time' => $request->query('current_time'),
                'timezone' => $timezone,
                'month' => $startDate->format('Y-m'),
            ],
            'data' => $result,
        ]);
    }

    /**
     * Tampilkan foto selfie absensi (inline, untuk app dengan Bearer token).
     * GET /api/attendances/{attendance}/selfie-inline
     */
    public function inlineSelfiePhoto(Attendance $attendance)
    {
        $this->authorize('view', $attendance);

        $path = $attendance->selfie_photo_path;
        if (! $path || ! Storage::disk('public')->exists($path)) {
            return response()->json([
                'message' => 'File tidak ditemukan',
            ], 404);
        }

        return Storage::disk('public')->response($path, basename($path), [
            'Content-Disposition' => 'inline; filename="'.basename($path).'"',
        ]);
    }

    /**
     * SHOW attendance dengan assignment details
     * GET /attendances/{attendance}
     */
    public function show(Attendance $attendance)
    {
        // ========= AUTHORIZATION: User hanya bisa lihat attendance yang relevant
        $this->authorize('view', $attendance);

        $attendance->load('assignment', 'post', 'schedule');

        return response()->json([
            'data' => $this->formatAttendanceResponse($attendance),
        ]);
    }

    // Logic moved to AttendanceService for better encapsulation.

    /**
     * Format attendance response dengan assignment & post details
     * Response include semua info dari single schedule query:
     * - Assignment code (P/M/O), timing (start/end/grace_period)
     * - Post name dan type (mobile/static) untuk validation
     * - Status calculation (HADIR, HADIR TELAT, HADIR LEMBUR)
     */
    private function formatAttendanceResponse(Attendance $attendance)
    {
        $post = $attendance->post;
        $assignment = $attendance->assignment;
        $project = $attendance->project;
        $timezone = $project->timezone ?? $project->organization->timezone ?? 'Asia/Jakarta';

        $overtimeLog = null;
        $displayAssignment = $assignment;
        if ($assignment && $assignment->isOffDuty()) {
            $overtimeLog = $attendance->relationLoaded('overtimeLog')
                ? $attendance->overtimeLog
                : $attendance->overtimeLog()->with(['scheduledAssignment', 'workAssignment'])->first();
            if ($overtimeLog?->workAssignment) {
                // Untuk schedule OFF, assignment yang ditampilkan mengikuti work_assignment (mis. P/M)
                $displayAssignment = $overtimeLog->workAssignment;
            }
        }

        $dateFormatted = $attendance->date->translatedFormat('d F Y'); // e.g., "12 Februari 2026"

        // Convert check-in time to project timezone
        $checkInTime = $attendance->check_in_at?->setTimezone($timezone)->format('H:i:s');
        $checkOutTime = $attendance->check_out_at?->setTimezone($timezone)->format('H:i:s');

        return [
            'id' => $attendance->id,
            'user_id' => $attendance->user_id,
            'date' => $attendance->date->format('Y-m-d'),
            'date_formatted' => $dateFormatted,
            'timezone' => $timezone,
            'selfie_photo' => [
                'path' => $attendance->selfie_photo_path,
                'url' => $attendance->selfie_photo_path ? SignedMediaUrl::attendanceSelfie($attendance) : null,
                'storage_url_legacy' => $attendance->selfie_photo_path ? Storage::disk('public')->url($attendance->selfie_photo_path) : null,
                'api_inline_url' => $attendance->selfie_photo_path ? url('/api/attendances/'.$attendance->id.'/selfie-inline') : null,
            ],
            'schedule' => [
                'assignment' => [
                    'id' => $displayAssignment?->id,
                    'code' => $displayAssignment?->code,  // Untuk OFF akan tampil code work_assignment (mis. P/M)
                    'name' => $displayAssignment?->name,
                    'start_time' => $displayAssignment?->start_time,
                    'end_time' => $displayAssignment?->end_time,
                    'grace_period' => ($displayAssignment?->grace_period ?? 0).' minutes',
                    'is_off_duty' => $displayAssignment?->isOffDuty() ?? false,
                ],
                // 'scheduled_assignment' => $overtimeLog?->scheduledAssignment ? [
                //     'code' => $overtimeLog->scheduledAssignment->code,
                //     'name' => $overtimeLog->scheduledAssignment->name,
                // ] : null,
                // 'work_assignment' => $overtimeLog?->workAssignment ? [
                //     'code' => $overtimeLog->workAssignment->code,
                //     'name' => $overtimeLog->workAssignment->name,
                // ] : null,
                'post' => [
                    'id' => $post?->id,
                    'name' => $post?->name,
                    'type' => $post?->type,
                ],
                'project' => [
                    'id' => $project?->id,
                    'name' => $project?->name,
                ],
            ],
            'timing' => [
                'check_in_at' => $checkInTime,
                'check_out_at' => $checkOutTime,
                'late_minutes' => $attendance->late_minutes,
                'overtime_minutes' => $attendance->overtime_minutes,
            ],
            'status' => [
                'attendance_status' => $attendance->attendance_status,
                'computed_status' => $attendance->computed_status,  // HADIR | HADIR TELAT | HADIR LEMBUR | HADIR TELAT LEMBUR
                'overtime_status' => $attendance->overtime_status,  // NONE | PENDING | APPROVED
            ],
            'can_attend' => $this->getCanAttend($attendance),
        ];
    }

    /**
     * Determine if user can attend based on current state
     */
    private function getCanAttend(Attendance $attendance)
    {
        // Sudah check-in dan check-out = tidak bisa attend lagi
        if ($attendance->check_in_at && $attendance->check_out_at) {
            return false;
        }

        // Sudah check-in tapi belum check-out = bisa check-out
        if ($attendance->check_in_at && ! $attendance->check_out_at) {
            return false; // Sudah attend, waiting for check-out
        }

        // Belum check-in = bisa attend
        return true;
    }

    /**
     * GET ATTENDANCE SCANS
     * GET /api/attendances/{attendance}/scans
     * 
     * Menampilkan list patrol points yang harus di-scan dengan status
     * Support untuk both member (specific post) dan danru (all static posts)
     */
    public function getAttendanceScans(Request $request, Attendance $attendance)
    {
        // Authorization
        $this->authorize('viewScans', $attendance);

        $user = Auth::user();

        // Validate attendance is valid and checked-in
        if (!$attendance->check_in_at || $attendance->check_out_at) {
            return response()->json([
                'message' => 'Attendance tidak valid atau sudah check-out.',
            ], 400);
        }

        $project = $attendance->project;
        $projectTimezone = $project->timezone ?? $project->organization->timezone ?? 'Asia/Jakarta';

        // Get patrol points berdasarkan tipe user
        if ($attendance->isCommanderAttendance()) {
            // Danru: ambil semua static posts di project
            $posts = $project->posts()
                ->where('type', 'static')
                ->with('patrolPoints')
                ->orderBy('id')
                ->get();
        } else {
            // Member: ambil post spesifik yang dipilih
            if (!$attendance->post_id) {
                return response()->json([
                    'message' => 'Post belum dipilih.',
                ], 400);
            }
            $posts = $attendance->post ? collect([$attendance->post])->load('patrolPoints') : collect();
        }

        // Get scans yang sudah dilakukan
        $scans = PatrolScan::where('attendance_id', $attendance->id)
            ->with('qrCode.patrolPoint', 'photos')
            ->orderBy('scan_time')
            ->get();

        // Build response: patrol points dengan status
        $patrolPointsData = [];
        $totalPoints = 0;
        $scannedPoints = 0;

        foreach ($posts as $post) {
            foreach ($post->patrolPoints as $point) {
                $totalPoints++;

                // Cek apakah point ini sudah di-scan
                $pointScans = $scans->filter(function ($scan) use ($point) {
                    return $scan->qrCode?->patrolPoint?->id === $point->id;
                });

                $isScanned = $pointScans->isNotEmpty();
                if ($isScanned) $scannedPoints++;

                $patrolPointsData[] = [
                    'id' => $point->id,
                    'name' => $point->name,
                    'sequence_order' => $point->sequence_order,
                    'post_id' => $post->id,
                    'post_name' => $post->name,
                    'post_type' => $post->type,
                    'latitude' => (string) $point->latitude,
                    'longitude' => (string) $point->longitude,
                    'altitude' => (string) ($point->altitude ?? ''),
                    'radius' => (float) ($point->radius ?? 50),
                    'is_scanned' => $isScanned,
                    'scanned_count' => $pointScans->count(),
                    'last_scan_time' => $pointScans->last()?->scan_time,
                    'last_scan_user' => $pointScans->last()?->attendance?->user?->full_name,
                    'last_scan_note' => $pointScans->last()?->note,
                ];
            }
        }

        // Sort by post_id then sequence_order
        $patrolPointsData = collect($patrolPointsData)
            ->sortBy(['post_id', 'sequence_order'])
            ->values()
            ->toArray();

        return response()->json([
            'success' => true,
            'message' => 'Data patrol points berhasil diambil.',
            'data' => [
                'attendance_id' => $attendance->id,
                'user' => [
                    'id' => $attendance->user_id,
                    'name' => $attendance->user->full_name,
                    'role' => $attendance->user->role,
                ],
                'timezone' => $projectTimezone,
                'patrol_points' => $patrolPointsData,
                'progress' => [
                    'total_points' => $totalPoints,
                    'scanned_points' => $scannedPoints,
                    'remaining_points' => max(0, $totalPoints - $scannedPoints),
                    'percentage' => $totalPoints > 0 ? round(($scannedPoints / $totalPoints) * 100, 2) : 0,
                ],
            ],
        ], 200);
    }

  
    /**
    //  * CHECK QR CODE
    //  * POST /api/attendances/{attendance}/check-qr
    //  * 
    //  * Validate QR code sebelum scan
    //  * Response includes post_name dan patrol point details
    //  */
    // public function checkQrCode(Request $request, Attendance $attendance)
    // {
    //     $validator = Validator::make($request->all(), [
    //         'qr_code' => 'required|string',
    //     ]);

    //     if ($validator->fails()) {
    //         return response()->json($validator->errors(), 422);
    //     }

    //     $user = Auth::user();

    //     // Validate attendance
    //     if ($attendance->user_id !== $user->id && $user->role !== 'dev') {
    //         return response()->json(['message' => 'Unauthorized'], 403);
    //     }

    //     if (!$attendance->check_in_at || $attendance->check_out_at) {
    //         return response()->json([
    //             'message' => 'Attendance tidak valid atau sudah check-out.',
    //         ], 400);
    //     }

    //     // Validate QR code
    //     $patrolScanService = app(PatrolScanService::class);
    //     $validation = $patrolScanService->validateQrCode($request->qr_code, $attendance);

    //     if (!$validation['valid']) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => implode(', ', $validation['errors']),
    //             'errors' => $validation['errors'],
    //         ], 422);
    //     }

    //     // Get patrol point info dengan post name
    //     $qrCode = $validation['qr_code'];
    //     $patrolPoint = $qrCode->patrolPoint;
    //     $post = $patrolPoint?->post;

    //     // Check apakah sudah di-scan sebelumnya
    //     $previousScans = PatrolScan::where('attendance_id', $attendance->id)
    //         ->where('qr_code_id', $qrCode->id)
    //         ->count();

    //     return response()->json([
    //         'success' => true,
    //         'message' => 'QR code valid.',
    //         'data' => [
    //             'qr_code_id' => $qrCode->id,
    //             'qr_code' => $qrCode->code,
    //             'patrol_point' => [
    //                 'id' => $patrolPoint?->id,
    //                 'name' => $patrolPoint?->name,
    //                 'sequence_order' => $patrolPoint?->sequence_order,
    //                 'latitude' => (string) ($patrolPoint?->latitude ?? ''),
    //                 'longitude' => (string) ($patrolPoint?->longitude ?? ''),
    //                 'radius' => (float) ($patrolPoint?->radius ?? 50),
    //             ],
    //             'post' => [
    //                 'id' => $post?->id,
    //                 'name' => $post?->name,
    //                 'type' => $post?->type,
    //             ],
    //             'previous_scan_count' => $previousScans,
    //             'already_scanned' => $previousScans > 0,
    //         ],
    //     ], 200);
    // }

    /**
     * GET PATROL POINTS
     * GET /api/attendances/{attendance}/patrol-points
     * 
     * Menampilkan list patrol points yang harus di-scan dengan status
     * Support untuk both member (specific post) dan danru (all static posts)
     */

public function getPatrolPoints(Attendance $attendance)
{
    $this->authorize('view', $attendance);

    // 🔥 eager load biar ga N+1 + ambil QR aktif
    $attendance->load([
        'post.patrolPoints.activeQrCode',
        'user'
    ]);

    $points = $attendance->getPatrolPoints()->map(function ($point) {

        $qr = $point->activeQrCode; // ✅ ambil QR active

        return [
            'patrol_point_id' => $point->id,
            'name'            => $point->name,
            'qr_code'         => $qr?->code,
            'sequence'        => $point->sequence_order, // ⚠️ biasanya ini, bukan sequence
            'latitude'        => $point->latitude,
            'longitude'       => $point->longitude,
        ];
    });

    return response()->json([
        'success' => true,
        'data'    => $points,
    ]);
}
    /**
     * DOWNLOAD PROGRESS PDF
     * GET /api/attendances/{attendance}/progress/pdf
     * 
     * Download PDF progress patrol scan untuk attendance/assignment
     * Optional: download untuk date range specific atau session specific
     */
    public function downloadProgressPdf(Request $request, Attendance $attendance)
    {
        $this->authorize('downloadProgressPdf', $attendance);

        $validator = Validator::make($request->all(), [
            'snapshot_id' => 'nullable|integer|exists:attendance_progress_snapshots,id',
            'session_start' => 'nullable|date_format:Y-m-d H:i:s',
            'session_end' => 'nullable|date_format:Y-m-d H:i:s',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        // Check if attendance has passed the end_time of assignment
        if ($attendance->schedule) {
            // Tentukan assignment yang digunakan (overtime atau regular)
            $assignment = null;
            if ($attendance->overtimeLog && $attendance->overtimeLog->workAssignment) {
                $assignment = $attendance->overtimeLog->workAssignment;
            } elseif ($attendance->assignment) {
                $assignment = $attendance->assignment;
            }
            
            if ($assignment) {
                $projectTimezone = $attendance->project?->timezone 
                    ?? $attendance->project?->organization?->timezone 
                    ?? 'Asia/Jakarta';
                
                $schDate = $attendance->schedule->date instanceof \Carbon\Carbon 
                    ? $attendance->schedule->date->format('Y-m-d') 
                    : \Carbon\Carbon::parse($attendance->schedule->date)->format('Y-m-d');
                
                $end = Carbon::createFromFormat('Y-m-d H:i:s', $schDate.' '.$assignment->end_time, $projectTimezone);
                $nowInProjectTz = now($projectTimezone);
                
                // Jika sudah melewati end_time, return error
                if ($nowInProjectTz->greaterThanOrEqualTo($end)) {
                    return response()->json([
                        'message' => 'Sesi sudah berakhir, tidak dapat download progress.',
                        'end_time' => $end->toISOString(),
                    ], 422);
                }
            }
        }

        $pdfService = app(\App\Services\ProgressPdfExportService::class);

        try {
            if ($request->has('session_start') && $request->has('session_end')) {
                // Download untuk date range specific
                $sessionStart = Carbon::createFromFormat('Y-m-d H:i:s', $request->session_start);
                $sessionEnd = Carbon::createFromFormat('Y-m-d H:i:s', $request->session_end);
                return $pdfService->generateSessionProgressPdf($attendance, $sessionStart, $sessionEnd);
            } else {
                // Download untuk attendance (uses latest snapshot)
                if ($attendance->isCommanderAttendance()) {
                    $pdf = $pdfService->generateDanruProgressPdf($attendance);
                } else {
                    $pdf = $pdfService->generateProgressPdf($attendance);
                }
                return $pdf;
            }
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Gagal generate PDF',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
