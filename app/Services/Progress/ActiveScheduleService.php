<?php

namespace App\Services\Progress;

use App\Repositories\ScheduleRepository;
use Carbon\Carbon;

class ActiveScheduleService
{
    public function __construct(private ScheduleRepository $scheduleRepository)
    {
    }

    public function getActiveScheduleContext(int $projectId, Carbon $now, string $timezone): array
    {
        $today = $now->toDateString();
        $yesterday = $now->copy()->subDay()->toDateString();

        $schedules = $this->scheduleRepository->getByProjectAndDates($projectId, [$today, $yesterday]);

        $activeSchedules = $schedules->filter(function ($schedule) use ($now, $timezone) {
            if (! $schedule->assignment) {
                return false;
            }

            $scheduleDate = $schedule->date instanceof Carbon ? $schedule->date->format('Y-m-d') : Carbon::parse($schedule->date)->format('Y-m-d');
            $start = Carbon::createFromFormat('Y-m-d H:i:s', $scheduleDate.' '.$schedule->assignment->start_time, $timezone);
            $end = Carbon::createFromFormat('Y-m-d H:i:s', $scheduleDate.' '.$schedule->assignment->end_time, $timezone);

            if ($end->lessThanOrEqualTo($start)) {
                $end->addDay();
            }

            return $now->greaterThanOrEqualTo($start) && $now->lessThan($end);
        })->values();

        $activeAssignment = null;
        $activeAssignmentId = null;

        if ($activeSchedules->isNotEmpty()) {
            $regularActive = $activeSchedules->first(fn($schedule) => $schedule->assignment && ! $schedule->assignment->isOffDuty());
            $activeAssignment = $regularActive ? $regularActive->assignment : $activeSchedules->filter(fn($schedule) => $schedule->assignment)->first()?->assignment;
            $activeAssignmentId = $activeAssignment?->id;
        }

        return [
            'schedules' => $schedules,
            'activeSchedules' => $activeSchedules,
            'activeAssignment' => $activeAssignment,
            'activeAssignmentId' => $activeAssignmentId,
        ];
    }
}
