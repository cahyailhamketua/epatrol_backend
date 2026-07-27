<?php

namespace App\Http\Controllers\Api;

use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Project;
use App\Support\SignedMediaUrl;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DocumentController extends Controller
{
    public function index(Request $request, Project $project)
    {
        $this->authorize('view', $project);

        $query = Document::query()
            ->with([
                'documentType',
                'uploader:id,full_name'
            ])
            ->where('project_id', $project->id);

        if ($request->filled('month')) {

            $month = $request->month;

            if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid month format. Use YYYY-MM',
                ], 422);
            }

            $query->whereYear(
                'document_date',
                substr($month, 0, 4)
            );

            $query->whereMonth(
                'document_date',
                substr($month, 5, 2)
            );
        }

        if ($request->filled('document_type_id')) {
            $query->where(
                'document_type_id',
                $request->document_type_id
            );
        }

        return response()->json([
            'success' => true,
            'data' => $query
                ->latest('document_date')
                ->paginate(20)
                ->through(function ($document) {
                    return [
                        'id' => $document->id,
                        'project_id' => $document->project_id,
                        'document_type_id' => $document->document_type_id,
                        'uploaded_by' => $document->uploaded_by,
                        'document_date' => $document->document_date,
                        'file_name' => $document->file_name,
                        'file_path' => $document->file_path,

                        'file_url' => SignedMediaUrl::document($document),

                        'document_type' => $document->documentType,

                        'uploader' => [
                            'id' => $document->uploader?->id,
                            'full_name' => $document->uploader?->full_name,
                        ],

                        'created_at' => $document->created_at,
                        'updated_at' => $document->updated_at,
                    ];
                })
        ]);
    }

    public function show(Document $document)
    {
        $this->authorize('view', $document);

        return response()->json([
            'success' => true,
            'data' => $document->load([
                'documentType',
                'uploader:id,full_name'
            ]),
        ]);
    }

    public function store(Request $request, Project $project)
    {
        $this->authorize('create', Document::class);

        $validated = $request->validate([
            'document_type_id' => [
                'nullable',
                'exists:document_types,id'
            ],

            'new_document_type' => [
                'nullable',
                'string',
                'max:255'
            ],

            'document_date' => [
                'required',
                'date'
            ],

            'file' => [
                'required',
                'file',
                'mimes:pdf',
                'max:10240'
            ]
        ]);

        if (
            empty($validated['document_type_id']) &&
            empty($validated['new_document_type'])
        ) {
            return response()->json([
                'success' => false,
                'message' => 'document_type_id atau new_document_type wajib diisi'
            ], 422);
        }

        $documentTypeId = $validated['document_type_id'] ?? null;

        if (
            !$documentTypeId &&
            !empty($validated['new_document_type'])
        ) {
            $documentType = DocumentType::firstOrCreate(
                [
                    'project_id' => $project->id,
                    'name' => $validated['new_document_type']
                ],
                [
                    'sort_order' => 0
                ]
            );

            $documentTypeId = $documentType->id;
        }

        $file = $request->file('file');

        $path = $file->store(
            "documents/project-{$project->id}",
            'public'
        );

        $document = Document::create([
            'project_id' => $project->id,
            'document_type_id' => $documentTypeId,
            'uploaded_by' => auth()->id(),
            'document_date' => $validated['document_date'],
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $path,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Document uploaded',
            'data' => $document->load([
                'documentType',
                'uploader'
            ]),
        ], 201);
    }

    public function update(Request $request, Document $document) 
    {
        $this->authorize('update', $document);

        $validated = $request->validate([
            'document_type_id' => 'required|exists:document_types,id',
            'document_date' => 'required|date',
            'file' => 'nullable|file|mimes:pdf|max:10240',
        ]);

        if ($request->hasFile('file')) {

            Storage::disk('public')
                ->delete($document->file_path);

            $file = $request->file('file');

            $document->file_path = $file->store(
                "documents/project-{$document->project_id}",
                'public'
            );

            $document->file_name =
                $file->getClientOriginalName();
        }

        $document->document_type_id =
            $validated['document_type_id'];

        $document->document_date =
            $validated['document_date'];

        $document->save();

        return response()->json([
            'success' => true,
            'message' => 'Document updated',
            'data' => $document->fresh(),
        ]);
    }

    public function destroy(Document $document)
    {
        $this->authorize('delete', $document);

        Storage::disk('public')
            ->delete($document->file_path);

        $document->delete();

        return response()->json([
            'success' => true,
            'message' => 'Document deleted',
        ]);
    }

    public function download(Document $document)
    {
        $this->authorize('download', $document);

        // Cek keberadaan file di disk public
        abort_if(
            ! $document->file_path || ! Storage::disk('public')->exists($document->file_path),
            404,
            'File dokumen tidak ditemukan'
        );

        return Storage::disk('public')->download(
            $document->file_path, 
            $document->file_name
        );
    }
}
