<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\Project;
use App\Models\Organization;
use App\Models\PatrolPoint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use App\Services\QrCardImageService;
use App\Services\QrCodeService;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class PostController extends Controller
{   
    private const POST_CACHE_TTL_SECONDS = 300;

    public function __construct(private readonly QrCardImageService $qrCardImageService)
    {
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', Post::class);

        $user = $request->user();
        if ($request->filled('type')) {
            $request->validate([
                'type' => 'in:static,mobile'
            ]);
        }
        $cacheVersion = $this->getPostCacheVersion();
        $cacheKey = sprintf(
            'posts:index:user:%d:role:%s:project:%s:org:%s:type:%s:fmt:no-qr:v:%d',
            $user->id,
            $user->role,
            $user->project_id ?? 'none',
            $user->organization_id ?? 'none',
            $request->query('type', 'all'),
            $cacheVersion
        );

        $posts = Cache::remember(
            $cacheKey,
            now()->addSeconds(self::POST_CACHE_TTL_SECONDS),
            function () use ($request, $user) {
                $query = Post::query()
                    ->select('id', 'project_id', 'name', 'type')
                    ->with([
                        'project' => fn($q) => $q->select('id', 'organization_id', 'name'),
                        'project.organization' => fn($q) => $q->select('id', 'name', 'logo'),
                        'patrolPoints' => fn($q) => $q->select(
                            'id',
                            'post_id',
                            'name',
                            'sequence_order',
                            'latitude',
                            'longitude',
                            'altitude',
                            'radius'
                        ),
                    ])
                    ->orderBy('name');

                if (in_array($user->role, ['anggota', 'komandan_regu', 'admin_project'])) {
                    $query->where('project_id', $user->project_id);
                }

                if ($user->role === 'ho') {
                    $query->whereHas('project', function ($q) use ($user) {
                        $q->where('organization_id', $user->organization_id);
                    });
                }

                if ($request->filled('type')) {
                    $query->where('type', $request->type);
                }

                return $query->orderBy('name')->get();
            }
        );

        return response()->json([
            'data' => $posts,
        ]);
    }

    /**
     * LIST POST PER ORGANIZATION
     * GET /organizations/{organization}/posts
     *
     * Data & otorisasi mengikuti method index()
     */
    public function indexByOrganization(Request $request, Organization $organization)
    {
        $this->authorize('viewAny', Post::class);

        $user = $request->user();
        if ($request->filled('type')) {
            $request->validate([
                'type' => 'in:static,mobile'
            ]);
        }
        $cacheVersion = $this->getPostCacheVersion();
        $cacheKey = sprintf(
            'posts:index-org:org:%d:user:%d:role:%s:project:%s:org-user:%s:type:%s:v:%d',
            $organization->id,
            $user->id,
            $user->role,
            $user->project_id ?? 'none',
            $user->organization_id ?? 'none',
            $request->query('type', 'all'),
            $cacheVersion
        );

        $posts = Cache::remember(
            $cacheKey,
            now()->addSeconds(self::POST_CACHE_TTL_SECONDS),
            function () use ($request, $organization, $user) {
                $query = Post::query()
                    ->select('id', 'project_id', 'name', 'type')
                    ->with([
                        'project' => fn($q) => $q->select('id', 'organization_id', 'name'),
                        'project.organization' => fn($q) => $q->select('id', 'name', 'logo'),
                        'patrolPoints' => fn($q) => $q->select(
                            'id',
                            'post_id',
                            'name',
                            'sequence_order',
                            'latitude',
                            'longitude',
                            'altitude',
                            'radius'
                        ),
                        'patrolPoints.qrCode' => fn($q) => $q->select('id', 'patrol_point_id', 'code', 'active'),
                    ])
                    ->orderBy('name');

                if (in_array($user->role, ['anggota', 'komandan_regu', 'admin_project'])) {
                    $query->where('project_id', $user->project_id);
                }

                if ($user->role === 'ho') {
                    $query->whereHas('project', function ($q) use ($user) {
                        $q->where('organization_id', $user->organization_id);
                    });
                }

                $query->whereHas('project', function ($q) use ($organization) {
                    $q->where('organization_id', $organization->id);
                });

                if ($request->filled('type')) {
                    $query->where('type', $request->type);
                }

                $result = $query->orderBy('name')->get();

                $result->transform(function ($post) {
                    $post->patrolPoints->transform(function ($patrolPoint) use ($post) {
                        if ($patrolPoint->qrCode) {
                            $patrolPoint->setRelation('post', $post);
                            $patrolPoint->qr_code_image = $this->buildQrCardDataUri($patrolPoint);
                            $rel = $this->qrCardImageService->ensurePublicWebpForPatrolPoint($patrolPoint);
                            $patrolPoint->qr_image = $rel ? url('/storage/'.$rel) : null;
                        } else {
                            $patrolPoint->qr_code_image = null;
                            $patrolPoint->qr_image = null;
                        }
                        return $patrolPoint;
                    });
                    return $post;
                });

                return $result;
            }
        );

        return response()->json([
            'data' => $posts,
        ]);
    }

    /**
     * LIST POST PER PROJECT
     * GET /projects/{project}/posts
     */
    public function indexByProject(Project $project)
    {
        $this->authorize('viewAnyByProject', [Post::class, $project]);

        $cacheVersion = $this->getPostCacheVersion();
        $cacheKey = sprintf('posts:index-project:%d:fmt:no-qr:v:%d', $project->id, $cacheVersion);
        $posts = Cache::remember(
            $cacheKey,
            now()->addSeconds(self::POST_CACHE_TTL_SECONDS),
            function () use ($project) {
                return $project->posts()
                    ->select('id', 'project_id', 'name', 'type')
                    ->with([
                        'project' => fn($q) => $q->select('id', 'organization_id', 'name'),
                        'project.organization' => fn($q) => $q->select('id', 'name', 'logo'),
                        'patrolPoints' => fn($q) => $q->select(
                            'id',
                            'post_id',
                            'name',
                            'sequence_order',
                            'latitude',
                            'longitude',
                            'altitude',
                            'radius'
                        ),
                    ])
                    ->orderBy('name')
                    ->get();
            }
        );

        return response()->json([
            'data' => $posts,
        ]);
    }

    /**
     * CREATE POST + PATROL POINT
     * POST /projects/{project}/posts
     */
    public function store(Request $request, Project $project)
    {
        $this->authorize('manage', [Post::class, $project]);

        try {
            $validated = $request->validate([
                'name' => 'required|string|max:100',
                'type' => 'required|in:static,mobile',

                'patrol_points' => 'required|array|min:1',
                'patrol_points.*.name' => 'required|string|max:100',
                'patrol_points.*.sequence_order' => 'required|integer|min:1',
                'patrol_points.*.latitude' => 'required|numeric',
                'patrol_points.*.longitude' => 'required|numeric',
                'patrol_points.*.altitude' => 'nullable|numeric',
                'patrol_points.*.radius' => 'nullable|integer|min:1',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success'     => false,
                'message'     => 'Validation failed',
                'status_code' => 422,
                'errors'      => $e->errors(),
            ], 422);
        }

        $post = DB::transaction(function () use ($validated, $project) {

            $post = $project->posts()->create([
                'name' => $validated['name'],
                'type' => $validated['type'],
            ]);

            foreach ($validated['patrol_points'] as $point) {
                $post->patrolPoints()->create($point);
                // QR Code otomatis dibuat via model event
            }

            return $post;
        });
        $this->bumpPostCacheVersion();

        return response()->json([
            'message' => 'Post created successfully',
            'data' => $post->load([
                'patrolPoints.qrCode'
            ]),
        ], 201);
    }


    /**
     * DETAIL POST
     * GET /posts/{post}
     */
    public function show(Post $post)
    {
        $this->authorize('view', $post);
        $cacheVersion = $this->getPostCacheVersion();
        $cacheKey = sprintf('posts:show:%d:v:%d', $post->id, $cacheVersion);
        $data = Cache::remember(
            $cacheKey,
            now()->addSeconds(self::POST_CACHE_TTL_SECONDS),
            fn() => $post->load('patrolPoints')
        );

        return response()->json([
            'data' => $data,
        ]);
    }

    /**
     * UPDATE POST
     * PUT /posts/{post}
     */
    public function update(Request $request, Post $post)
    {
        $this->authorize('manage', [Post::class, $post->project]);

        try {
            $validated = $request->validate([
                'name' => 'sometimes|string|max:100',
                'type' => 'sometimes|in:static,mobile',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success'     => false,
                'message'     => 'Validation failed',
                'status_code' => 422,
                'errors'      => $e->errors(),
            ], 422);
        }

        $post->update($validated);
        $this->bumpPostCacheVersion();

        return response()->json([
            'message' => 'Post updated successfully',
            'data' => $post,
        ]);
    }

    /**
     * DELETE POST
     * DELETE /posts/{post}
     */
    public function destroy(Post $post)
    {
        $this->authorize('manage', [Post::class, $post->project]);

        $post->delete();
        $this->bumpPostCacheVersion();

        return response()->json([
            'message' => 'Post deleted successfully',
        ]);
    }

    /**
     * GET POST TYPES
     * GET /posts/types
     */
    public function types()
    {
        $this->authorize('viewAny', Post::class);

        $types = DB::table('posts')
            ->select('type')
            ->distinct()
            ->orderBy('type')
            ->pluck('type');

        return response()->json([
            'data' => $types
        ]);
    }

    /**
     * GET POSTS BY TYPE
     * GET /posts/by-type/{type}
     */
    public function byType(Request $request, string $type)
    {
        $this->authorize('viewAny', Post::class);

        $user = $request->user();
        $cacheVersion = $this->getPostCacheVersion();
        $cacheKey = sprintf(
            'posts:by-type:type:%s:user:%d:role:%s:project:%s:org:%s:v:%d',
            $type,
            $user->id,
            $user->role,
            $user->project_id ?? 'none',
            $user->organization_id ?? 'none',
            $cacheVersion
        );

        $posts = Cache::remember(
            $cacheKey,
            now()->addSeconds(self::POST_CACHE_TTL_SECONDS),
            function () use ($user, $type) {
                $query = Post::query()
                    ->select('id', 'project_id', 'name', 'type')
                    ->with([
                        'patrolPoints' => fn($q) => $q->select(
                            'id',
                            'post_id',
                            'name',
                            'sequence_order',
                            'latitude',
                            'longitude',
                            'altitude',
                            'radius'
                        ),
                    ])
                    ->where('type', $type)
                    ->orderBy('name');

                if (in_array($user->role, ['anggota', 'komandan_regu', 'admin_project'])) {
                    $query->where('project_id', $user->project_id);
                }

                if ($user->role === 'ho') {
                    $query->whereHas('project', function ($q) use ($user) {
                        $q->where('organization_id', $user->organization_id);
                    });
                }

                return $query->get();
            }
        );

        return response()->json([
            'data' => $posts,
        ]);
    }

    /**
     * Regenerate qr image (WEBP in public storage) untuk semua patrol points dalam 1 post.
     *
     * Optional:
     * - regenerate_code=true => juga regenerate nilai qr_codes (uuid) (berpengaruh ke proses scan).
     *
     * POST /posts/{post}/patrol-points/regenerate-qr
     */
    public function regenerateQrForPost(Request $request, Post $post)
    {
        $this->authorize('manage', [Post::class, $post->project]);

        try {
            $validated = $request->validate([
                'regenerate_code' => 'sometimes|boolean',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'status_code' => 422,
                'errors' => $e->errors(),
            ], 422);
        }

        $regenerateCode = (bool) ($validated['regenerate_code'] ?? false);

        $points = $post->patrolPoints()
            ->with(['qrCode'])
            ->orderBy('sequence_order')
            ->get();

        $regeneratedImages = 0;
        $regeneratedCodes = 0;
        $errors = [];

        DB::transaction(function () use (
            $points,
            $regenerateCode,
            &$regeneratedImages,
            &$regeneratedCodes,
            &$errors
        ) {
            foreach ($points as $point) {
                if ($regenerateCode) {
                    if (! $point->qrCode) {
                        $errors[] = [
                            'patrol_point_id' => $point->id,
                            'reason' => 'qr_code missing, cannot regenerate_code',
                        ];
                    } else {
                        $point->qrCode->update(['active' => false]);
                        $point->qrCode()->create([
                            'code' => QrCodeService::generate(),
                            'active' => true,
                        ]);
                        $regeneratedCodes++;
                    }
                }

                $rel = $this->qrCardImageService->ensurePublicWebpForPatrolPoint($point);
                if ($rel) {
                    $regeneratedImages++;
                } else {
                    $errors[] = [
                        'patrol_point_id' => $point->id,
                        'reason' => 'cannot generate qr_image',
                    ];
                }
            }
        });
        $this->bumpPostCacheVersion();

        return response()->json([
            'success' => true,
            'data' => [
                'post_id' => $post->id,
                'patrol_points_total' => $points->count(),
                'regenerate_code' => $regenerateCode,
                'regenerated_codes' => $regeneratedCodes,
                'regenerated_images' => $regeneratedImages,
                'errors' => $errors,
            ],
        ]);
    }

    private function postVersionCacheKey(): string
    {
        return 'posts:cache:version';
    }

    private function getPostCacheVersion(): int
    {
        return (int) Cache::rememberForever($this->postVersionCacheKey(), fn() => 1);
    }

    private function bumpPostCacheVersion(): void
    {
        $versionKey = $this->postVersionCacheKey();
        if (! Cache::has($versionKey)) {
            Cache::forever($versionKey, 1);
        }
        Cache::increment($versionKey);
    }

    private function buildQrCardDataUri($patrolPoint): string
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
