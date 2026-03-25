<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Attendance;
use App\Models\User;
use App\Models\Post;
use App\Models\Assignment;
use App\Models\Schedule;
use App\Models\PatrolScan;
use App\Models\PatrolPoint;
use App\Models\Absence;
use App\Models\OvertimeLog;
use App\Services\OffDayOvertimeService;
use App\Services\PatrolScanService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use App\Services\ImageWebpService;

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
        $query = Attendance::with('assignment', 'post', 'user', 'schedule');

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
            'data' => $attendances->items(),
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

        // Anggota wajib pilih post. Komandan regu selalu menggunakan post static dari schedule (tanpa input dari user).
        if ($user->role !== 'komandan_regu') {
            $rules['post_type'] = 'required|string|in:static,mobile';
            $rules['post_name'] = 'required|string';
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $deviceDateTime = Carbon::createFromFormat('Y-m-d H:i:s', $request->current_time, 'Asia/Jakarta');
        $today = $deviceDateTime->toDateString();

        // Check schedule untuk validasi assignment time
        $schedule = Schedule::where('user_id', $user->id)
            ->where('date', $today)
            ->with('assignment', 'project')
            ->first();

        if (!$schedule) {
            return response()->json([
                'message' => 'Anda tidak memiliki jadwal hari ini.',
                'date' => $today,
            ], 403);
        }

        // Determine post
        if ($user->role === 'komandan_regu') {
            // Komandan regu selalu menggunakan pos STATIC dari project (tidak bergantung schedule->post).
            $post = Post::where('project_id', $user->project_id)
                ->where('type', 'static')
                ->orderBy('id')
                ->first();

            if (!$post) {
                return response()->json([
                    'message' => 'Project Anda belum memiliki pos static.',
                    'date' => $today,
                ], 403);
            }
        } else {
            // Check post exists dan sesuai dengan project user (berdasarkan type dan name)
            $post = Post::where('type', $request->post_type)
                ->where('name', $request->post_name)
                ->where('project_id', $user->project_id)
                ->first();

            if (!$post) {
                return response()->json([
                    'message' => 'Pos tidak ditemukan. Pilih pos yang tersedia di project Anda.',
                    'post_type' => $request->post_type,
                    'post_name' => $request->post_name,
                ], 404);
            }
        }

        $assignment = $schedule->assignment;
        $project = $schedule->project;
        $projectTimezone = $project->timezone ?? $project->organization->timezone ?? 'Asia/Jakarta';
        $now = Carbon::createFromFormat('Y-m-d H:i:s', $request->current_time, $projectTimezone);
        $now->setTimezone('UTC');

        // Check location
        if (!$project || !$project->location_latitude || !$project->location_longitude) {
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
                'distance' => round($distance, 2) . ' meters',
                'allowed_radius' => $globalRadius . ' meters',
            ], 403);
        }

        // Check time (Assignment O/OFF: bypass schedule time validation)
        $lateMinutes = 0;
        $computedStatus = 'HADIR';

        if (!$assignment->isOffDuty()) {
            $gracePeriod = $assignment->grace_period ?? 15;
            $startTime = Carbon::createFromFormat('Y-m-d H:i:s', $today . ' ' . $assignment->start_time, $projectTimezone);
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

        $todayCarbon = Carbon::parse($today);
        $dateFormatted = $todayCarbon->translatedFormat('d F Y');
        $nowInProjectTz = $now->copy()->setTimezone($projectTimezone);

        $isOffDay = $assignment->isOffDuty();

        return response()->json([
            'message' => 'Waktu check-in valid. Silakan ambil foto selfie.',
            'can_checkin' => true,
            'date' => $today,
            'date_formatted' => $dateFormatted,
            'time' => $nowInProjectTz->format('H:i:s'),
            'timezone' => $projectTimezone,
            'status' => $computedStatus,
            'late_minutes' => $lateMinutes,
            'distance' => round($distance, 2) . ' meters',
            'allowed_radius' => $globalRadius . ' meters',
            'is_off_day' => $isOffDay,
            'requires_overtime_work_code' => $isOffDay,
            'post' => [
                'id' => $post->id,
                'name' => $post->name,
                'type' => $post->type,
            ],
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
            'selfie_photo' => 'required|image|max:1024',
        ];

        // Anggota wajib pilih post. Komandan regu selalu menggunakan post static dari schedule (tanpa input dari user).
        if ($user->role !== 'komandan_regu') {
            $rules['post_type'] = 'required|string|in:static,mobile';
            $rules['post_name'] = 'required|string';
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        // PENTING: Extract DATE dari device time terlebih dahulu (sebelum load schedule)
        // Gunakan default timezone untuk parse, karena project belum di-load
        $deviceDateTime = Carbon::createFromFormat('Y-m-d H:i:s', $request->current_time, 'Asia/Jakarta');
        $today = $deviceDateTime->toDateString(); // e.g., "2026-02-13"
        $todayCarbon = Carbon::parse($today);

        // GUARD 0: Cek attendance hari lalu yang belum checkout
        $yesterdayUnclosed = Attendance::where('user_id', $user->id)
            ->where('date', '<', $today)
            ->whereNotNull('check_in_at')
            ->whereNull('check_out_at')
            ->first();

        if ($yesterdayUnclosed) {
            $yesterdayDate = $yesterdayUnclosed->date->translatedFormat('d F Y');
            return response()->json([
                'message' => 'Anda memiliki attendance hari sebelumnya yang belum di-close.',
                'info' => 'Absen ' . $yesterdayDate . ' belum check-out. Silakan check-out terlebih dahulu.',
                'unclosed_attendance' => [
                    'id' => $yesterdayUnclosed->id,
                    'date' => $yesterdayUnclosed->date->format('Y-m-d'),
                    'check_in_at' => $yesterdayUnclosed->check_in_at->format('H:i:s'),
                ],
            ], 403);
        }

        // GUARD 1: Cegah double check-in (gunakan device date, bukan server date)
        $existingAttendance = Attendance::where('user_id', $user->id)
            ->where('date', $today)
            ->whereNotNull('check_in_at')
            ->first();

        if ($existingAttendance) {
            $dateFormatted = $todayCarbon->translatedFormat('d F Y'); // e.g., "13 February 2026" (device date!)
            $existingCheckinTime = $existingAttendance->check_in_at->setTimezone('Asia/Jakarta')->format('H:i:s'); // Use default timezone for display
            return response()->json([
                'message' => 'Anda sudah absen masuk hari ini.',
                'info' => 'Ini tanggal ' . $dateFormatted,
                'date' => $today,
                'last_check_in_at' => $existingCheckinTime,
            ], 409);
        }

        // GUARD 2: Wajib punya schedule hari ini (hanya untuk validasi assignment time)
        $schedule = Schedule::where('user_id', $user->id)
            ->where('date', $today)
            ->with('assignment', 'project')
            ->first();

        if (!$schedule) {
            return response()->json([
                'message' => 'Anda tidak memiliki jadwal hari ini.',
                'date' => $today,
            ], 403);
        }

        // Determine post
        if ($user->role === 'komandan_regu') {
            // Komandan regu selalu menggunakan pos STATIC dari project (tidak bergantung schedule->post).
            $post = Post::where('project_id', $user->project_id)
                ->where('type', 'static')
                ->orderBy('id')
                ->first();

            if (!$post) {
                return response()->json([
                    'message' => 'Project Anda belum memiliki pos static.',
                    'date' => $today,
                ], 403);
            }
        } else {
            // ========= GET POST DARI REQUEST (BERDASARKAN TYPE & NAME) ==========
            // User memilih post berdasarkan type dan name dari daftar project
            $post = Post::where('type', $request->post_type)
                ->where('name', $request->post_name)
                ->where('project_id', $user->project_id)
                ->first();

            if (!$post) {
                return response()->json([
                    'message' => 'Pos tidak ditemukan. Pilih pos yang tersedia di project Anda.',
                    'post_type' => $request->post_type,
                    'post_name' => $request->post_name,
                ], 404);
            }
        }

        // Unpack semua data yang diperlukan
        $assignment = $schedule->assignment;
        $project = $schedule->project;
        
        // NOW: Parse device time dalam timezone PROJECT
        $projectTimezone = $project->timezone ?? $project->organization->timezone ?? 'Asia/Jakarta';
        $now = Carbon::createFromFormat('Y-m-d H:i:s', $request->current_time, $projectTimezone);
        // Convert ke UTC untuk internal logic
        $now->setTimezone('UTC');

        // GUARD: Project harus memiliki location
        if (!$project || !$project->location_latitude || !$project->location_longitude) {
            return response()->json([
                'message' => 'Project tidak memiliki data lokasi. Hubungi administrator.',
                'project_id' => $schedule->project_id,
            ], 403);
        }

        // GUARD 3: Cegah attendance jika ada keterangan absence pada schedule ini
        $dayAbsence = Absence::where('schedule_id', $schedule->id)->first();

        if ($dayAbsence) {
            return response()->json([
                'message' => 'Hari ini tercatat ' . $dayAbsence->label . '. Tidak dapat absen masuk.',
                'absence_type' => $dayAbsence->absence_type,
            ], 403);
        }

        $isOffDutyAssignment = $assignment->isOffDuty(); // termasuk code 'O'

        $offDayOvertime = app(OffDayOvertimeService::class);
        $workAssignment = null;
        if ($isOffDutyAssignment) {
            $workCode = $request->input('overtime_work_code');
            if (! $workCode) {
                return response()->json([
                    'message' => 'Jadwal hari ini OFF. Kirim overtime_work_code untuk shift yang Anda kerjakan lembur (mis. P atau M).',
                    'requires_overtime_work_code' => true,
                ], 422);
            }
            $workAssignment = $offDayOvertime->resolveWorkAssignment($schedule->project_id, $workCode);
            if (! $workAssignment) {
                return response()->json([
                    'message' => 'Kode shift lembur tidak valid. Pilih assignment kerja (non-OFF) di project ini.',
                    'overtime_work_code' => $workCode,
                ], 422);
            }
        }
        $startTime = null;
        if (!$isOffDutyAssignment) {
            $startTime = Carbon::createFromFormat('Y-m-d H:i:s', $today . ' ' . $assignment->start_time, $projectTimezone);
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
                'distance' => round($distance, 2) . ' meters',
                'allowed_radius' => $globalRadius . ' meters',
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
        if (!$isOffDutyAssignment) {
            $gracePeriod = $assignment->grace_period ?? 15;
            $endTime = Carbon::createFromFormat('Y-m-d H:i:s', $today . ' ' . $assignment->end_time, $projectTimezone);
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

        // Untuk OFF day overtime, status langsung dianggap LEMBUR saat check-in.
        if ($isOffDutyAssignment) {
            $computedStatus = 'HADIR LEMBUR';
        }

        // Handle selfie photo upload
        $imageService = app(ImageWebpService::class);
        $selfiePath = $imageService->storeAsWebp($request->file('selfie_photo'), 'attendances/selfies', 80);

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
            $offDayOvertime
        ) {
            $created = Attendance::create([
                'project_id' => $schedule->project_id,
                'user_id' => $user->id,
                'schedule_id' => $schedule->id,
                'assignment_id' => $assignment->id,
                'post_id' => $post->id,
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
            $lateInfo = ' (Telat ' . $attendance->late_minutes . ' menit)';
        }
        
        return response()->json([
            'message' => 'Absen masuk berhasil.',
            'info' => 'Ini tanggal ' . $dateFormatted . $lateInfo,
            'date' => $today,
            'time' => $nowInProjectTz->format('H:i:s'),
            'timezone' => $projectTimezone,
            'status' => $attendance->computed_status,
            'late_minutes' => (int) $attendance->late_minutes,
            'data' => $this->formatAttendanceResponse($attendance),
        ], 201);
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
        $this->authorize('progress', Attendance::class);

        $user = Auth::user();
        if (!$user->project_id) {
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
            if (!$request->filled('attendance_id')) {
                return response()->json([
                    'message' => 'Attendance tidak valid',
                ], 422);
            }

            $danruAttendance = Attendance::where('id', (int) $request->attendance_id)
                ->where('user_id', $user->id)
                ->with(['schedule.assignment', 'project.organization'])
                ->first();

            if (!$danruAttendance) {
                return response()->json([
                    'message' => 'Attendance tidak valid',
                ], 404);
            }

            if (!$danruAttendance->check_in_at) {
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
            if (!$schedule->assignment) {
                return false;
            }

            $start = Carbon::createFromFormat('Y-m-d H:i:s', $today . ' ' . $schedule->assignment->start_time, $projectTimezone);
            $end = Carbon::createFromFormat('Y-m-d H:i:s', $today . ' ' . $schedule->assignment->end_time, $projectTimezone);

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
        $activeAssignmentId = $activeSchedules->first()->assignment_id;
        $activeSchedules = $activeSchedules->where('assignment_id', $activeAssignmentId)->values();
        $activeAssignment = $activeSchedules->first()->assignment;

        // Danru tidak boleh melihat progress danru lain.
        // Tetap boleh melihat anggota. Untuk dirinya sendiri, tetap ditampilkan (agar tahu statusnya).
        if ($user->role === 'komandan_regu') {
            $activeSchedules = $activeSchedules->filter(function ($schedule) use ($user) {
                if (!$schedule->user) {
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

        $members = $activeSchedules->map(function ($schedule) use ($attendances, $patrolService) {
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
                'attendance' => $attendance ? [
                    'id' => (int) $attendance->id,
                    'check_in_at' => $attendance->check_in_at?->toISOString(),
                    'check_out_at' => $attendance->check_out_at?->toISOString(),
                    'computed_status' => $attendance->computed_status,
                ] : null,
                'checkin_status' => ($attendance && $attendance->check_in_at) ? 'CHECKED_IN' : 'NOT_YET',
                'scan_progress' => $scanProgress,
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

        if (!$attendance) {
            return response()->json(['message' => 'Attendance tidak valid'], 404);
        }

        if (!$attendance->check_in_at || $attendance->check_out_at) {
            return response()->json(['message' => 'Attendance tidak valid'], 400);
        }

        $this->authorize('scanForAttendance', [PatrolScan::class, $attendance]);

        $qrCode = $request->qr_code;
        if (!$qrCode && $request->patrol_point_id) {
            $point = PatrolPoint::with('qrCode')->find($request->patrol_point_id);
            if (!$point || !$point->qrCode) {
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

        if (!$result['success']) {
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
        
        // MODIFIED: If attendance_id not provided, find active attendance from token
        if ($request->has('attendance_id')) {
            // OLD: Use provided attendance_id
            // OLD: $attendance = Attendance::where('id', $request->attendance_id)
            //     ->where('user_id', $user->id)
            //     ->with('assignment', 'post')
            //     ->first();
            
            // NEW: Use provided attendance_id
            $attendance = Attendance::where('id', $request->attendance_id)
                ->where('user_id', $user->id)
                ->with('assignment', 'post')
                ->first();
        } else {
            // NEW: Get active attendance from token (user with checked-in but not checked-out)
            // Cari attendance aktif terbaru tanpa membatasi ke tanggal server hari ini,
            // supaya tetap ketemu meskipun device time beda hari (lebih maju / mundur).
            $attendance = Attendance::where('user_id', $user->id)
                ->whereNotNull('check_in_at')
                ->whereNull('check_out_at')
                ->orderBy('date', 'desc')
                ->orderBy('check_in_at', 'desc')
                ->with('assignment', 'post')
                ->first();
        }

        if (!$attendance) {
            return response()->json(['message' => 'Attendance tidak valid'], 404);
        }

        // Attendance harus aktif untuk check-out (sudah check-in & belum check-out)
        if (!$attendance->check_in_at || $attendance->check_out_at) {
            return response()->json(['message' => 'Attendance tidak valid'], 400);
        }

        // ========= AUTHORIZATION: User hanya bisa checkout attendance miliknya
        $this->authorize('checkout', $attendance);

        // GUARD: Harus sudah check-in dulu
        if (!$attendance->check_in_at) {
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
        if (!$project || !$project->location_latitude || !$project->location_longitude) {
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
                'distance' => round($distance, 2) . ' meters',
                'allowed_radius' => $globalRadius . ' meters',
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
            $endTime = Carbon::createFromFormat('Y-m-d H:i:s', $attendance->date->format('Y-m-d') . ' ' . $assignment->end_time, $projectTimezone);
            $endTime->setTimezone('UTC');

            // Handle midnight shift - gunakan COPY untuk perbandingan, jangan ubah original
            $startTime = Carbon::createFromFormat('Y-m-d H:i:s', $attendance->date->format('Y-m-d') . ' ' . $assignment->start_time, $projectTimezone);
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

    // Helper function to calculate distance between two coordinates (Haversine formula)
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
        if (!$post && $attendance->isCommanderAttendance()) {
            $post = $attendance->project?->posts()->where('type', 'static')->orderBy('id')->first();
        }
        $assignment = $attendance->assignment;
        $project = $attendance->project;
        $timezone = $project->timezone ?? $project->organization->timezone ?? 'Asia/Jakarta';
        
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
                    'code' => $assignment->code,  // P (Pagi), M (Malam), O (OFF)
                    'name' => $assignment->name,
                    'start_time' => $assignment->start_time,
                    'end_time' => $assignment->end_time,
                    'grace_period' => $assignment->grace_period . ' minutes',
                    'is_off_duty' => $assignment->isOffDuty(),
                ],
                'post' => [
                    'id' => $post?->id,
                    'name' => $post?->name,
                    'type' => $post?->type,
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
        if ($attendance->check_in_at && !$attendance->check_out_at) {
            return false; // Sudah attend, waiting for check-out
        }

        // Belum check-in = bisa attend
        return true;
    }
}
