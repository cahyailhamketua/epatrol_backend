<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Team extends Model
{
    protected $fillable = [
        'project_id',
        'name',
        'leader_id'
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function leader()
    {
        return $this->belongsTo(User::class,'leader_id');
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'team_users')
            ->withPivot('start_date', 'end_date')
            ->withTimestamps();
    }

    public function schedules()
    {
        return $this->hasMany(Schedule::class);
    }
}
