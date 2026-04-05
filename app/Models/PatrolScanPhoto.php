<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PatrolScanPhoto extends Model
{
    use HasFactory;

    protected $fillable = [
        'patrol_scan_id',
        'photo',
    ];

    public function patrolScan()
    {
        return $this->belongsTo(PatrolScan::class);
    }
}
