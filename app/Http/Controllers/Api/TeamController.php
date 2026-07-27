<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Schedule;
use App\Models\Team;
use App\Models\User;
use App\Services\ScheduleCacheService;
use App\Services\TeamMembershipService;
use App\Support\SignedMediaUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TeamController extends Controller
{
    private const USERS_WITHOUT_TEAM_CACHE_TTL_SECONDS = 300;

    public function __construct(
        protected ScheduleCacheService $scheduleCacheService,
        protected TeamMembershipService $teamMembershipService,
    ) {}

    /**
     * LIST ALL TEAMS (OPTIONAL FILTER BY PROJECT)
     * GET /teams
     * GET /teams?project_id=1
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Team::class);

        $query = Team::query()->with(['project', 'leader']);

        if ($request->has('project_id')) {
            $query->where('project_id', $request->project_id);
        }

        $teams = $query->orderBy('project_id')->orderBy('name')->get();

        return response()->json([
            'data' => $teams,
        ]);
    }

    /**
     * USERS IN PROJECT FOR TEAM ASSIGNMENT: without active team first (by name), then with team (+ id/name) sorted by team_id.
     * GET /projects/{project}/users/without-team
     */
    public function usersWithoutTeam(Project $project, Request $request)
    {
        $this->authorize('manage', [Schedule::class, $project]);

        $auth = $request->user();
        $activeOnly = $auth->role !== 'dev';

        $cacheVersion = $this->scheduleCacheService->getScheduleSheetCacheVersion($project->id);
        $cacheKey = $this->scheduleCacheService->usersWithoutTeamCacheKey(
            $project->id,
            $activeOnly,
            $cacheVersion
        );

        $data = Cache::remember(
            $cacheKey,
            now()->addSeconds(self::USERS_WITHOUT_TEAM_CACHE_TTL_SECONDS),
            function () use ($project, $activeOnly) {
                $users = User::query()
                    ->where('project_id', $project->id)
                    ->when($activeOnly, fn ($q) => $q->where('active', true))
                    ->with([
                        'teams' => static function ($q) use ($project) {
                            $q->where('teams.project_id', $project->id)
                                ->wherePivotNull('end_date')
                                ->orderBy('teams.id');
                        },
                    ])
                    ->select(
                        'id',
                        'full_name',
                        'username',
                        'email',
                        'role',
                        'project_id',
                        'organization_id',
                        'active',
                        'avatar'
                    )
                    ->orderBy('full_name')
                    ->get();

                $toRow = static function (User $user, ?int $teamId, ?string $teamName): array {
                    return [
                        'id' => $user->id,
                        'full_name' => $user->full_name,
                        'username' => $user->username,
                        'email' => $user->email,
                        'role' => $user->role,
                        'project_id' => $user->project_id,
                        'organization_id' => $user->organization_id,
                        'active' => $user->active,
                        'avatar_url' => $user->avatar
                            ? SignedMediaUrl::userAvatar($user)
                            : null,
                        'team_id' => $teamId,
                        'team_name' => $teamName,
                    ];
                };

                $withoutTeam = [];
                $withTeam = [];

                foreach ($users as $user) {
                    $activeTeams = $user->teams;
                    if ($activeTeams->isEmpty()) {
                        $withoutTeam[] = $toRow($user, null, null);
                        continue;
                    }

                    $teamName = $activeTeams->pluck('name')->implode(', ');
                    $teamId = $activeTeams->count() === 1 ? (int) $activeTeams->first()->id : null;

                    $withTeam[] = $toRow($user, $teamId, $teamName);
                }

                usort($withTeam, static function (array $a, array $b): int {
                    $ta = $a['team_id'];
                    $tb = $b['team_id'];
                    if ($ta === null && $tb === null) {
                        return strcmp($a['full_name'], $b['full_name']);
                    }
                    if ($ta === null) {
                        return 1;
                    }
                    if ($tb === null) {
                        return -1;
                    }
                    if ($ta !== $tb) {
                        return $ta <=> $tb;
                    }

                    return strcmp($a['full_name'], $b['full_name']);
                });

                return array_merge($withoutTeam, $withTeam);
            }
        );

        return response()->json([
            'success' => true,
            'message' => 'List user project beserta status keanggotaan tim berhasil diambil',
            'data' => $data,
        ]);
    }

    /**
     * LIST TEAMS BY PROJECT
     * GET /projects/{project}/teams
     */
    public function indexByProject(Project $project)
    {
        $this->authorize('viewAnyByProject', [Team::class, $project]);

        $teams = $project->teams()
            ->with(['leader'])
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $teams,
        ]);
    }

    /**
     * CREATE TEAM UNDER PROJECT
     * POST /projects/{project}/teams
     */
    public function store(Request $request, Project $project)
    {
        $this->authorize('manage', [Team::class, $project]);

        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'leader_id' => 'nullable|exists:users,id',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success'     => false,
                'message'     => 'Validation failed',
                'status_code' => 422,
                'errors'      => $e->errors(),
            ], 422);
        }

        if (! empty($validated['leader_id'])) {
            $leader = User::find($validated['leader_id']);
            if ($leader->project_id !== $project->id) {
                return response()->json([
                    'message' => 'Leader user does not belong to this project',
                ], 403);
            }
        }

        $team = Team::create([
            'project_id' => $project->id,
            'name' => $validated['name'],
            'leader_id' => $validated['leader_id'] ?? null,
        ]);

        if (! empty($team->leader_id)) {
            $leader = User::findOrFail($team->leader_id);
            $this->teamMembershipService->moveUserToTeam(
                $leader,
                $team,
                now()->startOfDay(),
                now()->copy()->startOfMonth()
            );
            $this->scheduleCacheService->bumpScheduleSheetCacheVersion($project->id);
        }

        $team->load(['project', 'leader', 'users']);

        return response()->json([
            'message' => 'Team created successfully',
            'data' => $team,
        ], 201);
    }

    /**
     * SHOW TEAM DETAIL
     * GET /teams/{team}
     */
    public function show(Team $team)
    {
        $this->authorize('view', $team);

        $team->load([
            'project',
            'leader',
            'users' => static fn ($q) => $q->wherePivotNull('end_date'),
        ]);

        return response()->json([
            'data' => $team,
        ]);
    }

    /**
     * UPDATE TEAM
     * PUT /teams/{team}
     */
    public function update(Request $request, Team $team)
    {
        $this->authorize('manage', [Team::class, $team->project]);

        try {
            $validated = $request->validate([
                'name' => 'sometimes|string|max:255',
                'leader_id' => 'sometimes|nullable|exists:users,id',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success'     => false,
                'message'     => 'Validation failed',
                'status_code' => 422,
                'errors'      => $e->errors(),
            ], 422);
        }

        $newLeaderId = array_key_exists('leader_id', $validated)
            ? $validated['leader_id']
            : null;

        if ($newLeaderId !== null && $newLeaderId !== '') {
            $leader = User::findOrFail($newLeaderId);
            if ($leader->project_id !== $team->project_id) {
                return response()->json([
                    'message' => 'Leader user does not belong to this project',
                ], 403);
            }
        }

        $oldLeaderId = null;
        DB::transaction(function () use ($team, $validated, &$oldLeaderId) {
            $oldLeaderId = $team->leader_id;
            $team->update($validated);

            if ($team->leader_id) {
                $leader = User::findOrFail($team->leader_id);
                $this->teamMembershipService->moveUserToTeam(
                    $leader,
                    $team,
                    now()->startOfDay(),
                    now()->copy()->startOfMonth()
                );
            }
        });

        if ($team->wasChanged('leader_id') && $oldLeaderId && $team->leader_id) {
            // Ensure new leader has schedules for current month
            $startDate = now()->startOfMonth();
            $endDate = now()->endOfMonth();

            // Calculate membership status for new leader
            $newLeaderTeamUser = DB::table('team_users')
                ->where('user_id', $team->leader_id)
                ->where('team_id', $team->id)
                ->first();

            $memberStatus = Schedule::STATUS_FULL_EXISTING;
            if ($newLeaderTeamUser && $newLeaderTeamUser->start_date) {
                $startDateCarbon = \Carbon\Carbon::parse($newLeaderTeamUser->start_date)->startOfDay();
                if ($startDateCarbon->greaterThan($startDate->copy()->startOfDay())
                    && $startDateCarbon->lessThanOrEqualTo($endDate->copy()->endOfDay())) {
                    $memberStatus = Schedule::STATUS_PRORATE_IN;
                }
            }

            // Copy schedule dari old leader jika ada
            $oldLeaderSchedules = Schedule::where('project_id', $team->project_id)
                ->where('team_id', $team->id)
                ->where('user_id', $oldLeaderId)
                ->whereBetween('date', [$startDate, $endDate])
                ->get();

            if ($oldLeaderSchedules->count() > 0) {
                foreach ($oldLeaderSchedules as $schedule) {
                    Schedule::updateOrCreate(
                        [
                            'project_id' => $team->project_id,
                            'user_id' => $team->leader_id,
                            'date' => $schedule->date,
                        ],
                        [
                            'assignment_id' => $schedule->assignment_id,
                            'team_id' => $team->id,
                            'membership_status' => $memberStatus,
                        ]
                    );
                }
            }

            $this->scheduleCacheService->bumpScheduleSheetCacheVersion($team->project_id);
        }

        $team->load(['project', 'leader']);

        return response()->json([
            'message' => 'Team updated successfully',
            'data' => $team,
        ]);
    }

    /**
     * DELETE TEAM
     * DELETE /teams/{team}?month=2025-12
     */
    public function destroy(Request $request, Team $team)
    {
        $this->authorize('manage', [Team::class, $team->project]);

        $validated = $request->validate([
            'month' => 'required|date_format:Y-m',
        ]);

        $monthStart = $this->teamMembershipService->monthStart($validated['month']);

        $projectId = $team->project_id;

        $deletedSchedules = $this->teamMembershipService->deleteSchedulesFromMonth($team, $monthStart);

        $team->delete();

        $this->scheduleCacheService->bumpScheduleSheetCacheVersion($projectId);

        return response()->json([
            'message' => 'Team deleted successfully',
            'deleted_schedule_count' => $deletedSchedules,
        ]);
    }

    /**
     * LIST TEAM MEMBERS (ACTIVE PIVOT)
     * GET /teams/{team}/members
     */
    public function members(Team $team)
    {
        $this->authorize('view', $team);

        $members = $team->users()
            ->wherePivotNull('end_date')
            ->withPivot(['start_date', 'end_date'])
            ->orderBy('full_name')
            ->get();

        return response()->json([
            'data' => $members,
        ]);
    }
}

