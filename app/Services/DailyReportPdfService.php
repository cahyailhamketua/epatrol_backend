<?php

namespace App\Services;

use App\Models\DailyReport;
use App\Models\Project;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DailyReportPdfService
{
    public function generateAndSave(DailyReport $report, Project $project): string
    {
        $report->loadMissing([
            'project.organization',
            'creator',
            'personnelConditions.user',
            'uniformPersonnels.user',
            'uniformPersonnels.checks.component',
            'equipmentChecks.equipmentComponent',
        ]);

        $directory = 'daily-reports/project-'.$project->id;
        Storage::disk('public')->makeDirectory($directory);

        $datePart = Carbon::parse($report->report_date)->format('Ymd');
        $reporterName = $report->creator?->full_name ?: (string) $report->user_id;
        $fileName = 'daily-report-'.$datePart.'-'.Str::slug($reporterName).'.pdf';
        $pdfPath = $directory.'/'.$fileName;

        $presentPersonnel = $this->getPresentPersonnelRows($project, $report->report_date);
        $latePersonnel = $presentPersonnel->filter(fn ($row) => (int) ($row['late_minutes'] ?? 0) != 0);

        $logoUrl = null;
        if ($project->organization?->logo && Storage::disk('public')->exists($project->organization->logo)) {
            $logoPath = Storage::disk('public')->path($project->organization->logo);
            $logoUrl = 'file://'.$logoPath;
        }

        $html = view('pdf.daily-report', [
            'report' => $report,
            'project' => $project,
            'organization' => $project->organization,
            'logoUrl' => $logoUrl,
            'presentPersonnel' => $presentPersonnel,
            'latePersonnel' => $latePersonnel,
        ])->render();

        $pdf = Pdf::loadHTML($html)
            ->setPaper('a4', 'portrait');

        Storage::disk('public')->put($pdfPath, $pdf->output());

        return $pdfPath;
    }

    protected function getPresentPersonnelRows(Project $project, mixed $reportDate): Collection
    {
        $attendanceRows = $project->attendances()
            ->with('user')
            ->whereDate('date', Carbon::parse($reportDate)->toDateString())
            ->whereIn('attendance_status', ['HADIR', 'HADIR TELAT'])
            ->get();

        return $attendanceRows->map(function ($attendance) {
            return [
                'user_id' => $attendance->user_id,
                'user_name' => $attendance->user?->full_name ?? $attendance->user?->name ?? '-',
                'attendance_status' => $attendance->attendance_status,
                'late_minutes' => abs((int) ($attendance->late_minutes ?? 0)),
            ];
        })->values();
    }
}
