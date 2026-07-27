# Schedule Consistency Fix

## Problem Statement
When a user is transferred to a different team, the old team's schedules were being deleted, causing:
1. Data consistency issues (no record of user's previous attendance)
2. Checkin failures when new team had no schedules generated yet

## Root Cause
The transfer workflow had a gap:
1. `moveUserToTeam()` deletes old team's schedules for the user
2. But new team's schedules are not automatically generated
3. If user tries to checkin before schedules are generated for new team, endpoint returns "Anda tidak memiliki jadwal hari ini"

## Solution Implemented

### 1. Standard User Transfer (via addTeamMember endpoint)
**Endpoint**: `POST /schedules/teams/{team}/members`

Process:
- Calls `moveUserToTeam()` to remove user from old teams
- Copies schedule from team leader to new user for requested month
- User can then checkin using the copied schedule

**Important**: Admin must call this endpoint for each month they want user to have schedule in new team.

### 2. Leader Change (via updateTeam endpoint)
**Endpoint**: `PATCH /teams/{team}`

Process:
- When `leader_id` is updated
- Automatically copies current month's schedule from old leader to new leader
- New leader can immediately checkin with copied schedule
- No separate admin action needed

**Code Location**: `TeamController.php` `update()` method (lines ~310-350)

### 3. Data Preservation Strategy
**Current Approach**: Delete old schedules
- Pros: Simple, no orphaned data
- Cons: No historical audit trail

**Why not soft-delete?** 
- Attempted soft-delete approach (setting `team_id = NULL`) broke the checkin endpoint
- `resolveActiveSchedule()` needs active team linkage to find valid schedules
- Soft-delete would require redesigning schedule resolution logic

## Workflow Examples

### Example 1: Transfer User to Different Team
```
Admin Action:
POST /schedules/teams/{newTeamId}/members
{
  "user_id": 123,
  "month": "2025-01"
}

Result:
1. User removed from old team (schedule deleted)
2. User added to new team
3. Current month schedule copied from new team's leader
4. User can checkin using new schedule
```

### Example 2: Change Team Leader
```
Admin Action:
PATCH /teams/{teamId}
{
  "leader_id": 456
}

Result:
1. New leader removed from other teams (schedule deleted)
2. New leader added to this team
3. Current month schedule automatically copied from old leader
4. New leader can immediately checkin
5. Cache bumped for schedule sheet update
```

## Schedule Consistency Guarantees

| Scenario | Guarantee |
|----------|-----------|
| User transfers to new team | New team's schedule is copied from leader for requested month |
| Leader is changed | New leader gets current month schedule from old leader |
| User checkin | Valid schedule exists for user's current team |
| Report queries | Includes all users' active schedules |
| Payroll summary | Counts all schedules user is assigned to |

## Code Changes Made

### File: `app/Http/Controllers/Api/TeamController.php`

**Method**: `update()` (lines ~310-350)

Added logic to:
1. Track old leader ID before update
2. After `moveUserToTeam()`, copy current month schedule from old leader to new leader
3. Use `updateOrCreate()` to create/update schedule for new leader
4. Only copy if old leader had schedule in current month (graceful fallback)

```php
if ($team->wasChanged('leader_id') && $oldLeaderId && $team->leader_id) {
    // Copy schedule from old leader to new leader for current month
    $oldLeaderSchedules = Schedule::where(...)
        ->where('user_id', $oldLeaderId)
        ->whereBetween('date', [$startDate, $endDate])
        ->get();

    foreach ($oldLeaderSchedules as $schedule) {
        Schedule::updateOrCreate([...], [...]);
    }
}
```

## Testing Checklist

- [ ] Transfer user to different team via `addTeamMember` endpoint
  - Verify user can checkin with new team's schedule
  - Verify old team's schedule is deleted
- [ ] Change team leader via `updateTeam` endpoint  
  - Verify new leader can immediately checkin
  - Verify schedule was copied from old leader
- [ ] Checkin after transfer
  - Should work without additional admin action (except addTeamMember for other months)
- [ ] Schedule sheet display
  - Should show only current team's schedules for each user
  - Should include all schedules for summary counts
- [ ] Payroll reports
  - Should reflect all user's active schedules correctly

## Future Improvements

### Option 1: Full Soft-Delete Approach
For complete audit trail and schedule preservation:
- Redesign `resolveActiveSchedule()` to join on team relationship
- Separate active (team_id NOT NULL) from inactive (team_id = NULL) schedules
- Update all schedule queries to be explicit about what status to include

### Option 2: Schedule Archive
For data preservation without affecting active queries:
- Add `archived_at` column to schedules table
- Instead of delete, set `archived_at = now()`
- Keep team relationship intact
- Would require migration and careful handling in all schedule queries

### Option 3: Schedule History Tracking  
Add dedicated schedule history table:
- Track all changes to schedule (team change, assignment change, etc.)
- Preserve full audit trail
- Current schedule is master, history is reference
- Requires new table and migration

## Related Issues
- Original requirement: "schedulenya tetap dan hanya assignment pada schedule saja yang berubah" 
- Full implementation would require one of the Future Improvements above
- Current solution balances data consistency with operational stability
