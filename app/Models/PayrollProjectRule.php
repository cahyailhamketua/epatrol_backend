<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollProjectRule extends Model
{
    protected $fillable = [
        'project_id',
        'backup_rate',
        'potongan_sakit',
        'potongan_izin',
        'potongan_cuti',
        'potongan_alpha',
        'potongan_soc_a',
    ];

    protected $casts = [
        'backup_rate' => 'decimal:2',
        'potongan_sakit' => 'decimal:2',
        'potongan_izin' => 'decimal:2',
        'potongan_cuti' => 'decimal:2',
        'potongan_alpha' => 'decimal:2',
        'potongan_soc_a' => 'decimal:2',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }
}
