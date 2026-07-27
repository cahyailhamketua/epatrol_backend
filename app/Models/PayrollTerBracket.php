<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollTerBracket extends Model
{
    protected $fillable = [
        'category',
        'ptkp_group',
        'min_income',
        'max_income',
        'rate',
        'sort_order',
    ];

    protected $casts = [
        'min_income' => 'decimal:2',
        'max_income' => 'decimal:2',
        'rate' => 'decimal:6',
    ];
}
