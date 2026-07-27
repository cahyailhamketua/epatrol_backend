<?php

namespace App\Http\Controllers\Api;

use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Project;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class DocumentTypeController extends Controller
{
    public function index(Request $request, Project $project)
    {
        $this->authorize('view', $project);

        // 1. Ambil input filter bulan (format yang diharapkan: YYYY-MM atau MM)
        $monthFilter = $request->query('month'); 

        // 2. Bangun query untuk document types
        $documentTypesQuery = $project->documentTypes()
            ->withCount(['documents' => function ($query) use ($monthFilter) {
                // Jika ada filter bulan, batasi perhitungan documents_count hanya pada bulan tersebut
                if ($monthFilter) {
                    if (strlen($monthFilter) === 7) {
                        // Format YYYY-MM (Contoh: '2026-07')
                        [$year, $month] = explode('-', $monthFilter);
                        $query->whereYear('created_at', $year)
                            ->whereMonth('created_at', $month);
                    } else {
                        // Format MM saja (Contoh: '07')
                        $query->whereMonth('created_at', $monthFilter);
                    }
                }
            }])
            ->orderBy('sort_order')
            ->orderBy('name');

        $documentTypes = $documentTypesQuery->get();

        // 3. Hitung total keseluruhan dokumen (total_documents_count) dari semua tipe yang tampil
        $totalDocumentsCount = $documentTypes->sum('documents_count');

        return response()->json([
            'success' => true,
            'total_document_types' => $documentTypes->count(), // Jumlah tipe dokumen
            'total_documents_all_types' => $totalDocumentsCount, // Total seluruh dokumen dari semua tipe
            'data' => $documentTypes,
        ]);
    }

    public function store(Request $request, Project $project)
    {
        $this->authorize('create', DocumentType::class);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'sort_order' => 'nullable|integer',
        ]);

        $type = DocumentType::create([
            'project_id' => $project->id,
            'name' => $validated['name'],
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Document type created',
            'data' => $type,
        ], 201);
    }

    public function update(Request $request, DocumentType $documentType) 
    {
        $this->authorize('update', $documentType);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'sort_order' => 'nullable|integer',
        ]);

        $documentType->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Document type updated',
            'data' => $documentType->fresh(),
        ]);
    }

    public function destroy(DocumentType $documentType)
    {
        $this->authorize('delete', $documentType);

        $documentType->delete();

        return response()->json([
            'success' => true,
            'message' => 'Document type deleted',
        ]);
    }

}
