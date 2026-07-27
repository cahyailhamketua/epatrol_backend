<?php

namespace App\Http\Requests;

use App\Models\DailyReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DailyReportStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', DailyReport::class) ?? false;
    }

    public function rules(): array
    {
        $project = $this->route('project');
        $projectId = $project?->id;

        return [
            'report_date' => ['required', 'date_format:Y-m-d'],
            'bos_name' => ['required', 'string', 'max:255'],
            'bos_position' => ['required', 'string', 'max:255'],
            'shift' => ['nullable', 'string', 'max:255'],
            'total_personnel' => ['required', 'integer', 'min:0'],
            'present_personnel' => ['nullable', 'integer', 'min:0'],
            'absent_personnel' => ['nullable', 'array'],
            'absent_personnel.*.employee_name' => ['required', 'string', 'max:255'],
            'absent_personnel.*.reason' => ['nullable', 'string', 'max:255'],
            'absent_personnel.*.backup_name' => ['nullable', 'string', 'max:255'],
            'absent_personnel.*.origin' => ['nullable', 'string','max:255'],
            'general_information' => ['nullable', 'string'],
            'further_escalation' => ['nullable', 'string'],
            'incidents' => ['nullable', 'array'],
            'incidents.*.component' => ['nullable', 'string', 'max:225'],
            'incidents.*.description' => ['required_with:incidents.*.component', 'string'],
            'berita_acara' => ['nullable', 'array'],
            'berita_acara.*.berita_acara' => ['required', 'string', 'max:255'],
            'berita_acara.*.description' => ['required','string'],

            'new_uniform_components' => ['nullable', 'array'],
            'new_uniform_components.*' => ['required', 'string', 'max:255'],

            'uniform_personnels' => ['nullable', 'array'],
            'uniform_personnels.*.user_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query->where('project_id', $projectId)),
            ],
            'uniform_personnels.*.overall_status' => ['required', 'in:lengkap,tidak_lengkap'],
            'uniform_personnels.*.notes' => ['nullable', 'string'],
            'uniform_personnels.*.checks' => ['required', 'array'],
            'uniform_personnels.*.checks.*.uniform_component_id' => [
                'nullable',
                'integer',
                Rule::exists('uniform_components', 'id')->where(fn ($query) => $query->where('project_id', $projectId)),
            ],
            'uniform_personnels.*.checks.*.uniform_component_name' => ['nullable', 'string', 'max:255'],
            'uniform_personnels.*.checks.*.status' => ['required', 'in:ada,tidak_ada'],

            'new_equipment_components' => ['nullable', 'array'],
            'new_equipment_components.*.name' => ['required', 'string', 'max:255'],
            'new_equipment_components.*.standard_quantity' => ['required', 'integer', 'min:0'],

            'equipment_checks' => ['nullable', 'array'],
            'equipment_checks.*.equipment_component_id' => [
                'nullable',
                'integer',
                Rule::exists('equipment_components', 'id')->where(fn ($query) => $query->where('project_id', $projectId)),
            ],

            'equipment_checks.*.equipment_component_name' => [
                'nullable',
                'string',
                'max:255',
            ],
            'equipment_checks.*.standard_quantity' => [
                'nullable',
                'integer',
                'min:0',
            ],
            'equipment_checks.*.available_quantity' => ['required', 'integer', 'min:0'],
            'equipment_checks.*.condition' => ['required', 'in:baik,rusak,hilang'],
            'equipment_checks.*.remarks' => ['nullable', 'string'],

            'personnel_conditions' => ['nullable', 'array'],
            'personnel_conditions.*.user_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query->where('project_id', $projectId)),
            ],
            'personnel_conditions.*.position' => ['nullable', 'string', 'max:255'],
            'personnel_conditions.*.physical_condition' => ['required', 'in:baik,perlu_perhatian,tidak_fit'],
            'personnel_conditions.*.remarks' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'uniform_personnels.*.checks.required' => 'Checklist seragam wajib diisi untuk setiap personel.',
            'equipment_checks.*.condition.in' => 'Nilai condition harus salah satu dari baik, rusak, atau hilang.',
        ];
    }
}
