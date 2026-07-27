<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'name',
        'code',
        'logo',
        'location_latitude',
        'location_longitude',
        'location_address',
        'location_city',
        'timezone',
        'radius',
        'active',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function posts()
    {
        return $this->hasMany(Post::class);
    }

    public function shifts()
    {
        return $this->hasMany(Shift::class);
    }

    public function activities()
    {
        return $this->hasMany(Activity::class);
    }

    public function assignments()
    {
        return $this->hasMany(Assignment::class);
    }

    public function schedules()
    {
        return $this->hasMany(Schedule::class);
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class);
    }

    public function teams()
    {
        return $this->hasMany(Team::class);
    }

    public function payrollRuns()
    {
        return $this->hasMany(PayrollRun::class);
    }

    public function payrollTemplates()
    {
        return $this->hasMany(PayrollUserTemplate::class);
    }

    public function payrollProjectRule()
    {
        return $this->hasOne(PayrollProjectRule::class);
    }

    public function dailyReports()
    {
        return $this->hasMany(DailyReport::class);
    }

    public function uniformComponents()
    {
        return $this->hasMany(UniformComponent::class);
    }

    public function equipmentComponents()
    {
        return $this->hasMany(EquipmentComponent::class);
    }

    public function beritaAcaras()
    {
        return $this->hasMany(BeritaAcara::class);
    }

    public function documentTypes()
    {
        return $this->hasMany(DocumentType::class);
    }

    public function documents()
    {
        return $this->hasMany(Document::class);
    }
}
