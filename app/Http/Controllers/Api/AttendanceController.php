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
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AttendanceController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:api');
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

    public function checkIn(Request $request)
    {
        // ========= AUTHORIZATION: User harus bisa create attendance
        $this->authorize('create', Attendance::class);

        $validator = Validator::make($request->all(), [
            'project_id' => 'required|exists:projects,id',
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'current_time' => 'required|date_format:Y-m-d H:i:s', // Device time
            'selfie_photo' => 'required|image|max:1024',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $user = Auth::user();
        $today = Carbon::today();
        $now = Carbon::createFromFormat('Y-m-d H:i:s', $request->current_time); // DEVICE TIME

        // GUARD 1: Cegah double check-in
        $existingAttendance = Attendance::where('user_id', $user->id)
            ->where('date', $today)
            ->whereNotNull('check_in_at')
            ->first();

        if ($existingAttendance) {
            return response()->json(['message' => 'Anda sudah absen masuk hari ini.'], 409);
        }

        // GUARD 2: Wajib punya schedule hari ini
        // ========== EFFICIENT DATA LOADING PATTERN ==========
        // SINGLE Query dengan Eager Load: Schedule + Assignment + Post + Project
        // Ini lebih efisien daripada query terpisah (1 query vs 4+ queries)
        // Dari Schedule kita dapat:
        //   - assignment.code (P/M/O)  
        //   - assignment.start_time, end_time, grace_period
        //   - post.name, post.type (mobile/static)
        //   - post.latitude, post.longitude (jika mobile post)
        //   - project.location_latitude, location_longitude (reference point)
        //   - project.radius (geofence radius)
        $schedule = Schedule::where('user_id', $user->id)
            ->where('date', $today)
            ->with('assignment', 'post', 'project')
            ->first();

        if (!$schedule) {
            return response()->json([
                'message' => 'Anda tidak memiliki jadwal hari ini.',
                'date' => $today->format('Y-m-d'),
            ], 403);
        }

        // Unpack semua data dari single schedule query
        $assignment = $schedule->assignment;
        $post = $schedule->post;
        $project = $schedule->project;

        // GUARD 3: Cegah attendance jika absence APPROVED
        $approvedAbsence = Absence::where('user_id', $user->id)
            ->where('schedule_id', $schedule->id)
            ->where('date', $today)
            ->where('status', 'APPROVED')
            ->first();

        if ($approvedAbsence) {
            return response()->json([
                'message' => 'Anda telah disetujui untuk ' . $approvedAbsence->absence_type . '. Tidak dapat absen masuk.',
                'absence_type' => $approvedAbsence->absence_type,
            ], 403);
        }

        // GUARD 4: Validasi Assignment O (OFF) - harus ada overtime APPROVED
        if ($assignment->isOffDuty()) {
            $approvedOvertime = OvertimeLog::where('user_id', $user->id)
                ->where('schedule_id', $schedule->id)
                ->where('date', $today)
                ->where('status', 'APPROVED')
                ->first();

            if (!$approvedOvertime) {
                return response()->json([
                    'message' => 'Hari ini adalah hari OFF. Memerlukan persetujuan lembur terlebih dahulu.',
                    'code' => $assignment->code,
                ], 403);
            }

            // Jika ada approved overtime, gunakan planned_start_time sebagai acuan
            $startTime = Carbon::createFromTimeString($approvedOvertime->planned_start_time);
        } else {
            $startTime = Carbon::createFromTimeString($assignment->start_time);
        }

        // ========== LOCATION VERIFICATION ==========
        // Reference location: Diambil dari project (fixed office location)
        // Device location: Dikirim dari HP/Laptop user saat check-in (dynamic)
        // Kalkulasi: Jarak antara reference location dan device location harus <= radius
        // 
        // Location logic:
        // - PROJECT location: Fixed reference point (dari database)
        // - POST type: Determine jika ada special location rules (mobile/static)
        // - DEVICE location: Current user position dari HP/request
        
        $globalRadius = $project->radius ?? 100; // Radius project (default 100m)
        
        // DEVICE location (dari HP/Laptop user saat check-in)
        $deviceLatitude = $request->latitude;
        $deviceLongitude = $request->longitude;
        
        // REFERENCE location: Coming from PROJECT (fixed office location)
        $referenceLatitude = $project->location_latitude;
        $referenceLongitude = $project->location_longitude;
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
        // Kalkulasi: current_time harus antara start_time dan (start_time + grace_period * 2)
        
        $gracePeriod = $assignment->grace_period ?? 15; // Grace period dari assignment (menit)
        $graceDeadline = $startTime->copy()->addMinutes($gracePeriod); // Batas akhir tanpa telat
        $absoluteDeadline = $startTime->copy()->addMinutes($gracePeriod * 2); // Batas absolute (tetap bisa check-in)

        $lateMinutes = 0;
        $attendanceStatus = 'HADIR';
        $computedStatus = 'HADIR';

        if ($now->isBefore($startTime)) {
            // Belum saatnya check-in (terlalu pagi)
            return response()->json([
                'message' => 'Belum waktunya absen masuk.',
                'assignment' => [
                    'code' => $assignment->code,
                    'start_time' => $startTime->format('H:i:s'),
                ],
                'your_time' => $now->format('H:i:s'),
                'wait_until' => $startTime->format('H:i:s'),
            ], 403);
        } elseif ($now->isAfter($absoluteDeadline)) {
            // Sudah lewat batas absolute (terlalu lambat)
            return response()->json([
                'message' => 'Waktu absen masuk telah berakhir.',
                'assignment' => [
                    'code' => $assignment->code,
                    'start_time' => $startTime->format('H:i:s'),
                ],
                'allowed_deadline' => $absoluteDeadline->format('H:i:s'),
                'your_time' => $now->format('H:i:s'),
            ], 403);
        } elseif ($now->isAfter($graceDeadline)) {
            // Telat, tapi masih bisa check-in
            $lateMinutes = $now->diffInMinutes($startTime);
            $attendanceStatus = 'HADIR TELAT';
            $computedStatus = 'HADIR TELAT';
        }

        // Handle selfie photo upload
        $selfiePath = $request->file('selfie_photo')->store('attendances/selfies', 'public');

        // Create attendance
        $attendance = Attendance::create([
            'project_id' => $request->project_id,
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'assignment_id' => $assignment->id,
            'post_id' => $post->id,
            'date' => $today,
            'check_in_at' => $now,
            'checkin_lat' => $request->latitude,
            'checkin_lng' => $request->longitude,
            'reference_lat' => $referenceLatitude,
            'reference_lng' => $referenceLongitude,
            'attendance_status' => $attendanceStatus,
            'computed_status' => $computedStatus,
            'late_minutes' => $lateMinutes,
            'overtime_minutes' => 0,
            'overtime_status' => 'NONE',
            'selfie_photo_path' => $selfiePath,
        ]);

        return response()->json([
            'message' => 'Absen masuk berhasil.',
            'data' => $this->formatAttendanceResponse($attendance),
        ], 201);
    }

    public function patrolScan(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'attendance_id' => 'required|exists:attendances,id',
            'patrol_point_id' => 'required|exists:patrol_points,id',
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'photos' => 'required|array|min:4',
            'photos.*' => 'image|max:1024',
            'description_option' => 'required|string|in:aman,ada kendala',
            'notes' => 'nullable|string',
            'current_time' => 'required|date_format:Y-m-d H:i:s', // Device time
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $user = Auth::user();
        $attendance = Attendance::where('id', $request->attendance_id)
                                ->where('user_id', $user->id)
                                ->first();

        if (!$attendance) {
            return response()->json(['message' => 'Absensi tidak ditemukan atau tidak milik Anda.'], 404);
        }

        // ========= AUTHORIZATION: User hanya bisa scan patrol untuk attendance miliknya
        $this->authorize('patrolScan', $attendance);

        $post = $attendance->post;
        if ($post->type !== 'mobile') {
            return response()->json(['message' => 'Patrol scan hanya untuk pos mobile.'], 403);
        }

        $patrolPoint = PatrolPoint::find($request->patrol_point_id);
        if (!$patrolPoint) {
            return response()->json(['message' => 'Titik patroli tidak ditemukan.'], 404);
        }

        // Verify patrol point belongs to the post
        if ($patrolPoint->post_id !== $post->id) {
            return response()->json(['message' => 'Titik patroli tidak sesuai dengan pos yang dipilih.'], 403);
        }

        // Location verification for patrol point (dummy for now)
        $patrolPointRadius = 50; // This should come from patrol point settings
        $distance = $this->calculateDistance($patrolPoint->latitude, $patrolPoint->longitude, $request->latitude, $request->longitude);

        if ($distance > $patrolPointRadius) {
            return response()->json(['message' => 'Anda berada di luar radius titik patroli.'], 403);
        }

        // Sequence order verification
        $lastPatrolScan = PatrolScan::where('attendance_id', $attendance->id)
                                    ->orderBy('sequence_order', 'desc')
                                    ->first();

        $expectedSequence = $lastPatrolScan ? $lastPatrolScan->patrolPoint->sequence_order + 1 : 1;

        if ($patrolPoint->sequence_order !== $expectedSequence) {
            return response()->json(['message' => 'Titik patroli harus discan secara berurutan. Titik selanjutnya adalah ' . $expectedSequence], 403);
        }

        // Create patrol scan entry
        $patrolScan = PatrolScan::create([
            'attendance_id' => $attendance->id,
            'patrol_point_id' => $request->patrol_point_id,
            'scan_time' => Carbon::createFromFormat('Y-m-d H:i:s', $request->current_time), // DEVICE TIME
            'notes' => $request->notes,
            'description_option' => $request->description_option,
            'latitude' => $request->latitude,
            'longitude' => $request->longitude,
            'sequence_order' => $patrolPoint->sequence_order,
        ]);

        // Handle photo uploads
        foreach ($request->file('photos') as $photo) {
            $path = $photo->store('patrol_scans', 'public');
            $patrolScan->photos()->create(['path' => $path]); // Assuming PatrolScan has a photos relationship
        }

        return response()->json(['message' => 'Scan titik patroli berhasil.', 'patrol_scan' => $patrolScan], 201);
    }

    public function checkOut(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'attendance_id' => 'required|exists:attendances,id',
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'current_time' => 'required|date_format:Y-m-d H:i:s', // Device time
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $user = Auth::user();
        $attendance = Attendance::where('id', $request->attendance_id)
            ->where('user_id', $user->id)
            ->with('assignment', 'post')
            ->first();

        if (!$attendance) {
            return response()->json(['message' => 'Absensi tidak ditemukan.'], 404);
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
        $now = Carbon::createFromFormat('Y-m-d H:i:s', $request->current_time); // DEVICE TIME

        // ========== LOCATION VERIFICATION for CHECK-OUT ==========
        // Same reference location logic as check-in
        $globalRadius = $project->radius ?? 100;
        
        $deviceLatitude = $request->latitude;
        $deviceLongitude = $request->longitude;
        
        // REFERENCE location: Prioritas Assignment > Post > Project
        $referenceLatitude = null;
        $referenceLongitude = null;
        
        if ($assignment->latitude && $assignment->longitude) {
            $referenceLatitude = $assignment->latitude;
            $referenceLongitude = $assignment->longitude;
        } elseif ($post && $post->latitude && $post->longitude) {
            $referenceLatitude = $post->latitude;
            $referenceLongitude = $post->longitude;
        } else {
            $referenceLatitude = $project->location_latitude;
            $referenceLongitude = $project->location_longitude;
        }
        
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

        // Tentukan end_time (bisa dari overtime atau assignment)
        if ($assignment->isOffDuty()) {
            $overtimeLog = OvertimeLog::where('schedule_id', $attendance->schedule_id)
                ->where('date', $attendance->date)
                ->where('status', 'APPROVED')
                ->first();

            if (!$overtimeLog) {
                return response()->json(['message' => 'Data overtime tidak ditemukan.'], 404);
            }

            $endTime = Carbon::createFromTimeString($overtimeLog->planned_end_time);
        } else {
            $endTime = Carbon::createFromTimeString($assignment->end_time);
        }

        // Handle midnight shift
        $startTime = Carbon::createFromTimeString($assignment->start_time);
        if ($endTime->lessThanOrEqualTo($startTime)) {
            $endTime->addDay();
        }

        if ($now->isBefore($endTime)) {
            return response()->json([
                'message' => 'Belum waktunya absen pulang.',
                'end_time' => $endTime->format('H:i:s'),
                'current_time' => $now->format('H:i:s'),
            ], 403);
        }

        // Verify all patrol scans completed (for mobile posts)
        if ($post->type === 'mobile') {
            $totalPoints = PatrolPoint::where('post_id', $post->id)->count();
            $scannedPoints = PatrolScan::where('attendance_id', $attendance->id)->count();

            if ($scannedPoints < $totalPoints) {
                return response()->json([
                    'message' => 'Anda harus menyelesaikan semua scan titik patroli.',
                    'scanned' => $scannedPoints,
                    'total' => $totalPoints,
                ], 403);
            }
        }

        // Calculate overtime_minutes jika ada
        $overtimeMinutes = 0;
        $overtimeStatus = 'NONE';

        if ($now->isAfter($endTime)) {
            $overtimeMinutes = $now->diffInMinutes($endTime);
            // Status tetap NONE sampai di-approve via OvertimeLog
            $overtimeStatus = 'NONE';
        }

        // Update computed_status
        $computedStatus = $attendance->computed_status; // Start dari status check-in

        if ($overtimeMinutes > 0 && strpos($computedStatus, 'LEMBUR') === false) {
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

        return response()->json([
            'message' => 'Absen pulang berhasil.',
            'data' => $this->formatAttendanceResponse($attendance->fresh()),
        ], 200);
    }

    /**
     * SHOW attendance dengan assignment details
     * GET /attendances/{attendance}
     */
    public function show(Attendance $attendance)
    {        // ========= AUTHORIZATION: User hanya bisa lihat attendance yang relevant        $this->authorize('view', $attendance);

        $attendance->load('assignment', 'post', 'schedule');

        return response()->json([
            'data' => $this->formatAttendanceResponse($attendance),
        ]);
    }

    // Helper function to calculate distance between two coordinates (Haversine formula)
    private function calculateDistance($lat1, $lon1, $lat2, $lon2)
    {
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
        
        return [
            'id' => $attendance->id,
            'user_id' => $attendance->user_id,
            'date' => $attendance->date->format('Y-m-d'),
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
                    'id' => $post->id,
                    'name' => $post->name,      // Pos Gate, Pos Utama, Patroli Mobile
                    'type' => $post->type,      // static atau mobile
                ],
            ],
            'timing' => [
                'check_in_at' => $attendance->check_in_at?->format('H:i:s'),
                'check_out_at' => $attendance->check_out_at?->format('H:i:s'),
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
