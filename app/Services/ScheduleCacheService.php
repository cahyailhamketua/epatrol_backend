<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class ScheduleCacheService
{
    public function scheduleSheetVersionKey(int $projectId): string
    {
        return "schedule_sheet:project:{$projectId}:version";
    }

    public function getScheduleSheetCacheVersion(int $projectId): int
    {
        return (int) Cache::rememberForever(
            $this->scheduleSheetVersionKey($projectId),
            fn () => 1
        );
    }

    public function bumpScheduleSheetCacheVersion(int $projectId): void
    {
        $versionKey = $this->scheduleSheetVersionKey($projectId);

        if (! Cache::has($versionKey)) {
            Cache::forever($versionKey, 1);
        }

        Cache::increment($versionKey);
    }

    public function scheduleSheetCacheKey(int $projectId, string $month, ?string $teamId, int $version): string
    {
        $team = $teamId ?: 'all';

        return sprintf(
            'schedule_sheet:project:%d:month:%s:team:%s:v:%d',
            $projectId,
            $month,
            $team,
            $version
        );
    }

    public function usersWithoutTeamCacheKey(int $projectId, bool $activeOnly, int $version): string
    {
        return sprintf(
            'users_without_team:project:%d:active_only:%s:v:%d',
            $projectId,
            $activeOnly ? '1' : '0',
            $version
        );
    }

    public function attendanceReportCacheKey(int $projectId, array $filters, int $version): string
    {
        return sprintf(
            'report:attendance:project:%d:v:%d:%s',
            $projectId,
            $version,
            md5(serialize($filters))
        );
    }
}
