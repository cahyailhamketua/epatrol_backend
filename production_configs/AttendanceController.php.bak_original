<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Absence;
use App\Models\Activity;
use App\Models\Assignment;
use App\Models\Attendance;
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
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class AttendanceController extends Controller
{
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
        if ($request->has('date')) {
            $query->where('date', $request->query('date'));
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
     * VALIDATE TIME BEFORE CHECK-IN
     * POST /api/attendances/validate-time
     * Validasi apakah user bisa check-in (waktu sudah tepat atau belum)
     * Sebelum user mengambil foto
     */
    public function validateCheckInTime(Request $request)
    {
        $user = Auth::user();

        $rules = [
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'current_time' => 'required|date_format:Y-m-d H:i:s',
        ];

        // Anggota wajib pilih post (dari projectnya sendiri).
        // Komandan regu dan admin_project tidak perlu memilih post (post_id disimpan NULL).
        if (! in_array($user->role, ['komandan_regu', 'admin_project'], true)) {
            $rules['post_id'] = 'sometimes|integer';
            $rules['post_type'] = 'required_without:post_id|string|in:static,mobile';
            $rules['post_name'] = 'required_without:post_id|string';
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $deviceDateTime = Carbon::createFromFormat('Y-m-d H:i:s', $request->current_time, 'Asia/Jakarta');
        $originalToday = $deviceDateTime->toDateString();

        // Mengambil target shift secara valid (mendukung shift lintas malam)
        [$schedule, $today] = $this->resolveActiveScheduleForCheckIn($user, $request->current_time);

        if (! $schedule) {
            return response()->json([
                'message' => 'Anda tidak memiliki jadwal hari ini.',
                'date' => $originalToday,
            ], 403);
        }

        // Determine post (dari project user)
        $post = null;
        if (! in_array($user->role, ['komandan_regu', 'admin_project'], true)) {
            if ($request->filled('post_id')) {
                $post = Post::where('id', (int) $request->post_id)
                    ->where('project_id', $user->project_id)
                    ->first();
            } else {
                $post = Post::where('type', $request->post_type)
                    ->where('name', $request->post_name)
                    ->where('project_id', $user->project_id)
                    ->first();
            }

            if (! $post) {
                return response()->json([
                    'message' => 'Pos tidak ditemukan. Pastikan pos tersebut milik project Anda.',
                    'post_id' => $request->post_id ? (int) $request->post_id : null,
                    'post_type' => $request->post_type ?? null,
                    'post_name' => $request->post_name ?? null,
                ], 404);
            }
        }

        $assignment = $schedule->assignment;
        $project = $schedule->project;

        // GUARD: 1 post hanya boleh ditempati 1 anggota (untuk assignment pada hari tsb)
        if (! in_array($user->role, ['komandan_regu', 'admin_project'], true) && $post) {
            $postAlreadyUsed = Attendance::where('post_id', $post->id)
                ->where('date', $today)
                ->where('assignment_id', $assignment->id)
                ->whereNotNull('check_in_at')
                ->where('user_id', '!=', $user->id)
                ->exists();

            if ($postAlreadyUsed) {
                return response()->json([
                    'message' => 'Pos ini sudah digunakan oleh anggota lain untuk jadwal ini.',
                    'post_id' => $post->id,
                ], 409);
            }
        }

        $projectTimezone = $project->timezone ?? $project->organization->timezone ?? 'Asia/Jakarta';
        $now = Carbon::createFromFormat('Y-m-d H:i:s', $request->current_time, $projectTimezone);
        $now->setTimezone('UTC');

        // Check location
        if (! $project || ! $project->location_latitude || ! $project->location_longitude) {
            return response()->json([
                'message' => 'Project tidak memiliki data lokasi. Hubungi administrator.',
                'project_id' => $schedule->project_id,
            ], 403);
        }

        $globalRadius = (float) ($project->radius ?? 100);
        $deviceLatitude = (float) $request->latitude;
        $deviceLongitude = (float) $request->longitude;
        $referenceLatitude = (float) $project->location_latitude;
        $referenceLongitude = (float) $project->location_longitude;

        $distance = $this->calculateDistance(
            $referenceLatitude,
            $referenceLongitude,
            $deviceLatitude,
            $deviceLongitude
        );

        if ($distance > $globalRadius) {
            return response()->json([
                'message' => 'Anda berada di luar radius absen masuk.',
                'your_location' => [
                    'latitude' => round($deviceLatitude, 6),
                    'longitude' => round($deviceLongitude, 6),
                ],
                'reference_location' => [
                    'latitude' => round($referenceLatitude, 6),
                    'longitude' => round($referenceLongitude, 6),
                ],
                'distance' => round($distance, 2).' meters',
                'allowed_radius' => $globalRadius.' meters',
            ], 403);
        }

        // Check time (Assignment O/OFF: bypass schedule time validation)
        $lateMinutes = 0;
        $computedStatus = 'HADIR';

        if (! $assignment->isOffDuty()) {
            $gracePeriod = $assignment->grace_period ?? 15;
            $startTime = Carbon::createFromFormat('Y-m-d H:i:s', $today.' '.$assignment->start_time, $projectTimezone);
            $startTime->setTimezone('UTC');
            $graceDeadline = $startTime->copy()->addMinutes($gracePeriod);

            if ($now->isBefore($startTime)) {
                $nowInProjectTz = $now->copy()->setTimezone($projectTimezone);
                $startTimeInProjectTz = $startTime->copy()->setTimezone($projectTimezone);

                return response()->json([
                    'message' => 'Belum waktunya absen masuk.',
                    'assignment' => [
                        'code' => $assignment->code,
                        'start_time' => $startTimeInProjectTz->format('H:i:s'),
                    ],
                    'your_time' => $nowInProjectTz->format('H:i:s'),
                    'wait_until' => $startTimeInProjectTz->format('H:i:s'),
                    'timezone' => $projectTimezone,
                ], 403);
            }

            if ($now->isAfter($graceDeadline)) {
                $lateMinutes = $now->diffInMinutes($startTime);
                $computedStatus = 'HADIR TELAT';
            }
        }

        if ($assignment->isOffDuty()) {
            $offDayOvertime = app(OffDayOvertimeService::class);
            $workInterval = $offDayOvertime->resolveWorkAssignmentByTime(
                $schedule->project_id,
                $now->copy()->setTimezone($projectTimezone)
            );
            $workAssignment = $workInterval['assignment'] ?? null;

            if (! $workAssignment) {
                return response()->json([
                    'message' => 'Jadwal hari ini OFF. Tidak ada assignment kerja yang cocok dengan jam sekarang di project ini.',
                    'time' => $now->copy()->setTimezone($projectTimezone)->format('H:i:s'),
                    'timezone' => $projectTimezone,
                ], 403);
            }

            $computedStatus = 'HADIR LEMBUR';
            $workGracePeriod = $workAssignment->grace_period ?? 15;
            $workStartUtc = $workInterval['start']->copy()->setTimezone('UTC');
            $graceDeadlineUtc = $workStartUtc->copy()->addMinutes($workGracePeriod);

            if ($now->isAfter($graceDeadlineUtc)) {
                $lateMinutes = $now->diffInMinutes($workStartUtc);
                $computedStatus = 'HADIR TELAT LEMBUR';
            }
        }

        $todayCarbon = Carbon::parse($today);
        $dateFormatted = $todayCarbon->translatedFormat('d F Y');
        $nowInProjectTz = $now->copy()->setTimezone($projectTimezone);

        $isOffDay = $assignment->isOffDuty();

        return response()->json([
            'message' => $user->role === 'admin_project'
                ? 'Waktu check-in valid. Silakan check-in.'
                : 'Waktu check-in valid. Silakan ambil foto selfie.',
            'can_checkin' => true,
            'date' => $today,
            'date_formatted' => $dateFormatted,
            'time' => $nowInProjectTz->format('H:i:s'),
            'timezone' => $projectTimezone,
            'status' => $computedStatus,
            'late_minutes' => $lateMinutes,
            'distance' => round($distance, 2).' meters',
            'allowed_radius' => $globalRadius.' meters',
            'is_off_day' => $isOffDay,
            // overtime_work_code untuk OFF dipilih otomatis berdasarkan current_time
            'requires_overtime_work_code' => false,
            'post' => $post ? [
                'id' => $post->id,
                'name' => $post->name,
                'type' => $post->type,
            ] : null,
        ], 200);
    }

    public function checkIn(Request $request)
    {
        // ========= AUTHORIZATION: User harus bisa create attendance
        $this->authorize('create', Attendance::class);

        $user = Auth::user();

        $rules = [
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'current_time' => 'required|date_format:Y-m-d H:i:s', // Device time
        ];

        // Komandan regu & anggota wajib selfie.
        // Admin project check-in tanpa selfie.
        $selfieRequired = in_array($user->role, ['komandan_regu', 'anggota'], true);
        $rules['selfie_photo'] = $selfieRequired ? 'required|image|max:1024' : 'sometimes|image|max:1024';

        // Anggota wajib pilih post (dari projectnya sendiri).
        // Komandan regu & admin_project tidak perlu memilih post (post_id disimpan NULL).
        if (! in_array($user->role, ['komandan_regu', 'admin_project'], true)) {
            $rules['post_id'] = 'sometimes|integer';
            $rules['post_type'] = 'required_without:post_id|string|in:static,mobile';
            $rules['post_name'] = 'required_without:post_id|string';
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        // PENTING: Resolve schedule dan shift timezone independent via helper (support midnight shift)
        $deviceDateTime = Carbon::createFromFormat('Y-m-d H:i:s', $request->current_time, 'Asia/Jakarta');
        $originalToday = $deviceDateTime->toDateString();

        [$schedule, $today] = $this->resolveActiveScheduleForCheckIn($user, $request->current_time);

        if (! $schedule) {
            return response()->json([
                'message' => 'Anda tidak memiliki jadwal hari ini.',
                'date' => $originalToday,
            ], 403);
        }

        $todayCarbon = Carbon::parse($today);

        // GUARD 0: Cari attendance aktif (belum check-out) berdasarkan user_id.
        // Ini memastikan server-side check lintas device (tidak bergantung shared preference mobile).
        $activeUnclosedAttendance = Attendance::where('user_id', $user->id)
            ->whereNotNull('check_in_at')
            ->whereNull('check_out_at')
            ->orderByDesc('check_in_at')
            ->first();

        // Jika ada attendance aktif di tanggal berbeda (bukan tanggal shift berjalan), wajib check-out dulu.
        if ($activeUnclosedAttendance && $activeUnclosedAttendance->date->format('Y-m-d') !== $today) {
            $activeDate = $activeUnclosedAttendance->date->translatedFormat('d F Y');

            return response()->json([
                'message' => 'Anda masih memiliki attendance aktif yang belum di-close.',
                'info' => 'Absen '.$activeDate.' belum check-out. Silakan check-out terlebih dahulu.',
                'unclosed_attendance' => [
                    'id' => $activeUnclosedAttendance->id,
                    'date' => $activeUnclosedAttendance->date->format('Y-m-d'),
                    'check_in_at' => $activeUnclosedAttendance->check_in_at->format('H:i:s'),
                ],
            ], 403);
        }

        // Jika attendance aktif ada di shift yang sama, request check-in dianggap EDIT.
        $existingAttendance = $activeUnclosedAttendance
            && $activeUnclosedAttendance->date->format('Y-m-d') === $today
            ? $activeUnclosedAttendance
            : null;

        // Determine post (dari project user)
        // Komandan regu & admin_project tidak perlu post_id.
        $post = null;
        if (! in_array($user->role, ['komandan_regu', 'admin_project'], true)) {
            if ($request->filled('post_id')) {
                $post = Post::where('id', (int) $request->post_id)
                    ->where('project_id', $user->project_id)
                    ->first();
            } else {
                // User memilih post berdasarkan type dan name dari daftar project
                $post = Post::where('type', $request->post_type)
                    ->where('name', $request->post_name)
                    ->where('project_id', $user->project_id)
                    ->first();
            }

            if (! $post) {
                return response()->json([
                    'message' => 'Pos tidak ditemukan. Pastikan pos tersebut milik project Anda.',
                    'post_id' => $request->post_id ? (int) $request->post_id : null,
                    'post_type' => $request->post_type ?? null,
                    'post_name' => $request->post_name ?? null,
                ], 404);
            }
        }

        // Unpack semua data yang diperlukan
        $assignment = $schedule->assignment;
        $project = $schedule->project;

        // GUARD: 1 post hanya boleh ditempati 1 anggota (untuk assignment pada hari tsb)
        if (! in_array($user->role, ['komandan_regu', 'admin_project'], true) && $post) {
            $postAlreadyUsed = Attendance::where('post_id', $post->id)
                ->where('date', $today)
                ->where('assignment_id', $assignment->id)
                ->whereNotNull('check_in_at')
                ->where('user_id', '!=', $user->id)
                ->exists();

            if ($postAlreadyUsed) {
                return response()->json([
                    'message' => 'Pos ini sudah digunakan oleh anggota lain untuk jadwal ini.',
                    'post_id' => $post->id,
                ], 409);
            }
        }

        // NOW: Parse device time dalam timezone PROJECT
        $projectTimezone = $project->timezone ?? $project->organization->timezone ?? 'Asia/Jakarta';
        $now = Carbon::createFromFormat('Y-m-d H:i:s', $request->current_time, $projectTimezone);
        // Convert ke UTC untuk internal logic
        $now->setTimezone('UTC');

        // GUARD: Project harus memiliki location
        if (! $project || ! $project->location_latitude || ! $project->location_longitude) {
            return response()->json([
                'message' => 'Project tidak memiliki data lokasi. Hubungi administrator.',
                'project_id' => $schedule->project_id,
            ], 403);
        }

        // GUARD 3: Cegah attendance jika ada keterangan absence pada schedule ini
        $dayAbsence = Absence::where('schedule_id', $schedule->id)->first();

        if ($dayAbsence) {
            return response()->json([
                'message' => 'Hari ini tercatat '.$dayAbsence->label.'. Tidak dapat absen masuk.',
                'absence_type' => $dayAbsence->absence_type,
            ], 403);
        }

        $isOffDutyAssignment = $assignment->isOffDuty(); // termasuk code 'O'

        $offDayOvertime = app(OffDayOvertimeService::class);
        $workAssignment = null;
        $workAssignmentInterval = null;
        if ($isOffDutyAssignment) {
            // AUTO pick assignment kerja berdasarkan waktu device (project timezone)
            $nowInProjectTz = $now->copy()->setTimezone($projectTimezone);
            $workAssignmentInterval = $offDayOvertime->resolveWorkAssignmentByTime($schedule->project_id, $nowInProjectTz);
            $workAssignment = $workAssignmentInterval['assignment'] ?? null;

            if (! $workAssignment) {
                return response()->json([
                    'message' => 'Jadwal hari ini OFF. Tidak ada assignment kerja yang cocok dengan jam sekarang di project ini.',
                    'time' => $nowInProjectTz->format('H:i:s'),
                    'timezone' => $projectTimezone,
                ], 403);
            }
        }
        $startTime = null;
        if (! $isOffDutyAssignment) {
            $startTime = Carbon::createFromFormat('Y-m-d H:i:s', $today.' '.$assignment->start_time, $projectTimezone);
            $startTime->setTimezone('UTC');
        }

        // ========== LOCATION VERIFICATION ===========
        // Reference location: Diambil dari project (fixed office location)
        // Device location: Dikirim dari HP/Laptop user saat check-in (dynamic)
        // Kalkulasi: Jarak antara reference location dan device location harus <= radius
        //
        // Location logic:
        // - PROJECT location: Fixed reference point (dari database)
        // - POST type: Determine jika ada special location rules (mobile/static)
        // - DEVICE location: Current user position dari HP/request

        $globalRadius = (float) ($project->radius ?? 5); // Radius project (default 100m)

        // DEVICE location (dari HP/Laptop user saat check-in)
        $deviceLatitude = (float) $request->latitude;
        $deviceLongitude = (float) $request->longitude;

        // REFERENCE location: Coming from PROJECT (fixed office location)
        $referenceLatitude = (float) $project->location_latitude;
        $referenceLongitude = (float) $project->location_longitude;
        $locationType = 'project'; // Always project location

        // Hitung jarak menggunakan Haversine formula
        $distance = $this->calculateDistance(
            $referenceLatitude,
            $referenceLongitude,
            $deviceLatitude,
            $deviceLongitude
        );

        if ($distance > $globalRadius) {
            return response()->json([
                'message' => 'Anda berada di luar radius absen masuk.',
                'your_location' => [
                    'latitude' => round($deviceLatitude, 6),
                    'longitude' => round($deviceLongitude, 6),
                ],
                'reference_location' => [
                    'type' => $locationType,
                    'latitude' => round($referenceLatitude, 6),
                    'longitude' => round($referenceLongitude, 6),
                ],
                'distance' => round($distance, 2).' meters',
                'allowed_radius' => $globalRadius.' meters',
            ], 403);
        }

        // ========== TIME VERIFICATION ==========
        // Assignment time: Tersimpan di database (start_time, end_time, grace_period)
        // Device time: Dikirim dari HP/Laptop user (current_time)
        // Kalkulasi:
        // - Jika now < start_time → reject (belum waktunya)
        // - Jika start_time <= now <= start_time + grace_period → HADIR (on time)
        // - Jika now > start_time + grace_period → HADIR TELAT (late, can still check-in)

        $lateMinutes = 0;
        $attendanceStatus = 'HADIR';
        $computedStatus = 'HADIR';

        // Assignment O/OFF: tidak perlu sesuai schedule untuk check-in time
        if (! $isOffDutyAssignment) {
            $gracePeriod = $assignment->grace_period ?? 15;
            $endTime = Carbon::createFromFormat('Y-m-d H:i:s', $today.' '.$assignment->end_time, $projectTimezone);
            $endTime->setTimezone('UTC');

            // HANDLE MIDNIGHT SHIFT: Jika end_time <= start_time, artinya shift melintasi tengah malam
            if ($endTime->lessThanOrEqualTo($startTime)) {
                $endTime->addDay();
            }

            // Untuk perbandingan logic, gunakan COPY - jangan ubah original!
            $startTimeForComparison = $startTime->copy();
            $graceDeadlineForComparison = $startTimeForComparison->copy()->addMinutes($gracePeriod);

            if ($now->isBefore($startTimeForComparison)) {
                // Belum saatnya check-in (terlalu pagi)
                $nowInProjectTz = $now->copy()->setTimezone($projectTimezone);
                $startTimeInProjectTz = $startTime->copy()->setTimezone($projectTimezone);  // ← Use ORIGINAL!

                return response()->json([
                    'message' => 'Belum waktunya absen masuk.',
                    'assignment' => [
                        'code' => $assignment->code,
                        'start_time' => $startTimeInProjectTz->format('H:i:s'),  // ← Tetap original
                    ],
                    'your_time' => $nowInProjectTz->format('H:i:s'),
                    'wait_until' => $startTimeInProjectTz->format('H:i:s'),
                    'timezone' => $projectTimezone,
                ], 403);
            } elseif ($now->isAfter($graceDeadlineForComparison)) {
                // Telat, tapi masih bisa check-in (tidak ada absolute deadline)
                $lateMinutes = $now->diffInMinutes($startTime);
                $attendanceStatus = 'HADIR TELAT';
                $computedStatus = 'HADIR TELAT';
            }
        }

        // Untuk OFF day overtime, status & late dihitung dari assignment kerja yang dipilih otomatis.
        if ($isOffDutyAssignment) {
            $workGracePeriod = $workAssignment->grace_period ?? 15;

            // start/end interval dalam project tz -> konversi ke UTC untuk hitung
            $workStartUtc = $workAssignmentInterval['start']->copy()->setTimezone('UTC');

            $graceDeadlineUtc = $workStartUtc->copy()->addMinutes($workGracePeriod);

            if ($now->isAfter($graceDeadlineUtc)) {
                $lateMinutes = $now->diffInMinutes($workStartUtc);
                $attendanceStatus = 'HADIR TELAT';
                $computedStatus = 'HADIR TELAT LEMBUR';
            } else {
                $computedStatus = 'HADIR LEMBUR';
            }
        }

        // Handle selfie photo upload (admin_project tidak perlu selfie)
        $selfiePath = null;
        if ($request->hasFile('selfie_photo')) {
            $imageService = app(ImageWebpService::class);
            $selfiePath = $imageService->storeAsWebp($request->file('selfie_photo'), 'attendances/selfies', 80);
        }

        $attendance = DB::transaction(function () use (
            $schedule,
            $assignment,
            $user,
            $post,
            $today,
            $now,
            $request,
            $attendanceStatus,
            $computedStatus,
            $lateMinutes,
            $selfiePath,
            $isOffDutyAssignment,
            $workAssignment,
            $offDayOvertime,
            $existingAttendance
        ) {
            if ($existingAttendance && ! $existingAttendance->check_out_at) {
                // EDIT check-in yang sudah ada (hari yang sama)
                $existingAttendance->project_id = $schedule->project_id;
                $existingAttendance->schedule_id = $schedule->id;
                $existingAttendance->assignment_id = $assignment->id;
                $existingAttendance->post_id = $post?->id;
                $existingAttendance->date = $today;
                $existingAttendance->check_in_at = $now;
                $existingAttendance->checkin_lat = $request->latitude;
                $existingAttendance->checkin_lng = $request->longitude;
                $existingAttendance->attendance_status = $attendanceStatus;
                $existingAttendance->computed_status = $computedStatus;
                $existingAttendance->late_minutes = $lateMinutes;
                $existingAttendance->overtime_minutes = 0;
                $existingAttendance->overtime_status = ($isOffDutyAssignment && $workAssignment) ? 'APPROVED' : 'NONE';

                if ($selfiePath) {
                    $existingAttendance->selfie_photo_path = $selfiePath;
                }

                $existingAttendance->save();

                if ($isOffDutyAssignment && $workAssignment) {
                    $offDayOvertime->createFromCheckIn($schedule, $existingAttendance, $workAssignment);
                }

                return $existingAttendance;
            }

            $created = Attendance::create([
                'project_id' => $schedule->project_id,
                'user_id' => $user->id,
                'schedule_id' => $schedule->id,
                'assignment_id' => $assignment->id,
                'post_id' => $post?->id,
                'date' => $today,
                'check_in_at' => $now,
                'checkin_lat' => $request->latitude,
                'checkin_lng' => $request->longitude,
                'attendance_status' => $attendanceStatus,
                'computed_status' => $computedStatus,
                'late_minutes' => $lateMinutes,
                'overtime_minutes' => 0,
                'overtime_status' => ($isOffDutyAssignment && $workAssignment) ? 'APPROVED' : 'NONE',
                'selfie_photo_path' => $selfiePath,
            ]);

            if ($isOffDutyAssignment && $workAssignment) {
                $offDayOvertime->createFromCheckIn($schedule, $created, $workAssignment);
            }

            return $created;
        });

        $dateFormatted = $todayCarbon->translatedFormat('d F Y'); // e.g., "13 Februari 2026" (device date!)
        $nowInProjectTz = $now->copy()->setTimezone($projectTimezone);

        // Determine status dan late info
        $lateInfo = '';
        if ($attendance->computed_status === 'HADIR TELAT') {
            $lateInfo = ' (Telat '.$attendance->late_minutes.' menit)';
        }

        $isEdit = (bool) ($existingAttendance && (int) $existingAttendance->id === (int) $attendance->id);

        return response()->json([
            'message' => $isEdit ? 'Check-in berhasil diperbarui.' : 'Absen masuk berhasil.',
            'info' => 'Ini tanggal '.$dateFormatted.$lateInfo,
            'date' => $today,
            'time' => $nowInProjectTz->format('H:i:s'),
            'timezone' => $projectTimezone,
            'status' => $attendance->computed_status,
            'late_minutes' => (int) $attendance->late_minutes,
            'data' => $this->formatAttendanceResponse($attendance),
        ], $isEdit ? 200 : 201);
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

        $this->authorize('progress', Attendance::class);

        $user = Auth::user();
        if (! $user->project_id) {
            return response()->json([
                'message' => 'User tidak memiliki project.',
            ], 422);
        }

        $project = $user->project()->with('organization')->first();
        $projectTimezone = $project?->timezone ?? $project?->organization?->timezone ?? 'Asia/Jakarta';

        $rules = [
            'current_time' => 'sometimes|date_format:Y-m-d H:i:s',
            'attendance_id' => 'sometimes|integer',
        ];
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $nowInProjectTz = $request->filled('current_time')
            ? Carbon::createFromFormat('Y-m-d H:i:s', $request->current_time, $projectTimezone)
            : now($projectTimezone);

        $today = $nowInProjectTz->toDateString();

        // KHUSUS KOMANDAN REGU (DANRU):
        // - Wajib punya attendance (check-in) agar bisa melihat progress.
        // - Wajib kirim attendance_id miliknya sendiri (supaya tidak bisa "nebak" attendance orang lain).
        $danruAttendance = null;
        if ($user->role === 'komandan_regu') {
            if (! $request->filled('attendance_id')) {
                return response()->json([
                    'message' => 'Attendance tidak valid',
                ], 422);
            }

            $danruAttendance = Attendance::where('id', (int) $request->attendance_id)
                ->where('user_id', $user->id)
                ->with(['schedule.assignment', 'project.organization'])
                ->first();

            if (! $danruAttendance) {
                return response()->json([
                    'message' => 'Attendance tidak valid',
                ], 404);
            }

            if (! $danruAttendance->check_in_at) {
                return response()->json([
                    'message' => 'Attendance tidak valid',
                ], 400);
            }

            if ($danruAttendance->check_out_at) {
                return response()->json([
                    'message' => 'Attendance tidak valid',
                ], 400);
            }

            // Pastikan attendance yang dipakai adalah untuk hari yang sama (device date)
            if ($danruAttendance->date->toDateString() !== $today) {
                return response()->json([
                    'message' => 'Attendance tidak valid',
                ], 422);
            }
        }

        // Ambil semua schedule project untuk hari ini (beserta assignment+user+post)
        $schedulesToday = Schedule::where('project_id', $user->project_id)
            ->where('date', $today)
            ->with(['assignment', 'user'])
            ->get();

        // Filter schedule berdasarkan assignment yang sedang aktif saat ini (time-only compare memakai tanggal hari ini)
        $activeSchedules = $schedulesToday->filter(function ($schedule) use ($nowInProjectTz, $today, $projectTimezone) {
            if (! $schedule->assignment) {
                return false;
            }

            $start = Carbon::createFromFormat('Y-m-d H:i:s', $today.' '.$schedule->assignment->start_time, $projectTimezone);
            $end = Carbon::createFromFormat('Y-m-d H:i:s', $today.' '.$schedule->assignment->end_time, $projectTimezone);

            // Handle midnight shift: end <= start berarti melewati tengah malam
            if ($end->lessThanOrEqualTo($start)) {
                $end->addDay();
            }

            // Jika now sebelum start tapi shift midnight, bisa jadi now berada di hari berikutnya (mis: jam 01:00)
            $now = $nowInProjectTz->copy();
            if ($end->greaterThan($start) && $now->lessThan($start) && $end->diffInHours($start) > 12) {
                // heuristik ringan: bila rentang besar dan now < start, anggap now ada di "hari berikutnya" shift midnight
                $now->addDay();
            }

            return $now->greaterThanOrEqualTo($start) && $now->lessThan($end);
        })->values();

        if ($activeSchedules->isEmpty()) {
            return response()->json([
                'message' => 'Tidak ada assignment aktif saat ini.',
                'project_id' => (int) $user->project_id,
                'date' => $today,
                'time' => $nowInProjectTz->format('H:i:s'),
                'timezone' => $projectTimezone,
                'assignment' => null,
                'progress' => [
                    'total' => 0,
                    'checked_in' => 0,
                    'not_checked_in' => 0,
                    'percentage' => 0,
                ],
                'members' => [],
            ], 200);
        }

        // Asumsi utama: pada satu waktu hanya ada 1 assignment aktif dalam project.
        // Jika ada lebih dari 1, kita ambil assignment pertama dan batasi ke assignment itu.
        $activeAssignmentId = $activeSchedules->first()?->assignment_id;
        if ($activeAssignmentId) {
            $activeSchedules = $activeSchedules->where('assignment_id', $activeAssignmentId)->values();
        }
        $activeAssignment = $activeSchedules->first()->assignment;

        // Danru tidak boleh melihat progress danru lain.
        // Tetap boleh melihat anggota. Untuk dirinya sendiri, tetap ditampilkan (agar tahu statusnya).
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

        // Ambil attendance untuk schedule active (hari ini)
        $attendances = Attendance::whereIn('schedule_id', $scheduleIds)
            ->where('date', $today)
            ->with(['user', 'post', 'schedule.assignment', 'project.organization'])
            ->get()
            ->keyBy('schedule_id');

        $patrolService = app(PatrolScanService::class);

        // Top-level ishoma activities per post
        $postIds = $attendances->pluck('post_id')->filter()->unique()->values();
        $ishomaActivitiesByPost = Activity::where('active', true)
            ->where('name', 'like', '%ishoma%')
            ->whereIn('post_id', $postIds->all())
            ->with('assignmentTimes.assignment')
            ->get()
            ->groupBy('post_id');

        $members = $activeSchedules->map(function ($schedule) use ($attendances, $patrolService, $ishomaActivitiesByPost) {
            $attendance = $attendances->get($schedule->id);

            $scanProgress = null;
            if ($attendance && $attendance->check_in_at) {
                $scanProgress = $patrolService->getScanProgress($attendance);
            }

            return [
                'user' => [
                    'id' => (int) $schedule->user_id,
                    'full_name' => $schedule->user?->full_name,
                    'role' => $schedule->user?->role,
                ],
                'schedule' => [
                    'id' => (int) $schedule->id,
                ],
                'post' => $attendance ? [
                    'post_id' => (int) $attendance->post_id,
                    'name' => $attendance->post?->name,
                    'type' => $attendance->post?->type,
                ] : null,
                'attendance' => $attendance ? [
                    'id' => (int) $attendance->id,
                    'post_id' => (int) $attendance->post_id,
                    'post_name' => $attendance->post?->name,
                    'post_type' => $attendance->post?->type,
                    'check_in_at' => $attendance->check_in_at?->toISOString(),
                    'check_out_at' => $attendance->check_out_at?->toISOString(),
                    'computed_status' => $attendance->computed_status,
                ] : null,
                'checkin_status' => ($attendance && $attendance->check_in_at) ? 'CHECKED_IN' : 'NOT_YET',
                'scan_progress' => $scanProgress,
                'timesheet' => $attendance ? [
                    'check_in_at' => $attendance->check_in_at?->toISOString(),
                    'check_out_at' => $attendance->check_out_at?->toISOString(),
                    'computed_status' => $attendance->computed_status,
                    'work_duration_minutes' => ($attendance->check_in_at && $attendance->check_out_at)
                        ? $attendance->check_in_at->diffInMinutes($attendance->check_out_at)
                        : null,
                ] : null,
                'ishoma_activities' => $attendance && $attendance->post_id
                    ? ($ishomaActivitiesByPost[$attendance->post_id] ?? collect())->map(function ($activity) {
                        return [
                            'id' => $activity->id,
                            'name' => $activity->name,
                            'location' => $activity->location,
                            'assignment_times' => $activity->assignmentTimes->map(function ($t) {
                                return [
                                    'id' => $t->id,
                                    'assignment_id' => $t->assignment_id,
                                    'assignment_name' => $t->assignment?->name,
                                    'start_time' => $t->start_time,
                                    'end_time' => $t->end_time,
                                ];
                            }),
                        ];
                    })
                    : collect(),
            ];
        })->values();

        // Hanya tampilkan anggota yang sudah benar-benar memiliki attendance (sudah check-in).
        $members = $members->filter(function ($member) {
            return $member['attendance'] !== null && $member['attendance']['check_in_at'] !== null;
        })->values();

        $total = $activeSchedules->count();
        $checkedIn = $members->count();
        $notCheckedIn = $total - $checkedIn;
        $percentage = $total > 0 ? round(($checkedIn / $total) * 100, 2) : 0;

        return response()->json([
            'message' => 'Progress assignment aktif berhasil diambil.',
            'project_id' => (int) $user->project_id,
            'date' => $today,
            'time' => $nowInProjectTz->format('H:i:s'),
            'timezone' => $projectTimezone,
            'assignment' => $activeAssignment ? [
                'id' => (int) $activeAssignment->id,
                'name' => $activeAssignment->name,
                'start_time' => $activeAssignment->start_time,
                'end_time' => $activeAssignment->end_time,
            ] : null,
            'progress' => [
                'total' => $total,
                'checked_in' => $checkedIn,
                'not_checked_in' => $notCheckedIn,
                'percentage' => $percentage,
            ],
            'members' => $members,
        ], 200);
    }

    /**
     * ini yang bener
     * Post progress detail - by attendance (danru/admin_lapang/ho)
     * GET /api/attendance/{attendance}/progress-post-detail
     * Ambil assignment_id dari user (anggota) yang checkin di post
     */
    // public function progressPostDetailByAttendance(Request $request)
    // {
    //     $user = Auth::user();

    //     if ($user->role === 'ho') {
    //         $postId = $request->input('post_id');
    //         if (!$postId) {
    //             return response()->json(['message' => 'post_id wajib untuk HO.'], 422);
    //         }

    //         $post = Post::findOrFail($postId);
    //         $project = $post->project;
    //         $projectTimezone = $project->timezone ?? $project->organization->timezone ?? 'Asia/Jakarta';
    //         $nowInProjectTz = $request->filled('current_time')
    //             ? Carbon::createFromFormat('Y-m-d H:i:s', $request->current_time, $projectTimezone)
    //             : now($projectTimezone);

    //         $today = $nowInProjectTz->toDateString();

    //         // Cari assignment aktif berdasarkan current_time
    //         $assignments = Assignment::where('post_id', $postId)->get();
    //         $activeAssignment = null;
    //         foreach ($assignments as $assignment) {
    //             $start = Carbon::createFromFormat('Y-m-d H:i:s', $today.' '.$assignment->start_time, $projectTimezone);
    //             $end = Carbon::createFromFormat('Y-m-d H:i:s', $today.' '.$assignment->end_time, $projectTimezone);
    //             if ($end->lessThanOrEqualTo($start)) {
    //                 $end->addDay();
    //             }
    //             if ($nowInProjectTz->between($start, $end)) {
    //                 $activeAssignment = $assignment;
    //                 break;
    //             }
    //         }

    //         if (!$activeAssignment) {
    //             return response()->json(['message' => 'Tidak ada assignment aktif di post ini pada waktu sekarang.'], 422);
    //         }

    //         // Cari schedule hari ini untuk assignment aktif
    //         $schedule = Schedule::where('project_id', $project->id)
    //             ->where('date', $today)
    //             ->where('assignment_id', $activeAssignment->id)
    //             ->with(['assignment', 'user'])
    //             ->first();

    //         if (!$schedule) {
    //             return response()->json(['message' => 'Tidak ada schedule aktif untuk assignment ini hari ini.'], 422);
    //         }

    //         // Cari attendance untuk schedule ini
    //         $attendance = Attendance::where('schedule_id', $schedule->id)
    //             ->where('date', $today)
    //             ->where('post_id', $postId)
    //             ->whereNotNull('check_in_at')
    //             ->first();

    //         if (!$attendance) {
    //             return response()->json(['message' => 'Tidak ada attendance yang check in untuk assignment ini.'], 422);
    //         }
    //     } else {
    //         $attendanceId = $request->input('attendance_id');
    //         if (!$attendanceId) {
    //             return response()->json(['message' => 'attendance_id wajib dikirim.'], 422);
    //         }

    //         $attendance = Attendance::findOrFail($attendanceId);
    //     }

    //     $this->authorize('view', $attendance);

    //     $post = $attendance->post;

    //     if (! $post) {
    //         return response()->json([ 'message' => 'Attendance tidak memiliki post.' ], 422);
    //     }

    //     $project = $attendance->project;

    //     $projectTimezone = $project->timezone ?? $project->organization->timezone ?? 'Asia/Jakarta';
    //     $nowInProjectTz = $request->filled('current_time')
    //         ? Carbon::createFromFormat('Y-m-d H:i:s', $request->current_time, $projectTimezone)
    //         : now($projectTimezone);

    //     $today = $nowInProjectTz->toDateString();

    //     // Ambil semua schedule hari ini di post ini
    //     $schedulesToday = Schedule::where('project_id', $project->id)
    //         ->where('date', $today)
    //         ->where('post_id', $post->id)
    //         ->with(['assignment', 'user'])
    //         ->get();

    //     // Filter active schedule
    //     $activeSchedules = $schedulesToday->filter(function ($schedule) use ($nowInProjectTz, $today, $projectTimezone) {
    //         if (! $schedule->assignment) {
    //             return false;
    //         }

    //         $start = Carbon::createFromFormat('Y-m-d H:i:s', $today.' '.$schedule->assignment->start_time, $projectTimezone);
    //         $end = Carbon::createFromFormat('Y-m-d H:i:s', $today.' '.$schedule->assignment->end_time, $projectTimezone);
    //         if ($end->lessThanOrEqualTo($start)) {
    //             $end->addDay();
    //         }

    //         $now = $nowInProjectTz->copy();
    //         if ($end->greaterThan($start) && $now->lessThan($start) && $end->diffInHours($start) > 12) {
    //             $now->addDay();
    //         }

    //         return $now->greaterThanOrEqualTo($start) && $now->lessThan($end);
    //     })->values();

    //     if ($activeSchedules->isEmpty()) {
    //         $activeSchedules = $schedulesToday;
    //     }

    //     $activeAssignmentId = $activeSchedules->first()?->assignment_id;
    //     if ($activeAssignmentId) {
    //         $activeSchedules = $activeSchedules->where('assignment_id', $activeAssignmentId)->values();
    //     }

    //     $scheduleIds = $activeSchedules->pluck('id')->all();

    //     // Attendance untuk post hari ini
    //     $allAttendances = Attendance::whereIn('schedule_id', $scheduleIds)
    //         ->where('date', $today)
    //         ->where('post_id', $post->id)
    //         ->with(['user', 'post', 'schedule.assignment', 'patrolScans.qrCode.patrolPoint'])
    //         ->get();

    //     $totalMembers = $activeSchedules->count();
    //     $checkedIn = $allAttendances->whereNotNull('check_in_at')->count();
    //     $notCheckedIn = max(0, $totalMembers - $checkedIn);

    //     // Patrol points
    //     $postPoints = $post->patrolPoints()->orderBy('sequence_order')->get();
    //     $attendanceIds = $allAttendances->pluck('id')->all();

    //     $allScans = PatrolScan::with(['qrCode.patrolPoint', 'attendance.user', 'photos'])
    //         ->whereIn('attendance_id', $attendanceIds)
    //         ->get();

    //     $patrolPoints = $postPoints->map(function ($point) use ($allScans) {
    //         $pointScans = $allScans->filter(function ($scan) use ($point) {
    //             return $scan->qrCode?->patrolPoint?->id === $point->id;
    //         })->sortBy('scan_time');

    //         return [
    //             'id' => $point->id,
    //             'name' => $point->name,
    //             'sequence_order' => $point->sequence_order,
    //             'latitude' => $point->latitude,
    //             'longitude' => $point->longitude,
    //             'is_scanned' => $pointScans->isNotEmpty(),
    //             'scanned_count' => $pointScans->count(),
    //             'last_scan_time' => $pointScans->last()?->scan_time,
    //             'last_scan_note' => $pointScans->last()?->note,
    //             'last_scan_user' => $pointScans->last()?->attendance?->user?->full_name,
    //         ];
    //     });

    //     $scanDetails = $allScans->map(function ($scan) {
    //         return [
    //             'id' => $scan->id,
    //             'attendance_id' => $scan->attendance_id,
    //             'patrol_point_id' => $scan->qrCode->patrolPoint->id,
    //             'patrol_point_name' => $scan->qrCode->patrolPoint->name,
    //             'scan_time' => $scan->scan_time,
    //             'note' => $scan->note,
    //             'photos' => $scan->photos->map(function ($photo) {
    //                 return [
    //                     'id' => $photo->id,
    //                     'url' => Storage::disk('public')->url($photo->photo),
    //                 ];
    //             }),
    //             'scan_user' => [
    //                 'id' => $scan->attendance->user_id,
    //                 'full_name' => $scan->attendance->user?->full_name,
    //             ],
    //         ];
    //     });

    //     // Activity list - FILTER by user attendance's assignment_id
    //     $userAssignmentId = $attendance->assignment_id;

    //     $activityQuery = Activity::where('active', true)
    //         ->where('post_id', $post->id);

    //     if ($userAssignmentId) {
    //         $activityQuery->whereHas('assignmentTimes', function ($q) use ($userAssignmentId) {
    //             $q->where('assignment_id', $userAssignmentId);
    //         });
    //     }

    //     $activityList = $activityQuery
    //         ->with('assignmentTimes.assignment')
    //         ->orderBy('name')
    //         ->get()
    //         ->map(function ($activity) use ($userAssignmentId) {
    //             // Filter assignment_times by user's assignment_id
    //             $filteredTimes = $userAssignmentId
    //                 ? $activity->assignmentTimes->where('assignment_id', $userAssignmentId)
    //                 : $activity->assignmentTimes;

    //             return [
    //                 'id' => $activity->id,
    //                 'name' => $activity->name,
    //                 'location' => $activity->location,
    //                 'assignment_times' => $filteredTimes->map(function ($t) {
    //                     return [
    //                         'id' => $t->id,
    //                         'assignment_id' => $t->assignment_id,
    //                         'assignment_name' => $t->assignment?->name,
    //                         'start_time' => $t->start_time,
    //                         'end_time' => $t->end_time,
    //                     ];
    //                 })->values(),
    //             ];
    //         })
    //         ->filter(fn ($act) => $act['assignment_times']->isNotEmpty())
    //         ->values();

    //     // Timesheet user
    //     $startMonth = Carbon::now($projectTimezone)->startOfMonth();
    //     $endMonth = Carbon::now($projectTimezone)->endOfMonth();

    //     $userTimesheet = Attendance::where('user_id', $attendance->user_id)
    //         ->whereBetween('date', [$startMonth->toDateString(), $endMonth->toDateString()])
    //         ->with(['assignment', 'overtimeLog.workAssignment'])
    //         ->get()
    //         ->map(function ($att) {
    //             return [
    //                 'date' => $att->date->format('Y-m-d'),
    //                 'check_in_at' => $att->check_in_at?->setTimezone('Asia/Jakarta')->format('H:i') ?? null,
    //                 'check_out_at' => $att->check_out_at?->setTimezone('Asia/Jakarta')->format('H:i') ?? null,
    //                 'status' => $att->computed_status,
    //                 'assignment_code' => $att->assignment?->code,
    //             ];
    //         });

    //     $postProgress = [
    //         'post_id' => $post->id,
    //         'post_name' => $post->name,
    //         'project_id' => $post->project_id,
    //         'assignment_id' => $userAssignmentId,
    //         'total_members' => $totalMembers,
    //         'checked_in_members' => $checkedIn,
    //         'not_checked_in_members' => $notCheckedIn,
    //         'total_patrol_points' => $postPoints->count(),
    //         'scanned_patrol_points' => $patrolPoints->where('is_scanned', true)->count(),
    //         'remaining_patrol_points' => $patrolPoints->where('is_scanned', false)->count(),
    //         'progress_percentage' => $postPoints->count() > 0 ? round($patrolPoints->where('is_scanned', true)->count() / $postPoints->count() * 100, 2) : 0,
    //     ];

    //     return response()->json([
    //         'success' => true,
    //         'data' => [
    //             'user' => [
    //                 'id' => $attendance->user_id,
    //                 'name' => $attendance->user?->full_name,
    //                 'check_in_at' => $attendance->check_in_at,
    //                 'check_out_at' => $attendance->check_out_at,
    //                 'computed_status' => $attendance->computed_status,
    //             ],
    //             'post_progress' => $postProgress,
    //             'patrol_points' => $patrolPoints,
    //             'scan_details' => $scanDetails,
    //             'activity_list' => $activityList,
    //             'user_timesheet' => $userTimesheet,
    //         ],
    //     ], 200);
    // }

    /**
     * Per-post progress detail endpoint for danru/admin_project/ho (attendance controller)
     * GET /api/posts/{post}/attendance/progress-detail
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

            // Gunakan date schedule yang sebenarnya (bisa jadi yesterday)
            $schDate = $schedule->date instanceof \Carbon\Carbon ? $schedule->date->format('Y-m-d') : \Carbon\Carbon::parse($schedule->date)->format('Y-m-d');
            $start = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $schDate.' '.$schedule->assignment->start_time, $projectTimezone);
            $end = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $schDate.' '.$schedule->assignment->end_time, $projectTimezone);

            // Handle midnight shift: end <= start berarti melewati tengah malam
            if ($end->lessThanOrEqualTo($start)) {
                $end->addDay();
            }

            return $nowInProjectTz->greaterThanOrEqualTo($start) && $nowInProjectTz->lessThan($end);
        })->values();

        if ($activeSchedules->isEmpty()) {
            // Tidak ada assignment aktif saat ini, fallback ke semua jadwal hari ini.
            $activeSchedules = $schedulesToday;
        }

        $activeAssignmentId = $activeSchedules->first()->assignment_id ?? null;
        $activeSchedules = $activeSchedules->where('assignment_id', $activeAssignmentId)->values();

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

        // Get schedule dates untuk attendance query (support lintas malam)
        $scheduleDates = $activeSchedules->pluck('date')->unique()->map(function ($date) {
            return $date instanceof \Carbon\Carbon ? $date->toDateString() : \Carbon\Carbon::parse($date)->toDateString();
        })->toArray();

        // Data attendance untuk post ini (active schedules)
        $attendances = Attendance::whereIn('schedule_id', $scheduleIds)
            ->whereIn('date', $scheduleDates)
            ->where('post_id', $post->id)
            ->with(['user', 'post', 'schedule.assignment', 'project.organization', 'patrolScans.qrCode.patrolPoint'])
            ->get();

        $totalMembers = $activeSchedules->count();
        $checkedIn = $attendances->whereNotNull('check_in_at')->count();
        $notCheckedIn = max(0, $totalMembers - $checkedIn);

        // Patrol points progress
        $postPoints = $post->patrolPoints()->orderBy('sequence_order')->get();
        $attendanceIds = $attendances->pluck('id')->all();

        $allScans = PatrolScan::with(['qrCode.patrolPoint', 'attendance.user', 'photos'])
            ->whereIn('attendance_id', $attendanceIds)
            ->get();

        $pointById = $postPoints->keyBy('id');

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
                        'url' => Storage::disk('public')->url($photo->photo),
                    ];
                }),
                'scan_user' => [
                    'id' => $scan->attendance->user_id,
                    'full_name' => $scan->attendance->user?->full_name,
                ],
            ];
        });

        $activityAssignmentId = $request->query('assignment_id');

        // preference: assignment_id request > user active post attendance > active schedule assignment
        if (! $activityAssignmentId) {
            $userPostAttendance = Attendance::where('user_id', $user->id)
                ->where('post_id', $post->id)
                ->whereIn('date', $scheduleDates)
                ->whereNotNull('check_in_at')
                ->whereNull('check_out_at')
                ->orderByDesc('check_in_at')
                ->first();

            $activityAssignmentId = $userPostAttendance?->assignment_id;
        }

        if (! $activityAssignmentId) {
            $activityAssignmentId = $activeAssignmentId;
        }

        if (! $activityAssignmentId) {
            $userActiveAttendance = Attendance::where('user_id', $user->id)
                ->whereNotNull('check_in_at')
                ->whereNull('check_out_at')
                ->orderByDesc('check_in_at')
                ->first();
            $activityAssignmentId = $userActiveAttendance?->assignment_id;
        }

        if (! $activityAssignmentId) {
            // Fallback to assignment_id from any checked-in user in this post
            $activityAssignmentId = $attendances->first()?->assignment_id;
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
        $userIds = $attendances->pluck('user_id')->unique()->values();
        $startMonth = Carbon::now($projectTimezone)->startOfMonth();
        $endMonth = Carbon::now($projectTimezone)->endOfMonth();

        $userTimesheets = Attendance::whereIn('user_id', $userIds)
            ->whereBetween('date', [$startMonth->toDateString(), $endMonth->toDateString()])
            ->with(['user', 'assignment', 'overtimeLog.workAssignment'])
            ->orderBy('user_id')
            ->orderBy('date')
            ->get()
            ->map(function ($attendance) {
                return [
                    'user_id' => $attendance->user_id,
                    'user_name' => $attendance->user?->full_name,
                    'date' => $attendance->date->format('Y-m-d'),
                    'check_in_at' => $attendance->check_in_at?->setTimezone('Asia/Jakarta')->format('H:i') ?? null,
                    'check_out_at' => $attendance->check_out_at?->setTimezone('Asia/Jakarta')->format('H:i') ?? null,
                    'status' => $attendance->computed_status,
                    'assignment_code' => $attendance->assignment?->code,
                ];
            });

        $postProgress = [
            'post_id' => $post->id,
            'post_name' => $post->name,
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

        return response()->json([
            'success' => true,
            'data' => [
                'post_progress' => $postProgress,
                'patrol_points' => $patrolPoints,
                'scan_details' => $scanDetails,
                'activity_list' => $activityList,
                'user_timesheets' => $userTimesheets,
                'members' => $attendances->map(function ($attendance) {
                    return [
                        'attendance_id' => $attendance->id,
                        'user_id' => $attendance->user_id,
                        'name' => $attendance->user?->full_name,
                        'check_in_at' => $attendance->check_in_at,
                        'check_out_at' => $attendance->check_out_at,
                        'computed_status' => $attendance->computed_status,
                    ];
                }),
            ],
        ], 200);
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
                'message' => 'Attendance sudah check-out, tidak dapat dihapus.',
            ], 409);
        }

        // Hapus attendance (beserta data turunan scan via FK jika ada)
        $attendance->delete();

        return response()->json([
            'message' => 'Check-in berhasil dihapus.',
        ], 200);
    }

    /**
     * Progress tim berbasis Post (slot = jumlah post project).
     * - Anggota: tidak memiliki akses (dibatasi policy).
     * - Danru (komandan_regu) & admin_project: hanya project miliknya.
     * - HO: bisa pilih `project_id` pada request body/query.
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

        $projectTimezone = $project?->timezone ?? $project?->organization?->timezone ?? 'Asia/Jakarta';

        $rules = [
            'current_time' => 'sometimes|date_format:Y-m-d H:i:s',
        ];
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $nowInProjectTz = $request->filled('current_time')
            ? Carbon::createFromFormat('Y-m-d H:i:s', $request->current_time, $projectTimezone)
            : now($projectTimezone);

        $today = $nowInProjectTz->toDateString();

        $yesterday = $nowInProjectTz->copy()->subDay()->toDateString();

        // Ambil schedule project untuk hari ini dan kemarin (mendukung lintas malam)
        $schedulesToday = Schedule::where('project_id', $projectId)
            ->whereIn('date', [$today, $yesterday])
            ->with('assignment')
            ->get();

        // Filter schedule berdasarkan assignment yang sedang aktif saat ini
        $activeSchedules = $schedulesToday->filter(function ($schedule) use ($nowInProjectTz, $projectTimezone) {
            if (! $schedule->assignment) {
                return false;
            }

            // Gunakan date schedule yang sebenarnya (bisa jadi yesterday)
            $schDate = $schedule->date instanceof \Carbon\Carbon ? $schedule->date->format('Y-m-d') : \Carbon\Carbon::parse($schedule->date)->format('Y-m-d');
            $start = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $schDate.' '.$schedule->assignment->start_time, $projectTimezone);
            $end = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $schDate.' '.$schedule->assignment->end_time, $projectTimezone);

            // Handle midnight shift: end <= start berarti melewati tengah malam
            if ($end->lessThanOrEqualTo($start)) {
                $end->addDay();
            }

            return $nowInProjectTz->greaterThanOrEqualTo($start) && $nowInProjectTz->lessThan($end);
        })->values();

        // Ambil daftar post (slot progress tim)
        $posts = Post::where('project_id', $projectId)->orderBy('id')->get(['id', 'name', 'type']);

        if ($activeSchedules->isEmpty()) {
            $postSlots = $posts->map(function ($post) {
                return [
                    'user' => null,
                    'schedule' => null,
                    'post' => [
                        'post_id' => (int) $post->id,
                        'name' => $post->name,
                        'type' => $post->type,
                    ],
                    'attendance' => null,
                    'checkin_status' => 'NOT_YET',
                    'scan_progress' => null,
                ];
            })->values();

            $total = $posts->count();

            return response()->json([
                'message' => 'Tidak ada assignment aktif saat ini.',
                'project_id' => (int) $projectId,
                'date' => $today,
                'time' => $nowInProjectTz->format('H:i:s'),
                'timezone' => $projectTimezone,
                'assignment' => null,
                'danru' => null,
                'progress' => [
                    'total' => $total,
                    'checked_in' => 0,
                    'not_checked_in' => $total,
                    'percentage' => 0,
                ],
                'members' => $postSlots,
            ], 200);
        }

        // Asumsi utama: pada satu waktu hanya ada 1 assignment aktif dalam project
        $activeAssignmentId = $activeSchedules->first()->assignment_id;
        $activeSchedules = $activeSchedules->where('assignment_id', $activeAssignmentId)->values();
        $activeAssignment = $activeSchedules->first()->assignment;
        $scheduleIds = $activeSchedules->pluck('id')->all();

        // Attendance untuk semua schedule aktif (anggota dan danru)
        $attendances = Attendance::whereIn('schedule_id', $scheduleIds)
            // Tanpa klausa where(date, today) agar attendance yang bermula kemarin dapat ditemukan
            ->with(['user', 'post'])
            ->get();

        $patrolService = app(PatrolScanService::class);

        // Commander (danru) progress (komandan_regu tidak punya post_id, scan berasal dari static posts project)
        $commanderAttendance = $attendances->first(function ($attendance) {
            return $attendance->user && $attendance->user->role === 'komandan_regu';
        });

        $commanderScanProgress = null;
        if ($commanderAttendance && $commanderAttendance->check_in_at) {
            $commanderScanProgress = $patrolService->getScanProgress($commanderAttendance);
        }

        $attendanceByPostId = $attendances
            ->filter(fn ($a) => ! is_null($a->post_id))
            ->keyBy('post_id');

        // Bentuk slot progress tim berdasarkan post (slot tetap ada meskipun attendance belum ada)
        $postSlots = $posts->map(function ($post) use ($attendanceByPostId, $patrolService) {
            $attendance = $attendanceByPostId->get($post->id);

            $scanProgress = null;
            $member = null;
            $attendancePayload = null;

            if ($attendance && $attendance->check_in_at) {
                $scanProgress = $patrolService->getScanProgress($attendance);
                $member = $attendance->user ? [
                    'id' => (int) $attendance->user_id,
                    'full_name' => $attendance->user?->full_name,
                    'role' => $attendance->user?->role,
                ] : null;
                $attendancePayload = [
                    'id' => (int) $attendance->id,
                    'post_id' => (int) $attendance->post_id,
                    'post_name' => $attendance->post?->name,
                    'post_type' => $attendance->post?->type,
                    'check_in_at' => $attendance->check_in_at?->toISOString(),
                    'check_out_at' => $attendance->check_out_at?->toISOString(),
                    'computed_status' => $attendance->computed_status,
                ];
            }

            return [
                'user' => $member,
                'schedule' => $attendance ? ['id' => (int) $attendance->schedule_id] : null,
                'post' => [
                    'post_id' => (int) $post->id,
                    'name' => $post->name,
                    'type' => $post->type,
                ],
                'attendance' => $attendancePayload,
                'checkin_status' => ($attendance && $attendance->check_in_at) ? 'CHECKED_IN' : 'NOT_YET',
                'scan_progress' => $scanProgress,
            ];
        })->values();

        $total = $posts->count();
        $checkedIn = $postSlots->filter(fn ($slot) => ! is_null($slot['attendance']))->count();
        $notCheckedIn = $total - $checkedIn;
        $percentage = $total > 0 ? round(($checkedIn / $total) * 100, 2) : 0;

        return response()->json([
            'message' => 'Progress assignment aktif berhasil diambil.',
            'project_id' => (int) $projectId,
            'date' => $today,
            'time' => $nowInProjectTz->format('H:i:s'),
            'timezone' => $projectTimezone,
            'assignment' => $activeAssignment ? [
                'id' => (int) $activeAssignment->id,
                'name' => $activeAssignment->name,
                'start_time' => $activeAssignment->start_time,
                'end_time' => $activeAssignment->end_time,
            ] : null,
            'danru' => $commanderAttendance ? [
                'attendance_id' => (int) $commanderAttendance->id,
                'check_in_at' => $commanderAttendance->check_in_at?->toISOString(),
                'computed_status' => $commanderAttendance->computed_status,
                'scan_progress' => $commanderScanProgress,
            ] : null,
            'progress' => [
                'total' => $total,
                'checked_in' => $checkedIn,
                'not_checked_in' => $notCheckedIn,
                'percentage' => $percentage,
            ],
            'members' => $postSlots,
        ], 200);
    }

    public function patrolScan(Request $request)
    {
        // NOTE: Endpoint ini dipertahankan untuk compatibility, tetapi sekarang memakai tabel patrol_scans (qr_code_id) + patrol_scan_photos.
        $validator = Validator::make($request->all(), [
            'attendance_id' => 'required|integer',
            'qr_code' => 'required_without:patrol_point_id|string',
            'patrol_point_id' => 'required_without:qr_code|exists:patrol_points,id',
            'scan_latitude' => 'required|numeric|between:-90,90',
            'scan_longitude' => 'required|numeric|between:-180,180',
            'scan_altitude' => 'nullable|numeric',
            'note' => 'nullable|string|max:500',
            'current_time' => 'required|date_format:Y-m-d H:i:s', // Device time
            'photos' => 'required|array|min:4',
            'photos.*' => 'required|image|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $user = Auth::user();
        $attendance = Attendance::where('id', $request->attendance_id)
            ->where('user_id', $user->id)
            ->with('project.organization', 'post', 'user')
            ->first();

        if (! $attendance) {
            return response()->json(['message' => 'Attendance tidak valid'], 404);
        }

        if (! $attendance->check_in_at || $attendance->check_out_at) {
            return response()->json(['message' => 'Attendance tidak valid'], 400);
        }

        $this->authorize('scanForAttendance', [PatrolScan::class, $attendance]);

        $qrCode = $request->qr_code;
        if (! $qrCode && $request->patrol_point_id) {
            $point = PatrolPoint::with('qrCode')->find($request->patrol_point_id);
            if (! $point || ! $point->qrCode) {
                return response()->json([
                    'message' => 'QR code untuk patrol point tidak ditemukan.',
                    'patrol_point_id' => (int) $request->patrol_point_id,
                ], 404);
            }
            $qrCode = $point->qrCode->code;
        }

        $projectTimezone = $attendance->project?->timezone ?? $attendance->project?->organization?->timezone ?? 'Asia/Jakarta';
        $scanTime = Carbon::createFromFormat('Y-m-d H:i:s', $request->current_time, $projectTimezone)->setTimezone('UTC');

        $service = app(PatrolScanService::class);
        $result = $service->createScan(
            $attendance,
            $qrCode,
            (float) $request->scan_latitude,
            (float) $request->scan_longitude,
            $request->scan_altitude !== null ? (float) $request->scan_altitude : null,
            $request->note,
            $scanTime,
            $request->file('photos') ?? []
        );

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'errors' => $result['errors'],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => $result['message'],
            'data' => [
                'scan' => $result['scan']->load('photos', 'qrCode.patrolPoint'),
                'patrol_point' => $result['patrolPoint'],
                'progress' => $service->getScanProgress($attendance),
            ],
        ], 201);
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
                'message' => 'Attendance tidak valid',
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

        $distance = $this->calculateDistance(
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
            // Overtime OFF day dihitung 1 assignment penuh (durasi dari assignment lembur),
            // bukan selisih check-in -> check-out.
            $otLog = $attendance->overtimeLog()->with('workAssignment')->first();

            $overtimeMinutes = (int) ($otLog?->workAssignment?->getDurationInMinutes() ?? 0);
            $overtimeStatus = $otLog ? 'APPROVED' : 'NONE';
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
     * TIMESHEET
     * GET /attendances/timesheet
     * Menampilkan riwayat schedule dan attendance selama 1 bulan
     */
    public function timesheet(Request $request)
    {
        $user = Auth::user();

        // Validasi parameter month (contoh: 2026-03)
        $request->validate([
            'month' => 'nullable|date_format:Y-m',
        ]);

        $project = $user->project()->with('organization')->first();
        $timezone = $project?->timezone ?? $project?->organization?->timezone ?? 'Asia/Jakarta';

        // Jika tidak ada parameter month, gunakan bulan sekarang dari request->current_time atau now
        if ($request->filled('month')) {
            $startDate = Carbon::createFromFormat('Y-m', $request->month, $timezone)->startOfMonth();
            $endDate = $startDate->copy()->endOfMonth();
        } else {
            // Gunakan current_time jika dikirim, atau now
            $nowInProjectTz = $request->filled('current_time')
                ? Carbon::createFromFormat('Y-m-d H:i:s', $request->current_time, $timezone)
                : now($timezone);
            $startDate = $nowInProjectTz->copy()->startOfMonth();
            $endDate = $nowInProjectTz->copy()->endOfMonth();
        }

        $nowForStatus = now($timezone);
        $todayStr = $nowForStatus->toDateString();

        // 1. Get Schedules in month
        $schedules = Schedule::where('user_id', $user->id)
            ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->with('assignment')
            ->get()
            ->keyBy(fn ($s) => $s->date instanceof \Carbon\Carbon ? $s->date->format('Y-m-d') : \Carbon\Carbon::parse($s->date)->format('Y-m-d'));

        // 2. Get Attendances in month
        $attendances = Attendance::where('user_id', $user->id)
            ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->with(['assignment', 'overtimeLog.workAssignment'])
            ->get()
            ->keyBy(fn ($a) => $a->date instanceof \Carbon\Carbon ? $a->date->format('Y-m-d') : \Carbon\Carbon::parse($a->date)->format('Y-m-d'));

        $history = [];
        $totalDaysMonth = $startDate->daysInMonth;

        $summary = [
            'total_hari' => $totalDaysMonth,
            'schedule_kerja' => 0,
            'kode_kehadiran' => 0,
            'tidak_absen' => 0,
        ];

        for ($day = 1; $day <= $totalDaysMonth; $day++) {
            $currentDate = $startDate->copy()->day($day);
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

                // Cek apakah ada lembur di hari libur
                if ($isOffSchedule) {
                    $workCode = $attendance->overtimeLog?->workAssignment?->code ?? $attendance->assignment?->code;
                    $attendanceCodeStr = $scheduleCode.' / '.$workCode;
                } else {
                    $attendanceCodeStr = $attendance->assignment?->code ?? $scheduleCode;
                }

                $checkIn = $attendance->check_in_at ? $attendance->check_in_at->setTimezone($timezone)->format('H:i') : '--:--';
                $checkOut = $attendance->check_out_at ? $attendance->check_out_at->setTimezone($timezone)->format('H:i') : '--:--';

                if (in_array($attendance->computed_status, ['HADIR TELAT', 'HADIR TELAT LEMBUR'])) {
                    $statusStr = 'Telat';
                } else {
                    $statusStr = 'Tepat Waktu';
                }

            } else {
                // Tidak ada attendance
                if ($currentDate->greaterThanOrEqualTo($nowForStatus->copy()->startOfDay())) {
                    // Hari ini atau masa depan
                    $statusStr = 'Belum Absen';
                } else {
                    // Masa lalu
                    if ($schedule && ! $isOffSchedule) {
                        $statusStr = 'Tidak Absen';
                        $summary['tidak_absen']++;
                    } else {
                        // Tidak ada jadwal / jadwal O
                        $statusStr = 'Libur';
                    }
                }
            }

            // Fallback attendance code
            if (! $attendanceCodeStr) {
                if ($statusStr === 'Belum Absen' || $statusStr === 'Tidak Absen' || $statusStr === 'Libur') {
                    $attendanceCodeStr = $scheduleCode ?? '-';
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
            'summary' => $summary,
            'history' => $history,
        ]);
    }

    /**
     * POST MEMBERS TIMESHEET
     * GET /posts/{post}/members-timesheet
     *
     * Realtime untuk satu hari (tanpa agregasi bulanan):
     * - Wajib `current_time` (Y-m-d H:i:s) di query string — sumber tanggal & jam di timezone project.
     * - Hanya menampilkan user yang masih punya baris Attendance pada post ini untuk slot hari tersebut.
     *   Jadi jika check-in dihapus, user hilang dari daftar (tidak “nyangkut” seperti list distinct sebulan).
     * - Lintas malam: ikut sertakan attendance tanggal kemarin yang belum check-out (shift masih jalan).
     */
    // public function postMembersTimesheet(Request $request, Post $post)
    // {
    //     $this->authorize('progress', Attendance::class);

    //     $validator = Validator::make($request->query(), [
    //         'current_time' => 'required|date_format:Y-m-d H:i:s',
    //         'project_id' => 'sometimes|integer',
    //     ]);
    //     if ($validator->fails()) {
    //         return response()->json($validator->errors(), 422);
    //     }

    //     $user = Auth::user();

    //     // Project scope
    //     if ($user->role === 'ho') {
    //         $projectId = (int) $request->query('project_id', 0);
    //         if ($projectId <= 0) {
    //             return response()->json(['message' => 'project_id wajib dikirim untuk HO.'], 422);
    //         }
    //     } else {
    //         $projectId = (int) ($user->project_id ?? 0);
    //         if ($projectId <= 0) {
    //             return response()->json(['message' => 'User tidak memiliki project.'], 422);
    //         }
    //     }

    //     if ((int) $post->project_id !== $projectId && $user->role !== 'dev') {
    //         return response()->json(['message' => 'Post tidak berada dalam project Anda.'], 403);
    //     }

    //     $project = Project::with('organization')->find($projectId);
    //     if (! $project) {
    //         return response()->json(['message' => 'Project tidak ditemukan.'], 404);
    //     }

    //     $timezone = $project->timezone ?? $project->organization->timezone ?? 'Asia/Jakarta';
    //     $nowInProjectTz = Carbon::createFromFormat('Y-m-d H:i:s', $request->query('current_time'), $timezone);
    //     $primaryDate = $nowInProjectTz->toDateString();
    //     $prevDate = $nowInProjectTz->copy()->subDay()->toDateString();

    //     // Attendance pada post untuk “hari kalender” ini ATAU shift kemarin yang masih terbuka (lintas malam)
    //     $attendanceRows = Attendance::where('post_id', $post->id)
    //         ->whereNotNull('check_in_at')
    //         ->where(function ($q) use ($primaryDate, $prevDate) {
    //             $q->where('date', $primaryDate)
    //                 ->orWhere(function ($q2) use ($prevDate) {
    //                     $q2->where('date', $prevDate)->whereNull('check_out_at');
    //                 });
    //         })
    //         ->with(['user', 'assignment', 'overtimeLog.workAssignment'])
    //         ->get();

    //     if ($attendanceRows->isEmpty()) {
    //         return response()->json([
    //             'success' => true,
    //             'meta' => [
    //                 'current_time' => $request->query('current_time'),
    //                 'timezone' => $timezone,
    //                 'calendar_date' => $primaryDate,
    //                 'overnight_includes_date' => $prevDate,
    //             ],
    //             'data' => [],
    //         ]);
    //     }

    //     $dateKey = static function ($attendance) {
    //         return $attendance->date instanceof \Carbon\Carbon
    //             ? $attendance->date->format('Y-m-d')
    //             : \Carbon\Carbon::parse($attendance->date)->format('Y-m-d');
    //     };

    //     $byUser = $attendanceRows->groupBy('user_id');
    //     $result = [];

    //     foreach ($byUser as $userId => $group) {
    //         // Prioritas: shift kemarin masih buka di post ini; kalau tidak, baris tanggal kalender utama
    //         $overnight = $group
    //             ->filter(fn ($a) => $dateKey($a) === $prevDate && $a->check_out_at === null)
    //             ->sortByDesc('check_in_at')
    //             ->first();

    //         $todayRow = $group
    //             ->filter(fn ($a) => $dateKey($a) === $primaryDate)
    //             ->sortByDesc('check_in_at')
    //             ->first();

    //         $attendance = $overnight ?? $todayRow ?? $group->sortByDesc('check_in_at')->first();

    //         $attendanceDateStr = $dateKey($attendance);
    //         $currentDate = Carbon::parse($attendanceDateStr, $timezone)->startOfDay();

    //         $schedule = Schedule::where('user_id', $userId)
    //             ->where('date', $attendanceDateStr)
    //             ->with('assignment')
    //             ->first();

    //         $scheduleCode = $schedule?->assignment?->code;
    //         $isOffSchedule = $schedule ? $schedule->assignment->isOffDuty() : true;

    //         if ($attendance) {
    //             if ($isOffSchedule) {
    //                 $workCode = $attendance->overtimeLog?->workAssignment?->code ?? $attendance->assignment?->code;
    //                 $attendanceCodeStr = $scheduleCode.' / '.$workCode;
    //             } else {
    //                 $attendanceCodeStr = $attendance->assignment?->code ?? $scheduleCode;
    //             }

    //             $checkIn = $attendance->check_in_at ? $attendance->check_in_at->setTimezone($timezone)->format('H:i') : '--:--';
    //             $checkOut = $attendance->check_out_at ? $attendance->check_out_at->setTimezone($timezone)->format('H:i') : '--:--';

    //             if (in_array($attendance->computed_status, ['HADIR TELAT', 'HADIR TELAT LEMBUR'])) {
    //                 $statusStr = 'Telat';
    //             } else {
    //                 $statusStr = 'Tepat Waktu';
    //             }
    //         } else {
    //             $attendanceCodeStr = $scheduleCode ?? '-';
    //             $checkIn = '--:--';
    //             $checkOut = '--:--';
    //             $statusStr = 'Belum Absen';
    //         }

    //         $history = [[
    //             'date' => $currentDate->format('d'),
    //             'day_name' => $currentDate->translatedFormat('l'),
    //             'full_date' => $attendanceDateStr,
    //             'schedule_code' => $scheduleCode ?? '-',
    //             'attendance_code' => $attendanceCodeStr ?? '-',
    //             'check_in' => $checkIn,
    //             'check_out' => $checkOut,
    //             'status' => $statusStr,
    //             'attendance_id' => (int) $attendance->id,
    //         ]];

    //         $summary = [
    //             'total_hari' => 1,
    //             'schedule_kerja' => ($schedule && ! $isOffSchedule) ? 1 : 0,
    //             'kode_kehadiran' => 1,
    //             'tidak_absen' => 0,
    //         ];

    //         $result[] = [
    //             'user_id' => (int) $userId,
    //             'user_name' => $attendance->user?->full_name ?? 'Unknown',
    //             'summary' => $summary,
    //             'history' => $history,
    //         ];
    //     }

    //     usort($result, fn ($a, $b) => strcmp($a['user_name'], $b['user_name']));

    //     return response()->json([
    //         'success' => true,
    //         'meta' => [
    //             'current_time' => $request->query('current_time'),
    //             'timezone' => $timezone,
    //             'calendar_date' => $primaryDate,
    //             'overnight_includes_date' => $prevDate,
    //         ],
    //         'data' => $result,
    //     ]);
    // }

    public function postMembersTimesheet(Request $request, Post $post)
{
    $this->authorize('progress', Attendance::class);

    $validator = Validator::make($request->query(), [
        'current_time' => 'required|date_format:Y-m-d H:i:s',
        'project_id' => 'sometimes|integer',
    ]);

    if ($validator->fails()) {
        return response()->json($validator->errors(), 422);
    }

    $user = Auth::user();

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

    if ((int) $post->project_id !== $projectId && $user->role !== 'dev') {
        return response()->json(['message' => 'Post tidak berada dalam project Anda.'], 403);
    }

    $project = Project::with('organization')->find($projectId);
    if (! $project) {
        return response()->json(['message' => 'Project tidak ditemukan.'], 404);
    }

    // ================= TIMEZONE =================
    $timezone = $project->timezone ?? $project->organization->timezone ?? 'Asia/Jakarta';

    $nowInProjectTz = Carbon::createFromFormat(
        'Y-m-d H:i:s',
        $request->query('current_time'),
        $timezone
    );

    $startDate = $nowInProjectTz->copy()->startOfMonth();
    $endDate   = $nowInProjectTz->copy()->endOfMonth();

    // ================= GET ATTENDANCE =================
    $attendanceRows = Attendance::where('post_id', $post->id)
        ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
        ->with(['user', 'assignment', 'overtimeLog.workAssignment'])
        ->get();

    // ================= GET USER IDS =================
    $userIds = $attendanceRows->pluck('user_id')->unique();

    if ($userIds->isEmpty()) {
        return response()->json([
            'success' => true,
            'meta' => [
                'current_time' => $request->query('current_time'),
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

        $attendanceByDate = $attendances->keyBy(fn ($a) =>
            \Carbon\Carbon::parse($a->date)->format('Y-m-d')
        );

        $userSchedules = $schedules->get($userId)?->keyBy(fn ($s) =>
            \Carbon\Carbon::parse($s->date)->format('Y-m-d')
        ) ?? collect();

        $history = [];

        $summary = [
            'total_hari' => $startDate->daysInMonth,
            'schedule_kerja' => 0,
            'kode_kehadiran' => 0,
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
                    $workCode = $attendance->overtimeLog?->workAssignment?->code
                        ?? $attendance->assignment?->code;
                    $attendanceCodeStr = $scheduleCode.' / '.$workCode;
                } else {
                    $attendanceCodeStr = $attendance->assignment?->code ?? $scheduleCode;
                }

                $checkIn = $attendance->check_in_at
                    ? $attendance->check_in_at->setTimezone($timezone)->format('H:i')
                    : '--:--';

                $checkOut = $attendance->check_out_at
                    ? $attendance->check_out_at->setTimezone($timezone)->format('H:i')
                    : '--:--';

                $statusStr = in_array($attendance->computed_status, [
                    'HADIR TELAT',
                    'HADIR TELAT LEMBUR'
                ]) ? 'Telat' : 'Tepat Waktu';

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

            if (! $attendanceCodeStr) {
                $attendanceCodeStr = $scheduleCode ?? '-';
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

    /**
     * Resolve schedule yang aktif untuk waktu check-in, mendukung shift lintas malam (midnight shift).
     * Memeriksa jadwal hari ini dan kemarin, dan menyeleksi jadwal dengan jam shift terkemungkinan.
     */
    private function resolveActiveScheduleForCheckIn($user, $currentTimeStr)
    {
        $deviceDateTime = Carbon::createFromFormat('Y-m-d H:i:s', $currentTimeStr, 'Asia/Jakarta');
        $todayStr = $deviceDateTime->toDateString();
        $yesterdayStr = $deviceDateTime->copy()->subDay()->toDateString();

        $schedules = Schedule::where('user_id', $user->id)
            ->whereIn('date', [$todayStr, $yesterdayStr])
            ->with('assignment', 'project')
            ->get();

        if ($schedules->isEmpty()) {
            return [null, $todayStr];
        }

        $validSchedules = [];
        $offDutySchedule = null;

        foreach ($schedules as $sch) {
            if (! $sch->project || ! $sch->assignment) {
                continue;
            }

            $projectTz = $sch->project->timezone ?? $sch->project->organization->timezone ?? 'Asia/Jakarta';
            $nowTz = Carbon::createFromFormat('Y-m-d H:i:s', $currentTimeStr, $projectTz)->setTimezone('UTC');

            if ($sch->assignment->isOffDuty()) {
                if ($sch->date instanceof \Carbon\Carbon ? $sch->date->format('Y-m-d') === $todayStr : \Carbon\Carbon::parse($sch->date)->format('Y-m-d') === $todayStr) {
                    $offDutySchedule = $sch;
                }

                continue;
            }

            $schDate = $sch->date instanceof \Carbon\Carbon ? $sch->date->format('Y-m-d') : \Carbon\Carbon::parse($sch->date)->format('Y-m-d');
            $start = Carbon::createFromFormat('Y-m-d H:i:s', $schDate.' '.$sch->assignment->start_time, $projectTz)->setTimezone('UTC');
            $end = Carbon::createFromFormat('Y-m-d H:i:s', $schDate.' '.$sch->assignment->end_time, $projectTz)->setTimezone('UTC');

            if ($end->lessThanOrEqualTo($start)) {
                $end->addDay();  // Shift terlewati tengah malam, selesai esoknya
            }

            // Rentang check-in yang masuk akal: 4 jam sebelum shift dimulai hingga 18 jam sesudah dimulai
            // Jika Anda check-in di 01:00 am dan jadwal Anda di hari kemarin dimulai 22:00,
            // (01:00 terhitung +3 jam dari start, jadi masuk rentang interval ini dengan valid)
            if ($nowTz->greaterThanOrEqualTo($start->copy()->subHours(4)) && $nowTz->lessThan($start->copy()->addHours(18))) {
                $validSchedules[] = [
                    'schedule' => $sch,
                    'start' => $start,
                ];
            }
        }

        if (! empty($validSchedules)) {
            // Prioritaskan schedule shift yang paling maju/terbaru jika rentang masuk di dua hari (mencegah bentrok)
            usort($validSchedules, function ($a, $b) {
                return $b['start']->timestamp - $a['start']->timestamp;
            });
            $bestSchedule = $validSchedules[0]['schedule'];
            $schDate = $bestSchedule->date instanceof \Carbon\Carbon ? $bestSchedule->date->format('Y-m-d') : \Carbon\Carbon::parse($bestSchedule->date)->format('Y-m-d');

            return [$bestSchedule, $schDate];
        }

        if ($offDutySchedule) {
            $offDate = $offDutySchedule->date instanceof \Carbon\Carbon ? $offDutySchedule->date->format('Y-m-d') : \Carbon\Carbon::parse($offDutySchedule->date)->format('Y-m-d');

            return [$offDutySchedule, $offDate];
        }

        $todaySch = $schedules->firstWhere(function ($s) use ($todayStr) {
            $schDt = $s->date instanceof \Carbon\Carbon ? $s->date->format('Y-m-d') : \Carbon\Carbon::parse($s->date)->format('Y-m-d');

            return $schDt === $todayStr;
        });

        if ($todaySch) {
            return [$todaySch, $todayStr];
        }

        return [null, $todayStr];
    }

    private function calculateDistance($lat1, $lon1, $lat2, $lon2)
    {
        // Validate and cast to float
        $lat1 = (float) $lat1;
        $lon1 = (float) $lon1;
        $lat2 = (float) $lat2;
        $lon2 = (float) $lon2;

        // Check for null or zero coordinates
        if ($lat1 === 0.0 || $lon1 === 0.0 || $lat2 === 0.0 || $lon2 === 0.0) {
            return 99999999; // Return large distance to reject
        }

        $earthRadius = 6371000; // meters
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        $distance = $earthRadius * $c; // Distance in meters

        return $distance;
    }

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
                'url' => $attendance->selfie_photo_path ? Storage::disk('public')->url($attendance->selfie_photo_path) : null,
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
}
