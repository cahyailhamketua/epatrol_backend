<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Services\QrCodeService;

class PatrolPoint extends Model
{
    use HasFactory;

    protected $fillable = [
        'post_id',
        'name',
        'sequence_order',
        'latitude',
        'longitude',
        'altitude',
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

    protected static function booted()
    {
        static::created(function (PatrolPoint $point) {
            $point->qrCode()->create([
                'code' => QrCodeService::generate(),
                'active' => true,
            ]);
        });
    }
}
