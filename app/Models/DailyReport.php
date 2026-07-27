<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DailyReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'user_id',
        'report_date',
        'bos_name',
        'bos_position',
        'shift',
        'total_personnel',
        'present_personnel',
        'absent_personnel',
        'general_information',
        'further_escalation',
        'incidents',
        'berita_acara',
        'pdf_path',
    ];

    protected $casts = [
        'report_date' => 'date',
        'absent_personnel' => 'array',
        'incidents' => 'array',
        'berita_acara' => 'array',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function personnelConditions()
    {
        return $this->hasMany(
            DailyReportPersonnelCondition::class
        );
    }

    public function uniformPersonnels()
    {
        return $this->hasMany(
            DailyReportUniformPersonnel::class
        );
    }

    public function equipmentChecks()
    {
        return $this->hasMany(
            DailyReportEquipmentCheck::class
        );
    }

    public function scopeByProject($query, $projectId)
    {
        return $query->where('project_id', $projectId);
    }

    public function scopeByMonth($query, $month)
    {
        return $query->whereYear('report_date', substr($month, 0, 4))
            ->whereMonth('report_date', substr($month, 5, 2));
    }
}