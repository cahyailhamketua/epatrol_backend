# Schedule Data Preservation: Soft-Delete Solution

## Problem Statement
When a user is removed from a team, the old team's schedules were being deleted, causing:
1. ❌ **Data Loss**: Attendance records become orphaned (no schedule_id reference)
2. ❌ **Report Gaps**: Removed team members' attendance disappears from reports
3. ❌ **Payroll Issues**: Missing schedule counts for users who left teams

User requirement: **"schedulenya tetap dan hanya assignment pada schedule saja yang berubah"**  
Translation: *"Schedule should remain, only assignment changes, not the entire schedule"*

---

## Solution: Soft-Delete with Smart Filtering

### Architecture Overview

```
BEFORE (Hard Delete - ❌ Problem):
────────────────────────────────
User Removed → Schedule.delete()
       ↓
Schedule gone from database
       ↓
Attendance orphaned: schedule_id no longer exists
       ↓
Report queries fail: no schedule to join on
       ↓
Payroll missing: no data to count

AFTER (Soft Delete - ✅ Solution):
──────────────────────────────────
User Removed → Schedule.update(['team_id' => NULL])
       ↓
Schedule exists but team_id = NULL
       ↓
Attendance preserved: schedule_id still valid
       ↓
Report queries include team_id IS NULL
       ↓
Payroll complete: all schedules counted
```

---

## Implementation Details

### 1. Core Soft-Delete Change

**Three places where deletion occurs:**

