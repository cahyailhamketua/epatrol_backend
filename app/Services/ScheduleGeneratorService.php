<?php

namespace App\Services;

use App\Models\Schedule;
use App\Models\Assignment;
use App\Models\Team;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

class ScheduleGeneratorService
{
    public function generate(int $projectId, string $month, int $teamId, string $pattern)
    {
        $startDate = Carbon::parse($month . '-01')->startOfMonth();
        $endDate   = Carbon::parse($month . '-01')->endOfMonth();

        $patternConfig = config("shift_patterns.$pattern");

        if (!$patternConfig) {
            throw new \Exception("Shift pattern not found");
        }

        $cycle = $patternConfig['cycle'];
        $cycleLength = count($cycle);

        $team = Team::with('users')->findOrFail($teamId);

        $assignments = Assignment::whereIn('code', $cycle)
            ->get()
            ->keyBy('code');

        DB::beginTransaction();

        try {

            foreach ($team->users as $userIndex => $user) {

                $dayIndex = 0;

                foreach (CarbonPeriod::create($startDate, $endDate) as $date) {

                    $assignmentCode = $cycle[$dayIndex % $cycleLength];
                    $assignment = $assignments[$assignmentCode];

                    Schedule::updateOrCreate(
                        [
                            'project_id' => $projectId,
                            'user_id' => $user->id,
                            'date' => $date->format('Y-m-d'),
                        ],
                        [
                            'assignment_id' => $assignment->id,
                            'team_id' => $teamId,
                        ]
                    );

                    $dayIndex++;
                }
            }

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        return true;
    }
}