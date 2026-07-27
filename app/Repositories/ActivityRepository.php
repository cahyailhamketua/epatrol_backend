<?php

namespace App\Repositories;

use App\Models\Activity;

class ActivityRepository
{
    public function getActivitiesForPost(int $postId, ?int $assignmentId = null)
    {
        return Activity::where('post_id', $postId)
            ->where('active', true)
            ->when($assignmentId, fn($q) => $q->whereHas('assignmentTimes', fn($q2) => $q2->where('assignment_id', $assignmentId)))
            ->with(['assignmentTimes' => function ($q) use ($assignmentId) {
                $q->when($assignmentId, fn($q2) => $q2->where('assignment_id', $assignmentId))->with('assignment');
            }])
            ->get();
    }

    public function getActivitiesForCommander(int $projectId, ?int $assignmentId = null)
    {
        return Activity::where('project_id', $projectId)
            ->whereNull('post_id')
            ->where('active', true)
            ->when($assignmentId, fn($q) => $q->whereHas('assignmentTimes', fn($q2) => $q2->where('assignment_id', $assignmentId)))
            ->with(['assignmentTimes' => function ($q) use ($assignmentId) {
                $q->when($assignmentId, fn($q2) => $q2->where('assignment_id', $assignmentId))->with('assignment');
            }])
            ->get();
    }
}
