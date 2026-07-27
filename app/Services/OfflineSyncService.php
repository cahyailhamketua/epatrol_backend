<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\PatrolScan;
use App\Models\PatrolSyncQueue;
use App\Models\AttendanceProgressSnapshot;
use App\Models\PatrolPoint;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class OfflineSyncService
{
    /**
     * Queue offline patrol scan untuk di-sync kemudian
     * Profesional offline-first approach
     */
    public function queueOfflineScan(
        Attendance $attendance,
        string $qrCode,
        float $latitude,
        float $longitude,
        ?float $altitude,
        ?string $note,
        Carbon $scanTimeUtc,
        array $photos = []
    ): array {
        try {
            // Validate attendance
            if (!$attendance->check_in_at || $attendance->check_out_at) {
                return [
                    'success' => false,
                    'message' => 'Attendance tidak valid untuk sync offline',
                ];
            }

            // Store photos sebagai base64 atau file references
            $photoData = [];
            foreach ($photos as $index => $photo) {
                if (is_file($photo) || $photo instanceof \Illuminate\Http\UploadedFile) {
                    $path = $photo->store('patrol-scans/queue', 'local');
                    $photoData[] = [
                        'type' => 'file_path',
                        'path' => $path,
                    ];
                } elseif (is_string($photo)) {
                    // Base64 encoded
                    $photoData[] = [
                        'type' => 'base64',
                        'data' => $photo,
                    ];
                }
            }

            // Create queue entry
            $syncQueue = PatrolSyncQueue::create([
                'user_id' => $attendance->user_id,
                'attendance_id' => $attendance->id,
                'qr_code' => $qrCode,
                'scan_latitude' => $latitude,
                'scan_longitude' => $longitude,
                'scan_altitude' => $altitude,
                'note' => $note,
                'scan_time_device' => now(), // Device time (local)
                'scan_time_utc' => $scanTimeUtc, // UTC untuk consistency
                'photo_data' => $photoData,
                'status' => 'pending',
            ]);

            Log::info('Offline scan queued', [
                'sync_queue_id' => $syncQueue->id,
                'attendance_id' => $attendance->id,
            ]);

            return [
                'success' => true,
                'message' => 'Scan berhasil disimpan offline. Akan disync otomatis saat online.',
                'sync_queue_id' => $syncQueue->id,
                'status' => 'pending',
            ];
        } catch (\Exception $e) {
            Log::error('Queue offline scan failed', [
                'attendance_id' => $attendance->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Gagal menyimpan scan offline',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Sync pending offline scans ketika device online
     * Gunakan transaction untuk atomicity
     */
    public function syncPendingScans(int $userId): array
    {
        $pending = PatrolSyncQueue::where('user_id', $userId)
            ->pending()
            ->get();
        
        if ($pending->isEmpty()) {
            return [
                'success' => true,
                'message' => 'Tidak ada scan yang perlu disync',
                'synced_count' => 0,
                'failed_count' => 0,
            ];
        }

        $syncedCount = 0;
        $failedCount = 0;

        foreach ($pending as $queue) {
            try {
                $result = $this->processSingleQueue($queue);
                if ($result['success']) {
                    $syncedCount++;
                } else {
                    $failedCount++;
                }
            } catch (\Exception $e) {
                Log::error('Sync queue item failed', [
                    'queue_id' => $queue->id,
                    'error' => $e->getMessage(),
                ]);
                $queue->markAsFailed($e->getMessage());
                $failedCount++;
            }
        }

        return [
            'success' => true,
            'message' => "Sync selesai. {$syncedCount} berhasil, {$failedCount} gagal.",
            'synced_count' => $syncedCount,
            'failed_count' => $failedCount,
        ];
    }

    /**
     * Process single sync queue item dengan transaction
     */
    private function processSingleQueue(PatrolSyncQueue $queue): array
    {
        return DB::transaction(function () use ($queue) {
            // Update status ke processing
            $queue->update(['status' => 'processing']);

            // Get attendance
            $attendance = $queue->attendance;
            if (!$attendance || !$attendance->check_in_at) {
                $queue->markAsFailed('Attendance tidak valid');
                return ['success' => false];
            }

            // Validate QR code
            $patrolScanService = app(PatrolScanService::class);
            $validation = $patrolScanService->validateQrCode($queue->qr_code, $attendance);
            if (!$validation['valid']) {
                $queue->markAsFailed(implode(', ', $validation['errors']));
                return ['success' => false];
            }

            // Create patrol scan (reuse service logic)
            $scanResult = $patrolScanService->createScan(
                $attendance,
                $queue->qr_code,
                $queue->scan_latitude,
                $queue->scan_longitude,
                $queue->scan_altitude,
                $queue->note,
                $queue->scan_time_utc,
                $this->reconstructPhotos($queue->photo_data)
            );

            if (!$scanResult['success']) {
                $queue->markAsFailed(implode(', ', $scanResult['errors']));
                return ['success' => false];
            }

            // Mark as synced
            $queue->markAsSynced($scanResult['scan']->id);

            Log::info('Offline scan synced successfully', [
                'sync_queue_id' => $queue->id,
                'patrol_scan_id' => $scanResult['scan']->id,
            ]);

            return ['success' => true];
        });
    }

    /**
     * Reconstruct photos dari stored data (file path atau base64)
     */
    private function reconstructPhotos(array $photoData): array
    {
        $photos = [];
        
        foreach ($photoData as $item) {
            if ($item['type'] === 'file_path' && Storage::disk('local')->exists($item['path'])) {
                $photos[] = Storage::disk('local')->path($item['path']);
            } elseif ($item['type'] === 'base64') {
                // Buat temporary file dari base64
                $content = base64_decode($item['data']);
                $tempPath = sys_get_temp_dir() . '/' . uniqid('patrol_') . '.jpg';
                file_put_contents($tempPath, $content);
                $photos[] = $tempPath;
            }
        }

        return $photos;
    }

    /**
     * Get pending sync status untuk UI
     */
    public function getPendingSyncStatus(): array
    {
        $pending = PatrolSyncQueue::where('user_id', auth()->id())
            ->pending()
            ->count();

        $synced = PatrolSyncQueue::where('user_id', auth()->id())
            ->synced()
            ->count();

        return [
            'pending_count' => $pending,
            'synced_count' => $synced,
            'last_sync_at' => PatrolSyncQueue::where('user_id', auth()->id())
                ->where('status', 'synced')
                ->latest('updated_at')
                ->first()?->updated_at,
        ];
    }

    /**
     * Create progress snapshot ketika session dimulai atau di-reset
     * Keep history tapi allow reset per assignment
     */
    public function createProgressSnapshot(
        Attendance $attendance,
        array $scanDetails = []
    ): AttendanceProgressSnapshot {
        $post = $attendance->post;
        $patrolPoints = $attendance->getPatrolPoints();
        
        $scannedCount = PatrolScan::where('attendance_id', $attendance->id)->count();
        $totalCount = $patrolPoints->count();

        return AttendanceProgressSnapshot::create([
            'attendance_id' => $attendance->id,
            'assignment_id' => $attendance->assignment_id,
            'project_id' => $attendance->project_id,
            'post_id' => $post?->id,
            'total_patrol_points' => $totalCount,
            'scanned_patrol_points' => $scannedCount,
            'progress_percentage' => $totalCount > 0 ? round(($scannedCount / $totalCount) * 100, 2) : 0,
            'snapshot_at' => now(),
            'scan_details' => $scanDetails,
            'snapshot_type' => 'session_start',
        ]);
    }

    /**
     * Update progress snapshot (pada session end)
     */
    public function updateProgressSnapshot(
        Attendance $attendance,
        ?AttendanceProgressSnapshot $snapshot = null
    ): AttendanceProgressSnapshot {
        if (!$snapshot) {
            $snapshot = AttendanceProgressSnapshot::where('attendance_id', $attendance->id)
                ->latest('created_at')
                ->first();
        }

        if (!$snapshot) {
            return $this->createProgressSnapshot($attendance);
        }

        $patrolPoints = $attendance->getPatrolPoints();
        $scannedCount = PatrolScan::where('attendance_id', $attendance->id)->count();
        $totalCount = $patrolPoints->count();

        $snapshot->update([
            'scanned_patrol_points' => $scannedCount,
            'progress_percentage' => $totalCount > 0 ? round(($scannedCount / $totalCount) * 100, 2) : 0,
            'snapshot_type' => 'session_end',
        ]);

        return $snapshot;
    }

    /**
     * Reset progress untuk assignment baru tapi keep history
     * Jangan delete, hanya create snapshot baru
     */
    public function resetProgressForNewAssignment(Attendance $attendance): AttendanceProgressSnapshot
    {
        // Create snapshot for previous state
        $this->updateProgressSnapshot($attendance);

        // Create new snapshot untuk assignment baru (ini automatically reset progress tracker)
        return $this->createProgressSnapshot($attendance);
    }
}
