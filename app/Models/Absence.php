<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Absence extends Model
{
    use HasFactory;

    protected $fillable = [
        'schedule_id',
        'absence_type',
    ];

    /** Map huruf ke key summary / laporan */
    public const TYPE_TO_SUMMARY_KEY = [
        'C' => 'CUTI',
        'S' => 'SAKIT',
        'I' => 'IZIN',
        'A' => 'ALPA',
    ];

    public function schedule()
    {
        return $this->belongsTo(Schedule::class);
    }

    /** Label singkat untuk API / frontend */
    public function getLabelAttribute(): string
    {
        return match ($this->absence_type) {
            'C' => 'Cuti',
            'S' => 'Sakit',
            'I' => 'Izin',
            'A' => 'Alfa',
            default => $this->absence_type,
        };
    }
}
