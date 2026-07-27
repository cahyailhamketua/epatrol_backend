<?php

namespace App\Observers;

use App\Models\Assignment;
use App\Services\PayrollRefreshService;
use Illuminate\Support\Facades\Cache;

class AssignmentObserver
{
    public function __construct(
        private readonly PayrollRefreshService $payrollRefreshService,
    ) {}
    /**
     * Handle the Assignment "created" event.
     * Reset all progress caches for the project when a new assignment is created.
     */
    public function created(Assignment $assignment): void
    {
        $this->clearProgressCaches($assignment);
        $this->refreshPayroll($assignment);
    }

    /**
     * Handle the Assignment "updated" event.
     * Reset all progress caches for the project when an assignment is updated.
     */
    public function updated(Assignment $assignment): void
    {
        $this->clearProgressCaches($assignment);
        $this->refreshPayroll($assignment);
    }

    /**
     * Handle the Assignment "deleted" event.
     * Reset all progress caches for the project when an assignment is deleted.
     */
    public function deleted(Assignment $assignment): void
    {
        $this->clearProgressCaches($assignment);
        $this->refreshPayroll($assignment);
    }

    private function refreshPayroll(Assignment $assignment): void
    {
        if (! $assignment->project_id) {
            return;
        }

        $this->payrollRefreshService->refreshAllPeriodsForProject((int) $assignment->project_id);
    }

    /**
     * Clear all progress-related caches for the assignment's project
     * 
     * This clears:
     * - progressPostDetail caches for all posts in the project
     * - danruProgress caches for the project
     * - Covers current time window and surrounding windows to handle timezone variations
     */
    private function clearProgressCaches(Assignment $assignment): void
    {
        $projectId = $assignment->project_id;

        // Get all posts in this project to clear their progress caches
        $project = $assignment->project;
        if ($project && $project->posts) {
            foreach ($project->posts as $post) {
                // Clear progress_post_* cache keys for this post
                // We need to cover multiple time windows since cache keys include Y-m-d H:i format
                $this->clearTimeWindowCaches('progress_post_' . $post->id . '_');
            }
        }

        // Clear danru progress caches for this project
        $this->clearTimeWindowCaches('progress_danru_project_' . $projectId . '_');
    }

    /**
     * Clear cache keys for a given prefix across multiple time windows
     * Covers current time +/- 5 minutes to handle the 5-minute TTL
     * 
     * @param string $keyPrefix Cache key prefix (e.g., 'progress_post_123_')
     */
    private function clearTimeWindowCaches(string $keyPrefix): void
    {
        try {
            // Get Redis instance if using Redis cache driver
            if (Cache::getStore() instanceof \Illuminate\Cache\RedisStore) {
                $redis = Cache::getRedis();
                $pattern = $keyPrefix . '*';
                
                // Use Redis KEYS pattern matching to find and delete all matching keys
                $keys = $redis->keys(Cache::getPrefix() . $pattern);
                if (!empty($keys)) {
                    foreach ($keys as $key) {
                        // Remove the prefix that Redis KEYS returns
                        $actualKey = str_replace(Cache::getPrefix(), '', $key);
                        Cache::forget($actualKey);
                    }
                }
                return;
            }
        } catch (\Exception $e) {
            // If Redis pattern matching fails, fall through to manual clearing
        }

        // Fallback: manually clear known time windows (current time +/- 5 minutes)
        // This handles array cache driver and other non-Redis implementations
        for ($offset = -5; $offset <= 5; $offset++) {
            $date = now()->addMinutes($offset);
            $cacheKey = $keyPrefix . $date->format('Y-m-d H:i');
            Cache::forget($cacheKey);
        }
    }
}
