<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PayrollUserTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'user_id',
        'component_key',
        'component_name',
        'component_group',
        'amount',
        'is_active',
        'last_used_period',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
