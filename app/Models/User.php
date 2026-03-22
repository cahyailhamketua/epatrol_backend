<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, HasApiTokens, Notifiable;
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'organization_id',
        'project_id',
        'full_name',
        'username',
        'email',
        'phone',
        'role',
        'password',
        'avatar',
        'nik',
        'npwp',
        'bpjs_kesehatan',
        'bpjs_ketenagakerjaan',
        'bank_name',
        'bank_account',
        'join_date',
        'active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

        /* ================= RELATIONS ================= */

        public function organization()
        {
            return $this->belongsTo(Organization::class);
        }
    
        public function project()
        {
            return $this->belongsTo(Project::class);
        }
    
        public function schedules()
        {
            return $this->hasMany(Schedule::class);
        }
    
        public function attendances()
        {
            return $this->hasMany(Attendance::class);
        }

        public function absences()
        {
            return $this->hasManyThrough(Absence::class, Schedule::class);
        }

        public function overtimeLogs()
        {
            return $this->hasMany(OvertimeLog::class);
        }

        public function payrollDetails()
        {
            return $this->hasMany(PayrollDetail::class);
        }

        public function approvedOvertimeLogs()
        {
            return $this->hasMany(OvertimeLog::class, 'approved_by');
        }

        public function approvedPayrollRuns()
        {
            return $this->hasMany(PayrollRun::class, 'finalized_by');
        }

        public function teams()
    {
        return $this->belongsToMany(Team::class,'team_users')
            ->withPivot('start_date','end_date')
            ->withTimestamps();
    }

    public function teamMemberships()
    {
        return $this->hasMany(TeamUser::class);
    }
}
