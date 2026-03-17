<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TemplateSchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'team_id',
        'pattern',
        'start_date',
    ];

    protected $casts = [
        'pattern' => 'array',
        'start_date' => 'date',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }
}
