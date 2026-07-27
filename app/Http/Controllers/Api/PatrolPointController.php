<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PatrolPoint;
use App\Models\PatrolScan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Services\QrCardImageService;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use App\Models\QrCode as QrCodeModel;

class PatrolPointController extends Controller
{
    private const PATROL_POINT_CACHE_TTL_SECONDS = 300;

    public function __construct(private readonly QrCardImageService $qrCardImageService)
    {
    }   

    /**
     * CREATE PATROL POINT
     * POST /posts/{post}/patrol-points
     * 
     * Logic:
     * - Static post: hanya 1 patrol point (untuk komandan regu)
     * - Mobile post: multiple patrol points dengan sequence order
     * - Sequence order harus unique per post
     * - Altitude untuk validasi ketinggian saat scanning
     * - Radius untuk validasi distance
     */
    public function store(Request $request, Post $post)
    {
        $this->authorize('manage', [PatrolPoint::class, $post->project]);

        try {
            $validated = $request->validate([
                'name' => 'required|string|max:100',
                'sequence_order' => 'required|integer|min:1',
                'latitude' => 'required|numeric|between:-90,90',
                'longitude' => 'required|numeric|between:-180,180',
                'altitude' => 'nullable|numeric',
                'radius' => 'nullable|integer|min:1',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success'     => false,
                'message'     => 'Validation failed',
                'status_code' => 422,
                'errors'      => $e->errors(),
            ], 422);
        }

        // ===== VALIDATION LOGIC =====
        
        // 1. Jika post type 'static': hanya boleh 1 patrol point
        if ($post->type === 'static') {
            $existingCount = $post->patrolPoints()->count();
            if ($existingCount > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Static post hanya boleh punya 1 patrol point (untuk komandan regu)',
                    'post_type' => 'static',
                    'current_count' => $existingCount,
                ], 422);
            }
            
