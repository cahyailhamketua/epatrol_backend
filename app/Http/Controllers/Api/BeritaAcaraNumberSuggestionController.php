<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BeritaAcara;
use App\Models\Project;
use Carbon\Carbon;
use Illuminate\Http\Request;

class BeritaAcaraNumberSuggestionController extends Controller
{
    public function __invoke(Request $request, Project $project)
    {
        $this->authorize('viewAny', BeritaAcara::class);

        $date = Carbon::parse(
            $request->input('incident_date', now())
        );

        $lastDocument = BeritaAcara::query()
            ->where('project_id', $project->id)
            ->orderByDesc('incident_date')
            ->orderByDesc('sequence_number')
            ->first();

        if (! $lastDocument) {
            return response()->json([
                'success' => true,
                'data' => [
                    'last_document_number' => null,
                    'recommended_document_number' => null,
                ],
            ]);
        }

        $recommended = null;

        if ($date->day > 1) {
            $recommended = $this->generateRecommendation(
                $lastDocument->document_number
            );
        }

        return response()->json([
            'success' => true,
            'data' => [
                'last_document_number' =>
                    $lastDocument->document_number,

                'recommended_document_number' =>
                    $recommended,
            ],
        ]);
    }

    protected function generateRecommendation(
        string $documentNumber
    ): ?string {

        if (
            ! preg_match(
                '/^(.*?\/)(\d+)(\/.*)$/',
                $documentNumber,
                $matches
            )
        ) {
            return null;
        }

        $prefix = $matches[1];
        $number = $matches[2];
        $suffix = $matches[3];

        $nextNumber = str_pad(
            (string) ((int) $number + 1),
            strlen($number),
            '0',
            STR_PAD_LEFT
        );

        return $prefix . $nextNumber . $suffix;
    }
}