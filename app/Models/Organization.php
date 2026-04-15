<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Organization extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'logo',
        'code',
        'address',
        'timezone',
        'start_date',
        'end_date',
        'active',
    ];

    public function projects()
    {
        return $this->hasMany(Project::class);
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function scopeVisibleTo($query, User $user)
    {
        if ($user->role === 'dev') {
            return $query;
        }

        return $query->where('id', $user->organization_id);
    }
}
