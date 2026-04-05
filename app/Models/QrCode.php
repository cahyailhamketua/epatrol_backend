<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class QrCode extends Model
{
    use HasFactory;

    protected $fillable = [
        'patrol_point_id',
        'code',
        'active',
    ];

    public function patrolPoint()
    {
        return $this->belongsTo(PatrolPoint::class);
    }

    public function patrolScans()
    {
        return $this->hasMany(PatrolScan::class);
    }
}
