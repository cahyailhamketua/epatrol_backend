<?php

namespace App\Services\Progress;

use App\Models\Post;
use App\Repositories\PatrolScanRepository;

class PatrolPointProgressService
{
    public function __construct(private PatrolScanRepository $patrolScanRepository)
    {
    }

    public function buildPointsForPost(Post $post, array $attendanceIds, ?int $userId = null): array
    {
        $scanGroups = $this->patrolScanRepository->getPointScanGroups($attendanceIds, $userId);

        return $post->patrolPoints->map(function ($point) use ($scanGroups) {
            $group = $scanGroups->get($point->id);

            return [
                'id' => $point->id,
                'name' => $point->name,
                'sequence_order' => $point->sequence_order,
                'latitude' => $point->latitude,
                'longitude' => $point->longitude,
                'is_scanned' => $group !== null,
                'scanned_count' => $group?->scan_count ?? 0,
                'last_scan_time' => $group?->last_scan_time ?? null,
                'last_scan_note' => null,
                'last_scan_user' => null,
            ];
        })->values()->all();
    }
}