            // Static post sequence harus 1
            if ($validated['sequence_order'] !== 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'Static post patrol point harus memiliki sequence_order = 1',
                    'provided_sequence' => $validated['sequence_order'],
                ], 422);
            }
        }
        
        // 2. Validasi unique [post_id, sequence_order]
        $sequenceExists = $post->patrolPoints()
            ->where('sequence_order', $validated['sequence_order'])
            ->exists();
            
        if ($sequenceExists) {
            return response()->json([
                'success' => false,
                'message' => 'Sequence order sudah ada untuk post ini',
                'post_id' => $post->id,
                'post_type' => $post->type,
                'sequence_order' => $validated['sequence_order'],
                'suggestion' => 'Gunakan sequence order yang berbeda atau update patrol point yang ada',
            ], 422);
        }
        
        // 3. Set default radius jika tidak diisi
        if (empty($validated['radius'])) {
            $validated['radius'] = 5; // Default 5 km
        }

        $point = null;
        DB::transaction(function () use ($post, $validated, &$point) {
            $point = $post->patrolPoints()->create($validated);

            // Auto-generate QR code
            $point->qrCode()->create([
                'code' => strtoupper(Str::uuid()),
                'active' => true,
            ]);
        });
        $this->bumpPatrolPointCacheVersion();

        return response()->json([
            'success' => true,
            'message' => 'Patrol point created dengan QR code',
            'data' => [
                'id' => $point->id,
                'post' => [
                    'id' => $post->id,
                    'name' => $post->name,
                    'type' => $post->type,
                ],
                'patrol_point' => $point->load('qrCode')->toArray(),
                'info' => [
                    'type' => $post->type === 'static' 
                        ? 'Static Point (Komandan Regu only)' 
                        : "Mobile Point (Sequence {$point->sequence_order})",
                    'total_points_in_post' => $post->patrolPoints()->count(),
                ],
                'qr_code' => [
                    'id' => $point->qrCode->id,
                    'code' => $point->qrCode->code,
                    'active' => $point->qrCode->active,
                    'image_url' => url('/api/qr-codes/' . $point->qrCode->id . '/image'),
                ],
            ],
        ], 201);
    }

    /**
     * LIST PATROL POINT BY POST
     * GET /posts/{post}/patrol-points
     * Bentuk data mengikuti transformasi di PostController@index
     */
    public function indexByPost(Request $request, Post $post)
    {
        $this->authorize('view', $post);
        $post->loadMissing('project.organization');
        $cacheVersion = $this->getPatrolPointCacheVersion();
        $cacheKey = sprintf('patrol-points:post:%d:v:%d', $post->id, $cacheVersion);

        $points = Cache::remember(
            $cacheKey,
            now()->addSeconds(self::PATROL_POINT_CACHE_TTL_SECONDS),
            function () use ($post) {
                $result = $post->patrolPoints()
                    ->select(
                        'id',
                        'post_id',
                        'name',
                        'sequence_order',
                        'latitude',
                        'longitude',
                        'altitude',
                        'radius'
                    )
                    ->with(['qrCode' => fn($q) => $q->select('id', 'patrol_point_id', 'code', 'active')])
                    ->orderBy('sequence_order')
                    ->get();

                $result->transform(function ($patrolPoint) use ($post) {
                    if ($patrolPoint->qrCode) {
                        $patrolPoint->setRelation('post', $post);
                        $patrolPoint->qr_code_image = $this->buildQrCardDataUri($patrolPoint);
                    } else {
                        $patrolPoint->qr_code_image = null;
                    }

                    return $patrolPoint;
                });

                return $result;
            }
        );

        return response()->json([
            'data' => $points,
        ]);
    }

    /**
     * SHOW PATROL POINT
     * GET /patrol-points/{patrolPoint}
     * 
     * Show detail patrol point dengan context:
     * - Post type (static/mobile)
     * - Sequence order dalam workflow
     * - Altitude untuk distance validation
     * - Current QR code status
     */
    public function show(PatrolPoint $patrolPoint)
    {
        $this->authorize('view', $patrolPoint);
        $cacheVersion = $this->getPatrolPointCacheVersion();
        $cacheKey = sprintf('patrol-points:show:%d:v:%d', $patrolPoint->id, $cacheVersion);
        $payload = Cache::remember(
            $cacheKey,
            now()->addSeconds(self::PATROL_POINT_CACHE_TTL_SECONDS),
            function () use ($patrolPoint) {
                $patrolPoint->load(['activeQrCode', 'post.project.organization']);
                $patrolPoint->setRelation('qrCode', $patrolPoint->activeQrCode);
                unset($patrolPoint->activeQrCode);
                $post = $patrolPoint->post;
                $pointData = $patrolPoint->toArray();
                $pointData['qr_code_image'] = $patrolPoint->qrCode
                    ? $this->buildQrCardDataUri($patrolPoint)
                    : null;
                $rel = $patrolPoint->qrCode ? $this->qrCardImageService->ensurePublicWebpForPatrolPoint($patrolPoint) : null;
                $pointData['qr_image'] = $rel ? url('/storage/'.$rel) : null;

                return [
                    'patrol_point' => $pointData,
                    'post_context' => [
                        'id' => $post->id,
                        'name' => $post->name,
                        'type' => $post->type,
                        'type_description' => $post->type === 'static'
                            ? 'Static Point - Untuk Komandan Regu (max 1 point per post)'
                            : 'Mobile Point - Untuk Anggota (multiple points dengan sequence)',
                    ],
                    'scanning_info' => [
                        'sequence_order' => $patrolPoint->sequence_order,
                        'total_points_in_post' => $post->patrolPoints()->count(),
                        'coordinates' => [
                            'latitude' => $patrolPoint->latitude,
                            'longitude' => $patrolPoint->longitude,
                            'altitude' => $patrolPoint->altitude,
                        ],
                        'validation_distance_radius' => $patrolPoint->radius . ' km',
                        'altitude_tolerance' => '±50 meters (from patrol point altitude)',
                    ],
                    'qr_code' => [
                        'id' => $patrolPoint->qrCode->id,
                        'code' => $patrolPoint->qrCode->code,
                        'active' => $patrolPoint->qrCode->active,
                        'scannable' => $patrolPoint->qrCode->active,
                        'image_url' => url('/api/qr-codes/' . $patrolPoint->qrCode->id . '/image'),
                    ],
                ];
            }
        );
        
        return response()->json([
            'success' => true,
            'data' => $payload,
        ]);
    }

    /**
     * UPDATE PATROL POINT
     * PATCH /patrol-points/{patrolPoint}
     * 
     * Logic:
     * - Tidak boleh mengubah sequence_order yang sudah ada
     * - Update altitude hanya jika perlu untuk recalibration
     * - Bisa update radius untuk adjustment validated distance
     */
