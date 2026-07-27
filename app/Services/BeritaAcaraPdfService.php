<?php

namespace App\Services;

use App\Models\BeritaAcara;
use App\Models\Project;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BeritaAcaraPdfService
{
    public function generateAndSave(
        BeritaAcara $beritaAcara,
        Project $project
    ): string {

        $beritaAcara->loadMissing([
            'creator',
            'project.organization',
        ]);

        $directory =
            'berita-acaras/project-' .
            $project->id;

        Storage::disk('public')
            ->makeDirectory($directory);

        $formattedDocumentNumber = str_replace(['/', '\\'], '-', $beritaAcara->document_number);

        $fileName =
            'berita-acara-' .
            $formattedDocumentNumber . '.pdf';

        $pdfPath =
            $directory .
            '/' .
            $fileName;

        $organizationLogo = null;

        if (
            $project->organization?->logo &&
            Storage::disk('public')->exists(
                $project->organization->logo
            )
        ) {
            $organizationLogo =
                'file://' .
                Storage::disk('public')->path(
                    $project->organization->logo
                );
        }

        $projectLogo = null;

        if (
            $project->logo &&
            Storage::disk('public')->exists(
                $project->logo
            )
        ) {
            $projectLogo =
                'file://' .
                Storage::disk('public')->path(
                    $project->logo
                );
        }

        $html = view(
            'pdf.berita-acara',
            [
                'beritaAcara' => $beritaAcara,
                'project' => $project,
                'organizationLogo' =>
                    $organizationLogo,
                'projectLogo' =>
                    $projectLogo,
            ]
        )->render();

        $pdf = Pdf::loadHTML($html)
            ->setPaper(
                'a4',
                'portrait'
            );

        Storage::disk('public')->put(
            $pdfPath,
            $pdf->output()
        );

        return $pdfPath;
    }
}