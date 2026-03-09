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
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AttendanceController extends Controller
{
    public function checkIn(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'project_id' => 'required|exists:projects,id',
            'post_id' => 'required|exists:posts,id',
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'selfie_photo' => 'required|image|max:1024', // Max 1MB
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $user = Auth::user();
        $today = Carbon::today();

        // Check if user already checked in today
        $existingAttendance = Attendance::where('user_id', $user->id)
            ->where('date', $today)
            ->whereNotNull('check_in_at')
            ->first();

        if ($existingAttendance) {
            return response()->json(['message' => 'Anda sudah absen masuk hari ini.'], 409);
        }

        $post = Post::find($request->post_id);
        if (!$post) {
            return response()->json(['message' => 'Pos tidak ditemukan.'], 404);
        }

        // Get user's schedule for today
        $schedule = Schedule::where('user_id', $user->id)
            ->where('date', $today)
            ->first();

        if (!$schedule) {
            return response()->json(['message' => 'Anda tidak memiliki jadwal hari ini.'], 403);
        }

        $assignment = Assignment::find($schedule->assignment_id);
        if (!$assignment) {
            return response()->json(['message' => 'Assignment tidak ditemukan.'], 404);
        }

        // Location verification (dummy for now, replace with actual radius check)
        // For example, if global radius is 100 meters
        $globalRadius = 100; // This should come from organization settings
        $distance = $this->calculateDistance($user->organization->latitude, $user->organization->longitude, $request->latitude, $request->longitude);

        if ($distance > $globalRadius) {
            return response()->json(['message' => 'Anda berada di luar radius absen masuk.'], 403);
        }

        // Time verification
        $checkInTime = Carbon::parse($assignment->start_time);
        $gracePeriod = 15; // minutes
        $currentTime = Carbon::now();
        $lateMinutes = 0;
        $attendanceStatus = 'hadir';

        if ($currentTime->gt($checkInTime->addMinutes($gracePeriod))) {
            // User is late, but still within allowed time to check in
            if ($currentTime->gt($checkInTime->addMinutes($gracePeriod)->addMinutes($gracePeriod))) { // Assuming double grace period for absolute cutoff
                return response()->json(['message' => 'Waktu absen masuk telah berakhir.'], 403);
            }
            $lateMinutes = $currentTime->diffInMinutes($checkInTime->subMinutes($gracePeriod));
            $attendanceStatus = 'telat';
        } elseif ($currentTime->lt($checkInTime)) {
             return response()->json(['message' => 'Belum waktunya absen masuk.'], 403);
        }
        

        // Handle selfie photo upload
        $selfiePath = $request->file('selfie_photo')->store('selfies', 'public');

        $attendance = Attendance::create([
            'project_id' => $request->project_id,
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'assignment_id' => $assignment->id,
            'post_id' => $request->post_id,
            'date' => $today,
            'check_in_at' => $currentTime,
            'checkin_lat' => $request->latitude,
            'checkin_lng' => $request->longitude,
            'attendance_status' => $attendanceStatus,
            'late_minutes' => $lateMinutes,
            'selfie_photo_path' => $selfiePath, // Add this field to your Attendance model
        ]);

        return response()->json(['message' => 'Absen masuk berhasil.', 'attendance' => $attendance], 201);
    }

    public function patrolScan(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'attendance_id' => 'required|exists:attendances,id',
            'patrol_point_id' => 'required|exists:patrol_points,id',
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'photos' => 'required|array|min:4',
            'photos.*' => 'image|max:1024', // Max 1MB per photo
            'description_option' => 'required|string|in:aman,ada kendala',
            'notes' => 'nullable|string',
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

        $post = $attendance->post;
        if ($post->category !== 'mobile') {
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
            'scan_time' => Carbon::now(),
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

        if ($attendance->check_out_at) {
            return response()->json(['message' => 'Anda sudah absen pulang.'], 409);
        }

        $assignment = $attendance->assignment;
        $checkOutTime = Carbon::parse($assignment->end_time);
        $currentTime = Carbon::now();

        if ($currentTime->lt($checkOutTime)) {
            return response()->json(['message' => 'Belum waktunya absen pulang.'], 403);
        }

        $post = $attendance->post;
        if ($post->category === 'mobile') {
            $totalPatrolPoints = PatrolPoint::where('post_id', $post->id)->count();
            $scannedPatrolPoints = PatrolScan::where('attendance_id', $attendance->id)->count();

            if ($scannedPatrolPoints < $totalPatrolPoints) {
                return response()->json(['message' => 'Anda harus menyelesaikan semua scan titik patroli sebelum absen pulang.'], 403);
            }
        }

        $attendance->update([
            'check_out_at' => $currentTime,
            'checkout_lat' => $request->latitude,
            'checkout_lng' => $request->longitude,
        ]);

        return response()->json(['message' => 'Absen pulang berhasil.', 'attendance' => $attendance], 200);
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
}
