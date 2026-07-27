<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class UniformComponent extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'name',
        'sort_order',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function checks()
    {
        return $this->hasMany(
            DailyReportUniformCheck::class
        );
    }
}