<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PatrolPoint extends Model
{
    use HasFactory;

    protected $fillable = [
        'post_id',
        'name',
        'sequence_order',
        'latitude',
        'longitude',
        'radius',
    ];

    public function post()
    {
        return $this->belongsTo(Post::class);
    }

    public function qrCode()
    {
        return $this->hasOne(QrCode::class);
    }
}
