<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Activity extends Model
{
    use HasFactory;

    protected $fillable = [
        'post_id',
        'name',
        'location',
        'active',
        'project_id',
    ];

    public function post()
    {
        return $this->belongsTo(Post::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }
    
    public function assignmentTimes()
    {
        return $this->hasMany(ActivityAssignmentTime::class);
    }
}
