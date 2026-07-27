<?php

namespace App\Services\Progress;

use App\Models\PatrolPoint;
use App\Models\Project;
use App\Repositories\PatrolScanRepository;

class CommanderProgressService
{
    public function __construct(private PatrolScanRepository $patrolScanRepository)
    {
    }

    public function getProgress(Project $project, array $attendanceIds, ?int $userId = null): array
    {
        $staticPostIds = $project->posts()
            ->where('type', 'static')
            ->pluck('id')
            ->all();

        $totalPoints = PatrolPoint::whereIn('post_id', $staticPostIds)->count();

        if (empty($attendanceIds)) {
            return [
                'total' => $totalPoints,
                'scanned' => 0,
                'remaining' => $totalPoints,
                'percentage' => 0,
                'completed' => false,
            ];
        }

        $scanCount = $this->patrolScanRepository->getDistinctQrCodeCount($attendanceIds, $userId);

        return [
            'total' => $totalPoints,
            'scanned' => $scanCount,
            'remaining' => max(0, $totalPoints - $scanCount),
            'percentage' => $totalPoints > 0 ? round(($scanCount / $totalPoints) * 100, 2) : 0,
            'completed' => $totalPoints > 0 && $scanCount >= $totalPoints,
        ];
    }
}
