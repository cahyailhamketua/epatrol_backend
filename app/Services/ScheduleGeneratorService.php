<?php

namespace App\Services;

use App\Models\Schedule;
use App\Models\Assignment;
use App\Models\Team;
use App\Models\TeamUser;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

class ScheduleGeneratorService
{
    public function generate(int $projectId, string $month, int $teamId, string $pattern)
    {
        $startDate = Carbon::parse($month . '-01')->startOfMonth();
        $endDate   = Carbon::parse($month . '-01')->endOfMonth();
        $monthStart = $startDate->copy();
        $monthEnd = $endDate->copy();

        $patternConfig = config("shift_patterns.$pattern");

        if (!$patternConfig) {
            throw new \Exception("Shift pattern not found");
        }

        $cycle = $patternConfig['cycle'];
        $cycleLength = count($cycle);

        $team = Team::findOrFail($teamId);

        $assignments = Assignment::whereIn('code', $cycle)
            ->get()
            ->keyBy('code');

        DB::beginTransaction();

        try {

            // Membership aktif pada bulan target (pakai pivot start/end)
            $memberships = TeamUser::query()
                ->where('team_id', $team->id)
                ->whereDate('start_date', '<=', $monthEnd)
                ->where(function ($q) use ($monthStart) {
                    $q->whereNull('end_date')
                        ->orWhereDate('end_date', '>=', $monthStart);
                })
                ->with('user')
                ->get()
                ->filter(fn($m) => $m->user && $m->user->active)
                ->values();

            $memberIds = $memberships->pluck('user_id')->unique()->values();

            // Cleanup: soft-delete schedule untuk user yang tidak termasuk member aktif (set team_id = NULL)
            // Ini preserve schedule_id references dalam attendance/overtime/absence untuk data consistency
            Schedule::where('project_id', $projectId)
                ->where('team_id', $teamId)
                ->whereBetween('date', [$startDate, $endDate])
                ->whereNotIn('user_id', $memberIds)
                ->update(['team_id' => null]);

            foreach ($memberships as $membership) {
                $user = $membership->user;

                $dayIndex = 0;

                $memberStart = $membership->start_date ? Carbon::parse($membership->start_date) : $monthStart;
                $memberEnd = $membership->end_date ? Carbon::parse($membership->end_date) : null;

                if ($memberStart->gt($monthStart)) {
                    $membershipStatus = Schedule::STATUS_PRORATE_IN;
                } else {
                    $membershipStatus = Schedule::STATUS_FULL_EXISTING;
                    if ($memberEnd && $memberEnd->lt($monthEnd)) {
                        $membershipStatus = Schedule::STATUS_PRORATE_OUT;
                    }
                }

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
                            'membership_status' => $membershipStatus,
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