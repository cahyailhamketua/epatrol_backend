<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DailyReportPersonnelCondition extends Model
{
    use HasFactory;

    protected $fillable = [
        'daily_report_id',
        'user_id',
        'position',
        'physical_condition',
        'remarks',
    ];

    public function dailyReport()
    {
        return $this->belongsTo(DailyReport::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}