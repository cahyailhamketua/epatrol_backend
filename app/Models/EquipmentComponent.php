<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class EquipmentComponent extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'name',
        'standard_quantity',
        'sort_order',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function checks()
    {
        return $this->hasMany(
            DailyReportEquipmentCheck::class
        );
    }
}