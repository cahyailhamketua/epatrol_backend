<?php

namespace App\Repositories;

use App\Models\Schedule;

class ScheduleRepository
{
    public function getByProjectAndDates(int $projectId, array $dates)
    {
        return Schedule::with(['assignment', 'user'])
            ->where('project_id', $projectId)
            ->whereIn('date', $dates)
            ->get();
    }
}
