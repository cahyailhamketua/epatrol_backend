<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Document extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'document_type_id',
        'uploaded_by',
        'document_date',
        'file_name',
        'file_path',
    ];

    protected $casts = [
        'document_date' => 'date',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function documentType()
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeByProject($query, int $projectId)
    {
        return $query->where('project_id', $projectId);
    }

    public function scopeByType($query, int $typeId)
    {
        return $query->where('document_type_id', $typeId);
    }

    public function scopeByMonth($query, int $month)
    {
        return $query->whereMonth('document_date', $month);
    }

    public function scopeByYear($query, int $year)
    {
        return $query->whereYear('document_date', $year);
    }
}