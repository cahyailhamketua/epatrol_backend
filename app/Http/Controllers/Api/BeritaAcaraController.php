<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BeritaAcaraStoreRequest;
use App\Models\BeritaAcara;
use App\Models\Project;
use App\Services\BeritaAcaraPdfService;
use App\Support\SignedMediaUrl;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;
use Illuminate\Support\Str;

class BeritaAcaraController extends Controller
{
    public function __construct(
        protected BeritaAcaraPdfService $pdfService,
    ) {}

    /**
     * POST
     * /projects/{project}/berita-acaras
     */
    public function store(
        BeritaAcaraStoreRequest $request,
        Project $project
    ) {
        $this->authorize('create', BeritaAcara::class);

        $user = $request->user();

        try {

            $beritaAcara = DB::transaction(function () use (
                $request,
                $project,
                $user
            ) {

                $validated = $request->validated();

                $incidentDate =
                    Carbon::parse(
                        $validated['incident_date']
                    )->toDateString();

                /**
                 * sequence number otomatis
                 */
                $lastRecord =
                    BeritaAcara::query()
                        ->where('project_id', $project->id)
                        ->whereYear(
                            'incident_date',
                            Carbon::parse($incidentDate)->year
                        )
                        ->whereMonth(
                            'incident_date',
                            Carbon::parse($incidentDate)->month
                        )
                        ->lockForUpdate()
                        ->orderByDesc('sequence_number')
                        ->first();

                $sequenceNumber =
                    ($lastRecord?->sequence_number ?? 0) + 1;


                $created = BeritaAcara::create([
                    'project_id' => $project->id,
                    'created_by' => $user->id,

                    'document_number' =>
                        $validated['document_number'],

                    'sequence_number' =>
                        $sequenceNumber,

                    'incident_date' =>
                        $incidentDate,

                    'incident_time' =>
                        $validated['incident_time'],

                    'subject' =>
                        $validated['subject'] ?? null,

                    'location' =>
                        $validated['location'] ?? null,

                    'description' =>
                        $validated['description'] ?? null,

                    'chronologies' =>
                        $validated['chronologies'] ?? [],

                    'actions_taken' =>
                        $validated['actions_taken'] ?? [],

                    'inspector_name' =>
                        $validated['inspector_name'] ?? null,

                    'inspector_position' =>
                        $validated['inspector_position'] ?? null,

                    'acknowledged_by' =>
                        $validated['acknowledged_by'] ?? null,

                    'acknowledged_position' =>
                        $validated['acknowledged_position'] ?? null,
                ]);

                $pdfPath =
                    $this->pdfService
                        ->generateAndSave(
                            $created,
                            $project
                        );

                $created->update([
                    'pdf_path' => $pdfPath,
                ]);

                return $created->fresh([
                    'project.organization',
                    'creator',
                ]);
            });

            return response()->json([
                'success' => true,
                'message' => 'Berita acara created.',
                'data' => [
                    'id' => $beritaAcara->id,
                    'pdf_path' => $beritaAcara->pdf_path,
                ],
            ], 201);

        } catch (Throwable $e) {

            Log::error(
                'Failed create berita acara',
                [
                    'project_id' => $project->id,
                    'message' => $e->getMessage(),
                ]
            );

            return response()->json([
                'success' => false,
                'message' => 'Failed create berita acara.',
            ], 500);
        }
    }

    /**
     * GET
     * /projects/{project}/berita-acaras
     */
    public function index(
        Request $request,
        Project $project
    ) {
        $this->authorize(
            'viewAny',
            BeritaAcara::class
        );

        $month =
            $request->input(
                'month',
                now()->format('Y-m')
            );

        $reports =
            BeritaAcara::query()
                ->with('creator:id,full_name,role')
                ->where(
                    'project_id',
                    $project->id
                )
                ->byMonth($month)
                ->orderByDesc('incident_date')
                ->orderByDesc('sequence_number')
                ->get()
                ->map(function ($item) {

                    return [
                        'id' => $item->id,

                        'document_number' =>
                            $item->document_number,

                        'sequence_number' =>
                            $item->sequence_number,

                        'incident_date' =>
                            $item->incident_date
                                ?->format('Y-m-d'),

                        'incident_time' =>
                            $item->incident_time,

                        'subject' =>
                            $item->subject,

                        'created_by' => [
                            'id' =>
                                $item->creator?->id,

                            'full_name' =>
                                $item->creator?->full_name,

                            'role' =>
                                $item->creator?->role,
                        ],

                        'pdf_url' =>
                            $item->pdf_path
                                ? SignedMediaUrl::beritaAcara($item)
                                : null,

                        'created_at' =>
                            $item->created_at,
                    ];
                });

        return response()->json([
            'success' => true,
            'data' => $reports,
        ]);
    }

    /**
     * GET
     * /projects/{project}/berita-acaras/{beritaAcara}
     */
    public function show(
        Project $project,
        BeritaAcara $beritaAcara
    ) {
        $this->authorize(
            'view',
            $beritaAcara
        );

        abort_if(
            $beritaAcara->project_id !== $project->id,
            404
        );

        $beritaAcara->load([
            'project.organization',
            'creator',
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                ...$beritaAcara->toArray(),

                'pdf_url' =>
                    $beritaAcara->pdf_path
                        ? SignedMediaUrl::beritaAcara(
                            $beritaAcara
                        )
                        : null,
            ],
        ]);
    }

    /**
     * GET
     * /download
     */
    public function download(
        Project $project,
        BeritaAcara $beritaAcara
    ) {
        $this->authorize(
            'download',
            $beritaAcara
        );

        abort_if(
            $beritaAcara->project_id !== $project->id,
            404
        );

        // 1. Cek keberadaan file di storage public
        abort_if(
            ! $beritaAcara->pdf_path || ! Storage::disk('public')->exists($beritaAcara->pdf_path),
            404,
            'File PDF Berita Acara tidak ditemukan'
        );

        // 2. Ganti karakter '/' dengan '-' agar aman untuk sistem file
        $formattedDocNumber = str_replace('/', '_', $beritaAcara->document_number);

        // 3. Susun Nama File
        $fileName = "{$formattedDocNumber}.pdf";

        // 4. Direct Stream Download
        return Storage::disk('public')->download(
            $beritaAcara->pdf_path,
            $fileName
        );
    }

    /**
     * DELETE
     */
    public function destroy(
        Project $project,
        BeritaAcara $beritaAcara
    ) {
        $this->authorize(
            'delete',
            $beritaAcara
        );

        abort_if(
            $beritaAcara->project_id !== $project->id,
            404
        );

        if (
            $beritaAcara->pdf_path &&
            Storage::disk('public')->exists(
                $beritaAcara->pdf_path
            )
        ) {
            Storage::disk('public')->delete(
                $beritaAcara->pdf_path
            );
        }

        $beritaAcara->delete();

        return response()->json([
            'success' => true,
            'message' => 'Berita acara deleted.',
        ]);
    }
}