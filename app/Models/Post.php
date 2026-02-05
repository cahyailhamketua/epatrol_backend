<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Post extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'name',
        'type',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function patrolPoints()
    {
        return $this->hasMany(PatrolPoint::class)
            ->orderBy('sequence_order');
    }

    public function activities()
    {
        return $this->hasMany(Activity::class);
    }

    public function schedules()
    {
        return $this->hasMany(Schedule::class);
    }
}