public function update(Request $request, PatrolPoint $patrolPoint)
{
    $this->authorize(
        'manage',
        [PatrolPoint::class, $patrolPoint->post->project]
    );

    try {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'latitude' => 'sometimes|numeric|between:-90,90',
            'longitude' => 'sometimes|numeric|between:-180,180',
            'altitude' => 'sometimes|nullable|numeric',
            'radius' => 'sometimes|integer|min:1',
        ]);
    } catch (ValidationException $e) {
        return response()->json([
            'success'     => false,
            'message'     => 'Validation failed',
            'status_code' => 422,
            'errors'      => $e->errors(),
        ], 422);
    }

    // Tidak boleh update sequence_order
    if ($request->has('sequence_order')) {
        return response()->json([
            'success' => false,
            'message' => 'Tidak boleh mengubah sequence_order. Hapus dan buat yang baru jika perlu',
            'current_sequence' => $patrolPoint->sequence_order,
        ], 422);
    }

    DB::transaction(function () use ($patrolPoint, $validated) {

        // Update patrol point
        $patrolPoint->update([
            'name'      => $validated['name'] ?? $patrolPoint->name,
            'latitude'  => $validated['latitude'] ?? $patrolPoint->latitude,
            'longitude' => $validated['longitude'] ?? $patrolPoint->longitude,
            'altitude'  => $validated['altitude'] ?? $patrolPoint->altitude,
            'radius'    => $validated['radius'] ?? $patrolPoint->radius,
        ]);

        // Ambil QR aktif
        $activeQr = $patrolPoint->activeQrCode;

        // Pastikan hanya 1 QR aktif
        if ($activeQr) {
                QrCodeModel::where('patrol_point_id', $patrolPoint->id)
                ->where('id', '!=', $activeQr->id)
                ->update([
                    'active' => false
                ]);
        }
    });

    $this->bumpPatrolPointCacheVersion();

    return response()->json([
        'success' => true,
        'message' => 'Patrol point updated successfully',
        'data' => [
            'patrol_point' => $patrolPoint->fresh()->load('activeQrCode')->toArray(),
            'post_info' => [
                'id' => $patrolPoint->post->id,
                'name' => $patrolPoint->post->name,
                'type' => $patrolPoint->post->type,
            ],
        ],
    ]);
}


    /**
     * DELETE PATROL POINT
     * DELETE /patrol-points/{patrolPoint}
     * 
     * Validasi:
     * - Cek apakah ada patrol scans yang sudah menggunakan patrol point ini
     * - Jika ada scans: warning atau prevent deletion (depending on business rule)
     * - Soft delete QR code dengan cascade ke patrol scans
     */
    public function destroy(PatrolPoint $patrolPoint)
    {
        $this->authorize(
            'manage',
            [PatrolPoint::class, $patrolPoint->post->project]
        );

        // Cek apakah ada patrol scans yang menggunakan QR code dari patrol point ini
        $scansCount = PatrolScan::where('qr_code_id', $patrolPoint->qrCode->id)->count();
        
        if ($scansCount > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak bisa menghapus patrol point, sudah ada ' . $scansCount . ' scan yang terhubung',
                'error_code' => 'PATROL_POINT_IN_USE',
                'linked_scans_count' => $scansCount,
                'recommendation' => 'Deactivate patrol point atau update scans terlebih dahulu',
            ], 422);
        }

        DB::transaction(function () use ($patrolPoint) {
            $patrolPoint->qrCode()?->delete();
            $patrolPoint->delete();
        });
        $this->bumpPatrolPointCacheVersion();

        return response()->json([
            'success' => true,
            'message' => 'Patrol point deleted successfully',
            'data' => [
                'deleted_point' => [
                    'id' => $patrolPoint->id,
                    'name' => $patrolPoint->name,
                    'post_id' => $patrolPoint->post_id,
                ],
            ],
        ]);
    }

    private function patrolPointVersionCacheKey(): string
    {
        return 'patrol-points:cache:version';
    }

    private function getPatrolPointCacheVersion(): int
    {
        return (int) Cache::rememberForever($this->patrolPointVersionCacheKey(), fn() => 1);
    }

    private function bumpPatrolPointCacheVersion(): void
    {
        $versionKey = $this->patrolPointVersionCacheKey();
        if (! Cache::has($versionKey)) {
            Cache::forever($versionKey, 1);
        }
        Cache::increment($versionKey);
    }

    private function buildQrCardDataUri(PatrolPoint $patrolPoint): string
    {
        $post = $patrolPoint->post;
        $project = $post?->project;
        $organization = $project?->organization;

        $qrSvg = QrCode::format('svg')->size(280)->margin(1)->generate($patrolPoint->qrCode->code);
        $qrDataUri = 'data:image/svg+xml;base64,'.base64_encode($qrSvg);
        $logoDataUri = $this->organizationLogoDataUri($organization?->logo);

        $orgName = strtoupper((string) ($organization?->name ?? 'ORGANIZATION'));
        $postName = strtoupper((string) ($post?->name ?? '-'));
        $projectName = strtoupper((string) ($project?->name ?? '-'));
        $pointName = strtoupper((string) ($patrolPoint->name ?? '-'));

        $logoSvg = $logoDataUri
            ? '<image href="'.$logoDataUri.'" x="205" y="30" width="110" height="110" preserveAspectRatio="xMidYMid meet" />'
            : '<rect x="205" y="30" width="110" height="110" fill="#f1f5f9" stroke="#cbd5e1" />';

        $cardSvg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="520" height="860" viewBox="0 0 520 860">
  <rect x="0" y="0" width="520" height="860" fill="#ffffff"/>
  {$logoSvg}
  {$this->buildMultilineTextSvg($orgName, 185, 42, 700, 22, 50, 2)}

  <rect x="90" y="270" width="340" height="340" fill="#ffffff" stroke="#111111" stroke-width="4"/>
  <image href="{$qrDataUri}" x="110" y="290" width="300" height="300" preserveAspectRatio="xMidYMid meet"/>

  {$this->buildMultilineTextSvg($postName, 665, 34, 700, 20, 46, 2)}
  {$this->buildMultilineTextSvg($projectName, 760, 38, 700, 22, 42, 2)}
  {$this->buildMultilineTextSvg($pointName, 800, 34, 500, 24, 38, 2)}
</svg>
SVG;

        return 'data:image/svg+xml;base64,'.base64_encode($cardSvg);
    }

    private function organizationLogoDataUri(?string $logoPath): ?string
    {
        if (! $logoPath) {
            return null;
        }

        if (str_starts_with($logoPath, 'data:image/')) {
            return $logoPath;
        }

        if (str_starts_with($logoPath, 'http://') || str_starts_with($logoPath, 'https://')) {
            return $this->xmlEscape($logoPath);
        }

        if (! Storage::disk('public')->exists($logoPath)) {
            return null;
        }

        $raw = Storage::disk('public')->get($logoPath);
        $mime = Storage::disk('public')->mimeType($logoPath) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode($raw);
    }

    private function xmlEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function buildMultilineTextSvg(
        string $text,
        int $startY,
        int $fontSize,
        int $fontWeight,
        int $maxCharsPerLine,
        int $lineHeight,
        int $maxLines
    ): string {
        $lines = $this->splitTextToLines($text, $maxCharsPerLine, $maxLines);
        $svg = '';

        foreach ($lines as $index => $line) {
            $y = $startY + ($index * $lineHeight);
            $svg .= '<text x="260" y="'.$y.'" text-anchor="middle" font-family="Arial, Helvetica, sans-serif" font-size="'.$fontSize.'" font-weight="'.$fontWeight.'" fill="#111111">'.$this->xmlEscape($line).'</text>';
        }

        return $svg;
    }

    private function splitTextToLines(string $text, int $maxCharsPerLine, int $maxLines): array
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        if ($normalized === '') {
            return ['-'];
        }

        $words = explode(' ', $normalized);
        $lines = [];
        $current = '';

        $totalWords = count($words);
        for ($i = 0; $i < $totalWords; $i++) {
            $word = $words[$i];
            $candidate = $current === '' ? $word : $current.' '.$word;
            if (mb_strlen($candidate) <= $maxCharsPerLine) {
                $current = $candidate;
                continue;
            }

            if ($current !== '') {
                $lines[] = $current;
            } else {
                $lines[] = mb_substr($word, 0, $maxCharsPerLine);
                $word = mb_substr($word, $maxCharsPerLine);
            }

            if (count($lines) >= $maxLines - 1) {
                $remainingParts = [];
                if ($word !== '') {
                    $remainingParts[] = $word;
                }
                if ($i + 1 < $totalWords) {
                    $remainingParts[] = implode(' ', array_slice($words, $i + 1));
                }
                $lines[] = $this->truncateWithEllipsis(trim(implode(' ', $remainingParts)), $maxCharsPerLine);
                return $lines;
            }

            $current = $word;
        }

        if ($current !== '' && count($lines) < $maxLines) {
            $lines[] = $current;
        }

        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, 0, $maxLines);
        }

        return $lines;
    }

    private function truncateWithEllipsis(string $text, int $maxChars): string
    {
        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, max(1, $maxChars - 1))).'…';
    }
}
