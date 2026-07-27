<?php

namespace App\Http\Requests;

use App\Models\BeritaAcara;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BeritaAcaraStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(
            'create',
            BeritaAcara::class
        ) ?? false;
    }

    public function rules(): array
    {
        return [
            'document_number' => [
                'required',
                'string',
                'max:255',
                Rule::unique(
                    'berita_acaras',
                    'document_number'
                ),
            ],

            'incident_date' => [
                'required',
                'date_format:Y-m-d',
            ],

            'incident_time' => [
                'required',
                'date_format:H:i',
            ],

            'subject' => [
                'required',
                'string',
                'max:255',
            ],

            'location' => [
                'required',
                'string',
                'max:255',
            ],

            'description' => [
                'required',
                'string',
            ],

            'chronologies' => [
                'nullable',
                'array',
            ],

            'chronologies.*' => [
                'required',
                'string',
            ],

            'actions_taken' => [
                'nullable',
                'array',
            ],

            'actions_taken.*' => [
                'required',
                'string',
            ],

            'inspector_name' => [
                'nullable',
                'string',
                'max:255',
            ],

            'inspector_position' => [
                'nullable',
                'string',
                'max:255',
            ],

            'acknowledged_by' => [
                'nullable',
                'string',
                'max:255',
            ],

            'acknowledged_position' => [
                'nullable',
                'string',
                'max:255',
            ],
        ];
    }
}