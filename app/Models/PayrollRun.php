<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PayrollRun extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_FINALIZED = 'FINALIZED';

    public const STATUS_PAID = 'PAID';

    public const STATUS_CANCELLED = 'CANCELLED';

    protected $fillable = [
        'project_id',
        'payroll_policy_id',
        'year',
        'month',
        'period',
        'pay_period_start',
        'pay_period_end',
        'status',
        'finalized_by',
        'finalized_at',
        'paid_at',
        'generated_at',
        'released_at',
        'total_employees',
        'total_payroll_amount',
        'total_deductions',
        'total_additions',
        'notes',
    ];

    protected $casts = [
        'pay_period_start' => 'date',
        'pay_period_end' => 'date',
        'finalized_at' => 'datetime',
        'paid_at' => 'datetime',
        'generated_at' => 'datetime',
        'released_at' => 'datetime',
        'total_payroll_amount' => 'decimal:2',
        'total_deductions' => 'decimal:2',
        'total_additions' => 'decimal:2',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function policy()
    {
        return $this->belongsTo(PayrollPolicy::class, 'payroll_policy_id');
    }

    public function finalizedBy()
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function payrollDetails()
    {
        return $this->hasMany(PayrollDetail::class);
    }

    // Scopes
    public function scopeDraft($query)
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    public function scopeFinalized($query)
    {
        return $query->where('status', self::STATUS_FINALIZED);
    }

    public function scopePaid($query)
    {
        return $query->where('status', self::STATUS_PAID);
    }

    public function scopeByProject($query, $projectId)
    {
        return $query->where('project_id', $projectId);
    }

    public function scopeByPeriod($query, $year, $month)
    {
        return $query->where('year', $year)->where('month', $month);
    }

    public function scopeByPeriodString($query, string $period)
    {
        return $query->where('period', $period);
    }

    // Accessors
    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isFinalized()
    {
        return $this->status === self::STATUS_FINALIZED;
    }

    public function isPaid()
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isCancelled()
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    // Helpers
    public function getPeriodLabel()
    {
        return "{$this->month}/{$this->year}";
    }

    public function getDaysInPeriod()
    {
        return $this->pay_period_end->diffInDays($this->pay_period_start) + 1;
    }
}
