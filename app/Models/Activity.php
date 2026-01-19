<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Activity extends Model
{
    use HasFactory;

    protected $fillable = [
        'post_id',
        'name',
        'start_time',
        'end_time',
        'active',
    ];

    public function post()
    {
        return $this->belongsTo(Post::class);
    }
}