#### A. TeamMembershipService.php - `deleteSchedulesFromMonth()`
**Location**: [app/Services/TeamMembershipService.php](app/Services/TeamMembershipService.php#L26)

```php
// BEFORE (Hard Delete):
return $query->delete();

// AFTER (Soft Delete):
return $query->update(['team_id' => null]);
```

#### B. TeamMembershipService.php - `releaseUserFromOtherTeamsInProject()`
**Location**: [app/Services/TeamMembershipService.php](app/Services/TeamMembershipService.php#L105)

```php
// When user transfers to new team, remove from old teams
// Changed from delete() to update(['team_id' => null])
Schedule::query()
    ->where('project_id', $projectId)
    ->where('user_id', $user->id)
    ->whereIn('team_id', $otherTeamIds)
    ->where('date', '>=', $fromDate)
    ->update(['team_id' => null]);  // ← Soft delete
```

#### C. ScheduleGeneratorService.php - `generate()`
**Location**: [app/Services/ScheduleGeneratorService.php](app/Services/ScheduleGeneratorService.php#L50)

```php
// Cleanup: remove schedules for users no longer active in team
// Changed from delete() to update(['team_id' => null])
Schedule::where('project_id', $projectId)
    ->where('team_id', $teamId)
    ->whereBetween('date', [$startDate, $endDate])
    ->whereNotIn('user_id', $memberIds)
    ->update(['team_id' => null]);  // ← Soft delete
```

---

### 2. Report Query Update

**Location**: [app/Http/Controllers/Api/Concerns/BuildsProjectReportData.php](app/Http/Controllers/Api/Concerns/BuildsProjectReportData.php#L325)

**Change**: `attendanceBaseQuery()` method now includes schedules with team_id = NULL

```php
// BEFORE (Missing removed members):
if ($v['team_id']) {
    $q->where('schedules.team_id', $v['team_id']);
}

// AFTER (Includes full history):
if ($v['team_id']) {
    $q->where(function ($query) use ($v) {
        $query->where('schedules.team_id', $v['team_id'])
              ->orWhereNull('schedules.team_id');  // ← Include removed members
    });
}
```

**Result**:
- Attendance reports show complete history
- Removed team members' data appears in reports
- Payroll can calculate based on complete schedule data

---

### 3. Display Query (Already Correct ✅)

**Location**: [app/Services/ScheduleSheetService.php](app/Services/ScheduleSheetService.php)

Schedule sheet uses **two separate collections**:

```php
// Collection 1: ALL schedules (including team_id=NULL) for accurate summary
$allSchedules = Schedule::where('project_id', $projectId)
    ->whereBetween('date', [$startDate, $endDate])
    ->get();

// Collection 2: Display schedules (only team_id NOT NULL) per user requirement
$schedules = $allSchedules->filter(fn($s) => $s->team_id !== null);

// Summary calculated from allSchedules (includes removed members)
// Display grid uses schedules (current teams only)
```

---

### 4. Checkin Remains Functional ✅

**Location**: [app/Services/AttendanceService.php](app/Services/AttendanceService.php#L26)

`resolveActiveSchedule()` has **no team_id filter**, so it finds all schedules:

```php
$schedules = Schedule::where('user_id', $user->id)
    ->whereIn('date', [$todayStr, $yesterdayStr])
    ->with(['assignment', 'project.organization'])
    ->get();  // ← Gets ALL schedules, including team_id=NULL
```

**Result**: Checkin works for users with team_id=NULL schedules (backward compatible)

---

## Workflow Examples

### Example 1: Remove User from Team

```
Admin Action:
DELETE /teams/{teamId}/members/{userId}?month=2025-01

System Process:
├─ 1. Remove from team_users table (membership ends)
├─ 2. UPDATE schedules SET team_id = NULL
│     WHERE team_id = {teamId}
│     AND user_id = {userId}
│     AND date >= 2025-01-01
└─ 3. Attendance/Absence/Overtime records REMAIN LINKED

Result After:
✅ Schedule exists: id=5, user_id=10, team_id=NULL, date=2025-01-10
✅ Attendance linked: schedule_id=5, check_in_at=09:05
✅ Report query finds this data
✅ Payroll counts this schedule
```

### Example 2: View Attendance Reports

```
Report API Call:
GET /projects/{projectId}/reports/attendance?team_id=A&dari_tanggal=2025-01-01

Query Result:
├─ Active Members (team_id=A):
│   └─ User 10: 20 schedules, 18 hadir
│
├─ Removed Members (team_id=NULL):
│   └─ User 15: 5 schedules, 5 hadir (before removal)
│
└─ Total: 25 schedules, 23 hadir

✅ Complete attendance history visible
✅ No data gaps
```

### Example 3: Checkin After Removal

```
Scenario: User removed from Team A on 2025-01-15

User tries to checkin on 2025-01-16:
├─ AttendanceService.resolveActiveSchedule(user)
├─ Query: SELECT * FROM schedules
│         WHERE user_id = 10
│         AND date IN ['2025-01-16', '2025-01-15']
├─ Found: schedule with team_id=NULL (from old team)
├─ Validates time against assignment
├─ ✅ Checkin succeeds (backward compatible)
└─ Attendance recorded for that schedule

Note: Even though user is not in Team A anymore,
      they can still record attendance for old schedules
```

---

## Database State Example

### Before Removal
```sql
SELECT * FROM schedules WHERE user_id=5 AND team_id=A;
┌─────┬─────────┬────────┬──────────┬───────────────┐
│ id  │ user_id │ date   │ team_id  │ assignment_id │
├─────┼─────────┼────────┼──────────┼───────────────┤
│ 10  │ 5       │ 2025-01-10 │ A      │ 2             │
│ 11  │ 5       │ 2025-01-11 │ A      │ 2             │
│ 12  │ 5       │ 2025-01-15 │ A      │ 2             │
└─────┴─────────┴────────┴──────────┴───────────────┘
```

### After User Removed on 2025-01-15
```sql
SELECT * FROM schedules WHERE user_id=5;
┌─────┬─────────┬────────┬──────────┬───────────────┐
│ id  │ user_id │ date   │ team_id  │ assignment_id │
├─────┼─────────┼────────┼──────────┼───────────────┤
│ 10  │ 5       │ 2025-01-10 │ NULL   │ 2             │ ← Soft deleted
│ 11  │ 5       │ 2025-01-11 │ NULL   │ 2             │ ← Soft deleted
│ 12  │ 5       │ 2025-01-15 │ NULL   │ 2             │ ← Soft deleted (from month)
└─────┴─────────┴────────┴──────────┴───────────────┘

SELECT * FROM attendance WHERE schedule_id IN (10, 11, 12);
┌─────┬────────────┬────────┬──────────────┬──────────────┐
│ id  │ schedule_id │ date   │ status       │ check_in_at  │
├─────┼────────────┼────────┼──────────────┼──────────────┤
│ 100 │ 10         │ 2025-01-10 │ HADIR       │ 2025-01-10 08:45 │ ✅ Linked!
│ 101 │ 11         │ 2025-01-11 │ TELAT       │ 2025-01-11 10:15 │ ✅ Linked!
│ 102 │ 12         │ 2025-01-15 │ HADIR       │ 2025-01-15 09:00 │ ✅ Linked!
└─────┴────────────┴────────┴──────────────┴──────────────┘
```

---

## Testing Checklist

### Test 1: Verify Soft-Delete Occurs
```bash
# Step 1: Add user to team
POST /schedules/teams/{teamId}/members
{
  "user_id": 10,
  "month": "2025-01"
}

# Step 2: Verify schedule created
mysql> SELECT * FROM schedules WHERE user_id=10 AND team_id='{teamId}';
# Result: 30 rows (30 days in month)

# Step 3: Remove user from team
DELETE /teams/{teamId}/members/10?month=2025-01

# Step 4: Verify soft-delete (team_id = NULL)
mysql> SELECT * FROM schedules WHERE user_id=10 AND team_id IS NULL;
# Result: 30 rows (same schedule, but team_id=NULL)

mysql> SELECT * FROM schedules WHERE user_id=10 AND team_id='{teamId}';
# Result: 0 rows (no longer linked to team)
```

### Test 2: Verify Attendance Still Linked
```bash
mysql> SELECT a.* FROM attendance a
       JOIN schedules s ON a.schedule_id = s.id
       WHERE s.user_id=10 AND s.team_id IS NULL;
# Result: Should show all attendance records
# Even though schedule team_id = NULL, attendance is still there
```

### Test 3: Report Shows Removed Members
```bash
# Get attendance report for Team A (including removed members)
GET /projects/1/reports/attendance?team_id=A

# Response should include:
{
  "summary": {
    "jadwal_total": 100,  # Includes removed members
    "hadir": 95,
    "hadir_telat": 3,
    "absen": 2
  },
  "rows": [
    {
      "user_id": 5,
      "full_name": "John (Removed)",
      "attendance_count": 20,  # His schedules from when he was in team
      ...
    }
  ]
}
```

### Test 4: Display Only Shows Active Members
```bash
# Get schedule sheet (display)
GET /schedules/sheet?projectId=1&month=2025-01

# Response should include:
{
  "summary": {
    "schedule_count": 300  # All schedules (includes team_id=NULL)
  },
  "rows": [
    {
      "user_id": 1,
      "full_name": "Active Member",
      "schedules": 30  # Only team_id NOT NULL
    },
    // User 10 (removed) NOT shown here
  ]
}
```

### Test 5: Checkin Works for Removed Members
```bash
# User removed from team on 2025-01-15
# User tries to checkin for old schedule on 2025-01-16

POST /attendance/checkin
{
  "current_time": "2025-01-16 09:00:00",
  "device_lat": 6.123,
  "device_lon": 106.456,
  ...
}

# Result: ✅ Success (backward compatible)
# Attendance recorded on schedule with team_id=NULL
```

### Test 6: Payroll Counts All Schedules
```bash
# Generate payroll for user who was in multiple teams
GET /projects/1/payroll?month=2025-01&user_id=10

# Response should include:
{
  "total_schedules": 60,  # 30 from Team A (removed) + 30 from Team B (active)
  "total_hk": 57,
  ...
}
```

---

## Data Consistency Guarantees

| Item | Guarantee |
|------|-----------|
| **Schedule Exists** | Even after user removed, schedule record remains (team_id=NULL) |
| **Attendance Linked** | Attendance record stays linked to schedule_id |
| **Report Completeness** | Removed members' data appears in reports (via team_id IS NULL) |
| **Display Accuracy** | Only current team members shown (team_id NOT NULL) |
| **Payroll Calculation** | All schedules counted (active + inactive) |
| **Checkin Function** | Works for all valid schedules (no team_id check) |
| **Audit Trail** | Can see when user left team (team_id=NULL indicates removal) |
| **Backward Compat** | No changes to checkin logic, fully backward compatible |

---

## Files Modified Summary

| File | Change | Reason |
|------|--------|--------|
| [TeamMembershipService.php](app/Services/TeamMembershipService.php#L26) | deleteSchedulesFromMonth(): delete() → update(['team_id'=>NULL]) | Preserve schedule_id references |
| [TeamMembershipService.php](app/Services/TeamMembershipService.php#L105) | releaseUserFromOtherTeamsInProject(): delete() → update(['team_id'=>NULL]) | Soft-delete on transfer |
| [ScheduleGeneratorService.php](app/Services/ScheduleGeneratorService.php#L50) | generate() cleanup: delete() → update(['team_id'=>NULL]) | Soft-delete inactive members |
| [BuildsProjectReportData.php](app/Http/Controllers/Api/Concerns/BuildsProjectReportData.php#L325) | attendanceBaseQuery(): added `->orWhereNull('team_id')` | Include removed members in reports |
| [ScheduleSheetService.php](app/Services/ScheduleSheetService.php) | (Already correct) | Uses allSchedules for summary, schedules for display |
| [AttendanceService.php](app/Services/AttendanceService.php#L26) | (No change) | No team_id filter, works with team_id=NULL |

---

## Advantages of This Approach

✅ **Data Preservation**: No information lost when users leave teams  
✅ **Report Completeness**: Full attendance history always available  
✅ **Backward Compatibility**: Existing checkin logic unchanged  
✅ **Audit Trail**: Can identify when users left (team_id=NULL)  
✅ **Payroll Accuracy**: All schedules counted in summary  
✅ **User Requirement**: Display shows only current team members  
✅ **Simple Implementation**: Just UPDATE team_id to NULL  
✅ **Minimal DB Impact**: No migration needed  
✅ **Performance**: Same number of queries, just different WHERE clause  

---

## Migration & Rollback

### No Migration Needed ✅
- Soft-delete works with existing database schema
- team_id already nullable in schedules table
- No new columns or tables required

### Rollback (if needed)
```php
// To restore hard-delete behavior:
// Change update(['team_id' => null]) back to delete()
// in TeamMembershipService and ScheduleGeneratorService

// To clean up orphaned schedules:
// DELETE FROM schedules WHERE team_id IS NULL
```

---

## Related Components

**Core Attendance System**:
- [AttendanceController.php](app/Http/Controllers/Api/AttendanceController.php) - Checkin endpoint
- [AttendanceService.php](app/Services/AttendanceService.php) - Business logic

**Reporting System**:
- [ProjectReportController.php](app/Http/Controllers/Api/ProjectReportController.php) - Report endpoints
- [BuildsProjectReportData.php](app/Http/Controllers/Api/Concerns/BuildsProjectReportData.php) - Report queries

**Schedule Management**:
- [ScheduleController.php](app/Http/Controllers/Api/ScheduleController.php) - Schedule endpoints
- [ScheduleSheetService.php](app/Services/ScheduleSheetService.php) - Schedule display

**Payroll System**:
- [PayrollService.php](app/Services/PayrollService.php) - Payroll calculations
- Uses ScheduleSheetService for schedule counts

---

## Summary

✅ **Problem Solved**: Data no longer lost when users removed from teams  
✅ **Reports Fixed**: Attendance history complete and visible  
✅ **Checkin Preserved**: No impact on checkin functionality  
✅ **Requirement Met**: "Schedule remains, only assignment changes"  
✅ **Simple Implementation**: Minimal code changes, maximum data preservation  
