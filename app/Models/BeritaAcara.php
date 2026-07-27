<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class BeritaAcara extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'created_by',
        'document_number',
        'sequence_number',
        'incident_date',
        'incident_time',
        'subject',
        'location',
        'description',
        'chronologies',
        'actions_taken',
        'inspector_name',
        'inspector_position',
        'acknowledged_by',
        'acknowledged_position',
        'pdf_path',
    ];

    protected $casts = [
        'incident_date' => 'date',
        'chronologies' => 'array',
        'actions_taken' => 'array',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeByProject($query, $projectId)
    {
        return $query->where('project_id', $projectId);
    }

    public function scopeByMonth($query, $month)
    {
        return $query->whereYear('incident_date', substr($month, 0, 4))
            ->whereMonth('incident_date', substr($month, 5, 2));
    }
}