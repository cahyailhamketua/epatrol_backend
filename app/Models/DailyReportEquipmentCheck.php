<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DailyReportEquipmentCheck extends Model
{
    use HasFactory;

    protected $fillable = [
        'daily_report_id',
        'equipment_component_id',
        'available_quantity',
        'condition',
        'remarks',
    ];

    public function dailyReport()
    {
        return $this->belongsTo(DailyReport::class);
    }

    public function equipmentComponent()
    {
        return $this->belongsTo(
            EquipmentComponent::class,
            'equipment_component_id'
        );
    }
}