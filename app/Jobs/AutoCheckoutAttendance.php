<?php

namespace App\Jobs;

use App\Models\Attendance;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Events\AttendanceUpdated;

class AutoCheckoutAttendance implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $attendanceId
    ) {}

    public function handle(): void
    {
        Log::info("AUTO CHECKOUT START: {$this->attendanceId}");

        $attendance = Attendance::with(['project'])
            ->find($this->attendanceId);

        // 🔒 Guard
        if (!$attendance) {
            Log::warning("AUTO CHECKOUT: attendance not found {$this->attendanceId}");
            return;
        }

        if ($attendance->check_out_at) {
            Log::info("AUTO CHECKOUT: already checked out {$this->attendanceId}");
            return;
        }

        if (!$attendance->check_in_at) {
            Log::warning("AUTO CHECKOUT: no check-in {$this->attendanceId}");
            return;
        }

        // ⏱️ Gunakan waktu SEKARANG (karena delay sudah benar dari controller)
        $nowUtc = now('UTC');

        // 💡 Overtime fix 120 menit (sesuai requirement)
        $overtimeMinutes = 120;
        $overtimeStatus = 'PENDING';

        // 🧠 Update computed_status
        $computedStatus = $attendance->computed_status;
        if ($overtimeMinutes > 0 && strpos($computedStatus, 'LEMBUR') === false) {
            $computedStatus .= ' LEMBUR';
        }

        // ✅ AUTO CHECKOUT
        $attendance->forceFill([
            'check_out_at' => $nowUtc,
            'checkout_lat' => $attendance->checkout_lat ?? $attendance->checkin_lat,
            'checkout_lng' => $attendance->checkout_lng ?? $attendance->checkin_lng,
            'overtime_minutes' => $overtimeMinutes,
            'overtime_status' => $overtimeStatus,
            'computed_status' => $computedStatus,
        ])->save();

        $attendance->refresh();

        // 🧹 Clear cache
        if ($attendance->project_id) {
            Cache::forever('project_reports_' . $attendance->project_id . '_v', time());
        }

        // Setelah $attendance->update([...])
        broadcast(new AttendanceUpdated(
            userId: $attendance->user_id,
            status: 'checkout',
            timestamp: now('UTC')->toIso8601String(),
            assignmentId: $attendance->assignment_id
        ))->onQueue('default');

        Log::info("AUTO CHECKOUT SUCCESS: {$this->attendanceId}");
    }
}