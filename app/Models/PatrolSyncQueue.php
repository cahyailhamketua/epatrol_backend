<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PatrolSyncQueue extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'attendance_id',
        'qr_code_id',
        'qr_code',
        'scan_latitude',
        'scan_longitude',
        'scan_altitude',
        'note',
        'scan_time_device',
        'scan_time_utc',
        'photo_data',
        'status',
        'error_message',
        'retry_count',
        'last_retry_at',
        'patrol_scan_id',
    ];

    protected $casts = [
        'scan_time_device' => 'datetime',
        'scan_time_utc' => 'datetime',
        'photo_data' => 'json',
        'last_retry_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function attendance()
    {
        return $this->belongsTo(Attendance::class);
    }

    public function qrCode()
    {
        return $this->belongsTo(QrCode::class);
    }

    public function patrolScan()
    {
        return $this->belongsTo(PatrolScan::class, 'patrol_scan_id');
    }

    /**
     * Scope untuk pending sync
     */
    public function scopePending($query)
    {
        return $query->whereIn('status', ['pending', 'failed'])->where('retry_count', '<', 3);
    }

    /**
     * Scope untuk synced items
     */
    public function scopeSynced($query)
    {
        return $query->where('status', 'synced');
    }

    /**
     * Mark as synced dengan patrol_scan_id
     */
    public function markAsSynced($patrolScanId)
    {
        $this->update([
            'status' => 'synced',
            'patrol_scan_id' => $patrolScanId,
            'error_message' => null,
        ]);
    }

    /**
     * Mark as failed dengan error message
     */
    public function markAsFailed($errorMessage)
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $errorMessage,
            'retry_count' => $this->retry_count + 1,
            'last_retry_at' => now(),
        ]);
    }
}
