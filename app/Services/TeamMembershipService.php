<?php

namespace App\Services;

use App\Models\Schedule;
use App\Models\Team;
use App\Models\TeamUser;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class TeamMembershipService
{
    public function monthStart(string $month): Carbon
    {
        return Carbon::parse($month . '-01')->startOfMonth();
    }

    /**
     * Soft-delete jadwal (set team_id = NULL) untuk preserve schedule_id references dalam attendance/overtime/absence.
     * Ini memastikan data consistency ketika user di-remove dari team.
     * Jadwal mulai awal bulan $monthStart ke depan.
     */
    public function deleteSchedulesFromMonth(
        Team $team,
        Carbon $monthStart,
        ?int $userId = null
    ): int {
        $fromDate = $monthStart->copy()->startOfMonth()->toDateString();

        $query = Schedule::query()
            ->where('project_id', $team->project_id)
            ->where('team_id', $team->id)
            ->where('date', '>=', $fromDate);

        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        return $query->update(['team_id' => null]);
    }

    /**
     * Hapus tim jika tidak ada anggota (team_users). Jadwal tim dihapus dari bulan cutoff ke depan saja.
     *
     * @return array{deleted: bool, deleted_schedule_count: int}
     */
    public function deleteTeamIfNoMembersRemain(Team $team, Carbon $monthStart): array
    {
        if (TeamUser::query()->where('team_id', $team->id)->exists()) {
            return ['deleted' => false, 'deleted_schedule_count' => 0];
        }

        $deletedSchedules = $this->deleteSchedulesFromMonth($team, $monthStart);

        $team->delete();

        return ['deleted' => true, 'deleted_schedule_count' => $deletedSchedules];
    }

    /**
     * Hapus keanggotaan user di tim lain (project sama) dan hapus jadwalnya
     * di tim tersebut mulai tanggal cutoff, agar user hanya terdaftar di $targetTeam.
     * Jika user adalah ketua tim yang ditinggalkan, ketua diganti anggota aktif pertama (start_date terawal).
     */
    public function releaseUserFromOtherTeamsInProject(
        User $user,
        Team $targetTeam,
        Carbon $memberStartDate,
        Carbon $scheduleDeleteFromDateStart
    ): void {
        $projectId = (int) $targetTeam->project_id;

        $otherTeamIds = Team::query()
            ->where('project_id', $projectId)
            ->where('id', '!=', $targetTeam->id)
            ->pluck('id');

        if ($otherTeamIds->isEmpty()) {
            return;
        }

        $teamsWhereUserWasLeader = Team::query()
            ->whereIn('id', $otherTeamIds)
            ->where('leader_id', $user->id)
            ->get();

        foreach ($teamsWhereUserWasLeader as $team) {
            DB::table('teams')
                ->where('id', $team->id)
                ->update([
                    'leader_id' => $this->firstActiveMemberUserId($team, $user->id),
                    'updated_at' => now(),
                ]);
        }

        DB::table('team_users')
            ->where('user_id', $user->id)
            ->whereIn('team_id', $otherTeamIds)
            ->delete();

        $fromDate = $scheduleDeleteFromDateStart->copy()->startOfMonth()->toDateString();

        // Soft-delete: set team_id = NULL untuk preserve schedule_id references dalam attendance/overtime/absence
        Schedule::query()
            ->where('project_id', $projectId)
            ->where('user_id', $user->id)
            ->whereIn('team_id', $otherTeamIds)
            ->where('date', '>=', $fromDate)
            ->update(['team_id' => null]);
    }

    /**
     * Pindahkan user ke $targetTeam: keluar dari tim lain di project yang sama + daftar di tim tujuan.
     * Tim asal yang tidak punya anggota lagi otomatis dihapus (jadwal dari bulan cutoff ke depan).
     *
     * @return list<array{team_id: int, deleted_schedule_count: int}>
     */
    public function moveUserToTeam(
        User $user,
        Team $targetTeam,
        Carbon $memberStartDate,
        Carbon $scheduleDeleteFromDateStart
    ): array {
        $formerTeamIds = $this->formerTeamIdsForUserInProject($user, $targetTeam);

        $this->releaseUserFromOtherTeamsInProject(
            $user,
            $targetTeam,
            $memberStartDate,
            $scheduleDeleteFromDateStart
        );

        $this->ensureActiveMembershipOnTeam($user, $targetTeam, $memberStartDate);

        return $this->deleteTeamsIfNoMembersRemain(
            $formerTeamIds,
            $scheduleDeleteFromDateStart->copy()->startOfMonth()
        );
    }

    /**
     * @return list<int>
     */
    public function formerTeamIdsForUserInProject(User $user, Team $targetTeam): array
    {
        return TeamUser::query()
            ->where('user_id', $user->id)
            ->where('team_id', '!=', $targetTeam->id)
            ->whereHas('team', fn ($q) => $q->where('project_id', $targetTeam->project_id))
            ->pluck('team_id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $teamIds
     * @return list<array{team_id: int, deleted_schedule_count: int}>
     */
    public function deleteTeamsIfNoMembersRemain(array $teamIds, Carbon $monthStart): array
    {
        $deleted = [];

        foreach ($teamIds as $teamId) {
            $team = Team::find($teamId);
            if ($team === null) {
                continue;
            }

            $result = $this->deleteTeamIfNoMembersRemain($team, $monthStart);
            if ($result['deleted']) {
                $deleted[] = [
                    'team_id' => (int) $teamId,
                    'deleted_schedule_count' => $result['deleted_schedule_count'],
                ];
            }
        }

        return $deleted;
    }

    /**
     * Pastikan tepat satu baris keanggotaan aktif user di tim tujuan.
     */
    public function ensureActiveMembershipOnTeam(
        User $user,
        Team $targetTeam,
        Carbon $memberStartDate
    ): void {
        $startDate = $memberStartDate->toDateString();

        DB::table('team_users')
            ->where('team_id', $targetTeam->id)
            ->where('user_id', $user->id)
            ->delete();

        TeamUser::create([
            'team_id' => $targetTeam->id,
            'user_id' => $user->id,
            'start_date' => $startDate,
            'end_date' => null,
        ]);
    }

    /**
     * Jika user yang dihapus dari tim adalah ketua, leader_id diganti anggota aktif terlama.
     */
    public function reassignLeaderIfRemovedMemberWasLeader(Team $team, User $removedUser): bool
    {
        if ((int) $team->leader_id !== (int) $removedUser->id) {
            return false;
        }

        DB::table('teams')
            ->where('id', $team->id)
            ->update([
                'leader_id' => $this->firstActiveMemberUserId($team, $removedUser->id),
                'updated_at' => now(),
            ]);

        return true;
    }

    /**
     * Anggota aktif pertama di tim (urut start_date, lalu id), opsional kecuali satu user.
     */
    public function firstActiveMemberUserId(Team $team, ?int $excludeUserId = null): ?int
    {
        $query = TeamUser::query()
            ->where('team_id', $team->id)
            ->whereNull('end_date')
            ->orderBy('start_date')
            ->orderBy('id');

        if ($excludeUserId !== null) {
            $query->where('user_id', '!=', $excludeUserId);
        }

        $userId = $query->value('user_id');

        return $userId !== null ? (int) $userId : null;
    }
}
