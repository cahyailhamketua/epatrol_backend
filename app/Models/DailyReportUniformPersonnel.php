<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DailyReportUniformPersonnel extends Model
{
    use HasFactory;

    protected $fillable = [
        'daily_report_id',
        'user_id',
        'overall_status',
        'notes',
    ];

    public function dailyReport()
    {
        return $this->belongsTo(DailyReport::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function checks()
    {
        return $this->hasMany(
            DailyReportUniformCheck::class,
            'uniform_personnel_id'
        );
    }
}