<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DailyReportUniformCheck extends Model
{
    use HasFactory;

    protected $fillable = [
        'uniform_personnel_id',
        'uniform_component_id',
        'status',
    ];

    public function uniformPersonnel()
    {
        return $this->belongsTo(
            DailyReportUniformPersonnel::class,
            'uniform_personnel_id'
        );
    }

    public function component()
    {
        return $this->belongsTo(
            UniformComponent::class,
            'uniform_component_id'
        );
    }
}