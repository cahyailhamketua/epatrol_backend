<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PatrolScan extends Model
{
    use HasFactory;

    protected $fillable = [
        'attendance_id',
        'qr_code_id',
        'scan_time',
        'note',
    ];

    protected $casts = [
        'scan_time' => 'datetime',
    ];

    public function attendance()
    {
        return $this->belongsTo(Attendance::class);
    }

    public function qrCode()
    {
        return $this->belongsTo(QrCode::class);
    }

    public function photos()
    {
        return $this->hasMany(PatrolScanPhoto::class);
    }
}
