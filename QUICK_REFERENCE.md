# Quick Reference Guide - Attendance & Payroll System

## 🚀 Quick Start (5 minutes)

### 1. Run Migrations
```bash
php artisan migrate
```

### 2. Create Payroll Policy
```bash
curl -X POST http://localhost:8000/api/payroll-policies \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "project_id": 1,
    "policy_code": "STD_2026",
    "policy_name": "Standard Policy 2026",
    "effective_from": "2026-01-01",
    "daily_rate": 150000,
    "hourly_rate": 18750,
    "late_deduction_per_minute": 1500,
    "absence_deduction_amount": 100000,
    "alpha_deduction_amount": 150000,
    "overtime_rate_percent": 150,
    "perfect_attendance_bonus": 300000
  }'
```

### 3. Create Payroll Run
```bash
curl -X POST http://localhost:8000/api/payroll-runs \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
    "project_id": 1,
    "payroll_policy_id": 1,
    "year": 2026,
    "month": 2,
    "pay_period_start": "2026-02-01",
    "pay_period_end": "2026-02-28"
  }'
```

### 4. Calculate Payroll
```bash
curl -X GET http://localhost:8000/api/payroll-runs/1/calculate \
  -H "Authorization: Bearer YOUR_TOKEN"
```

### 5. View Results
```bash
curl -X GET http://localhost:8000/api/payroll-details?payroll_run_id=1 \
  -H "Authorization: Bearer YOUR_TOKEN"
```

---

## 📋 Endpoint Summary

### ABSENCES (HO Admin)
| Method | Endpoint | Purpose |
|--------|----------|---------|
| POST | `/absences` | Create absence (SAKIT/IZIN/CUTI) |
| GET | `/absences?project_id=1&status=PENDING` | List pending approvals |
| PATCH | `/absences/{id}/approve` | Approve absence |
| PATCH | `/absences/{id}/reject` | Reject absence |

### OVERTIME LOGS (HO Admin)
| Method | Endpoint | Purpose |
|--------|----------|---------|
| POST | `/overtime-logs` | Create OT request (OFF_DUTY/EXTEND_SHIFT) |
| GET | `/overtime-logs?project_id=1&status=APPROVED` | List approved OT |
| PATCH | `/overtime-logs/{id}/approve` | Approve OT |
| PATCH | `/overtime-logs/{id}/complete` | Mark actual times |

### PAYROLL POLICIES (HO Admin)
| Method | Endpoint | Purpose |
|--------|----------|---------|
| POST | `/payroll-policies` | Create policy (configurable rates) |
| GET | `/payroll-policies?project_id=1` | List policies |
| PATCH | `/payroll-policies/{id}` | Update policy |

### PAYROLL RUNS (HO Admin)
| Method | Endpoint | Purpose |
|--------|----------|---------|
| POST | `/payroll-runs` | Create monthly payroll |
| GET | `/payroll-runs/1/calculate` | Calculate all employees |
| PATCH | `/payroll-runs/1/finalize` | Lock payroll (can't edit) |
| PATCH | `/payroll-runs/1/mark-paid` | Mark as paid |

### PAYROLL DETAILS (HO + Employees)
| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/payroll-details?payroll_run_id=1` | List all payroll details |
| GET | `/payroll-details/101` | View detailed breakdown |
| GET | `/payroll-details/101/export` | Export as text |

---

## 🔑 Key Models & Relationships

```
User
├── hasMany Absences
├── hasMany OvertimeLogs
├── hasMany PayrollDetails
└── hasMany Attendances

Schedule
├── hasOne Attendance
├── hasOne Absence
├── hasMany OvertimeLogs
└── belongsTo Assignment

PayrollRun
├── belongsTo PayrollPolicy
└── hasMany PayrollDetails

PayrollDetail
├── belongsTo User
├── belongsTo PayrollRun
└── belongsTo Assignment
```

---

## 💰 Payroll Calculation Formula

```
BASE SALARY
= attendance_count × daily_rate

DEDUCTIONS
= late_deduction
  + absence_deduction (SAKIT + IZIN)
  + cuti_deduction (if unpaid)
  + alpha_deduction

ADDITIONS
= overtime_hours × hourly_rate × (overtime_rate_percent/100)
  + (attendance_count × daily_allowance)
  + (no late & no alpha ? perfect_attendance_bonus : 0)

NET SALARY = BASE + ADDITIONS - DEDUCTIONS
```

---

## 🧮 Calculation Examples

### Perfect Attendance
```
20 working days, 0 late, 0 absence, 0 alpha, 0 overtime

Base:           20 × 150k = 3,000,000
Allowance:      20 × 25k  = 500,000
Bonus:          (no late, no alpha) = 300,000
Deductions:     0
───────────────────────────
NET:            3,800,000
```

### With Issues (Late + Alpha)
```
20 working days, 2 late (30 min total), 1 absence (SAKIT), 1 alpha

Base:           17 × 150k = 2,550,000  (only attended days)
Allowance:      17 × 25k  = 425,000
Bonus:          (has alpha) = 0
Late Deduct:    30 min × 1,500 = (45,000)
Absence Deduct: 1 × 100k = (100,000)
Alpha Deduct:   1 × 150k = (150,000)
───────────────────────────
NET:            2,580,000
```

### With Overtime
```
20 working days, 2 late (30 min), 6 hours OT (approved)

Base:           20 × 150k = 3,000,000
Overtime:       6 × 18.75k × 1.5 = 168,750
Allowance:      20 × 25k  = 500,000
Bonus:          (has late) = 0
Late Deduct:    (45,000)
───────────────────────────
NET:            3,623,750
```

---

## 📊 Payroll Workflow

```
STEP 1: SETUP
├─ Create PayrollPolicy (rates, deductions, bonuses)
└─ Assign to Project

STEP 2: MONTHLY CYCLE
├─ Users check-in/check-out (Attendance)
├─ HO create Absence if user doesn't come
├─ Security app validates (no attendance if absence approved)
├─ HO approve pending Overtime
└─ Count days: [Attended] + [Absence] + [Alpha] = Total

STEP 3: GENERATE
├─ POST /payroll-runs (create monthly batch)
├─ GET /payroll-runs/{id}/calculate (generate details)
└─ All PayrollDetails populated automatically

STEP 4: APPROVAL
├─ PATCH /payroll-runs/{id}/finalize (HO/Direktur approve)
└─ System locks for editing

STEP 5: PAYMENT
├─ PATCH /payroll-runs/{id}/mark-paid (execute transfer)
└─ Updated paid_at timestamp

STEP 6: ARCHIVAL
└─ Data locked & immutable
```

---

## ⚡ Common Operations

### Create Absence (User SAKIT)
```json
POST /absences
{
  "project_id": 1,
  "user_id": 5,
  "schedule_id": 120,
  "assignment_id": 3,
  "date": "2026-02-10",
  "absence_type": "SAKIT",
  "attachment_url": "https://..."
}
→ Status: PENDING
→ Waiting HO approval
```

### Approve Absence
```json
PATCH /absences/1/approve
{
  "approved_by": 1
}
→ Status: APPROVED
→ Prevents check-in that day
```

### Create Overtime (OFF_DUTY)
```json
POST /overtime-logs
{
  "schedule_id": 120,
  "date": "2026-02-10",
  "overtime_type": "OFF_DUTY",
  "planned_start_time": "08:00:00",
  "planned_end_time": "17:00:00",
  "status": "APPROVED",
  "approved_by": 1
}
→ User already can check-in at 08:00
→ All hours counted as OT
```

### Calculate Payroll (Auto for all users)
```json
GET /payroll-runs/1/calculate
→ Queries all Schedules in period
→ For each Schedule:
   - Check Attendance (if exists)
   - Check Absence (if APPROVED)
   - Else → Alpha
→ Creates PayrollDetail per user
```

### View Employee Payroll
```json
GET /payroll-details/101
→ Shows:
   - Base salary calculation
   - Attendance breakdown
   - Deductions & additions
   - Daily breakdown
   - Attendance rate %
```

---

## 🔒 Status Transitions

### Absence Workflow
```
PENDING → APPROVED → (locked for edit)
      ↘ REJECTED  → (deleted or archived)
```

### Overtime Workflow
```
PENDING → APPROVED → COMPLETED (after check-out)
      ↘ REJECTED   → (deleted or archived)
```

### Payroll Workflow
```
DRAFT → (calculate) → DRAFT → (finalize) → FINALIZED → (paid) → PAID
    ↘ (cancel)       ↘ (recalculate)  ↘ (cancel)    ↘ (keep)
  CANCELLED                                      CANCELLED
```

---

## 📱 Integration with Existing Code

### In AttendanceController::checkIn()
```php
// Before creating attendance:
1. Check AbsenceService::hasApprovedAbsence()
   ↘ If yes, reject check-in
   
2. Get OvertimeService::getApprovedOvertimeByDate()
   ↘ If yes, use OT start_time instead
   
3. Calculate late_minutes vs standard vs OT start_time
4. Create Attendance with overtime fields
```

### In ScheduleController
```php
// When creating schedule:
1. Can assign Assignment = O (OFF duty)
2. System treats all attendance that day as OT
```

---

## 🎯 Files Created/Modified

### New Files (12)
```
Migrations (7):
  - 2026_02_10_000001_create_absences_table.php
  - 2026_02_10_000002_create_overtime_logs_table.php
  - 2026_02_10_000003_create_payroll_policies_table.php
  - 2026_02_10_000004_create_payroll_runs_table.php
  - 2026_02_10_000005_create_payroll_details_table.php
  - 2026_02_10_000006_modify_attendances_table.php
  - 2026_02_10_000007_modify_assignments_table.php

Models (5):
  - app/Models/Absence.php
  - app/Models/OvertimeLog.php
  - app/Models/PayrollPolicy.php
  - app/Models/PayrollRun.php
  - app/Models/PayrollDetail.php

Services (4):
  - app/Services/PayrollCalculationService.php
  - app/Services/PayrollGenerationService.php
  - app/Services/AbsenceService.php
  - app/Services/OvertimeService.php

Controllers (5):
  - app/Http/Controllers/Api/AbsenceController.php
  - app/Http/Controllers/Api/OvertimeLogController.php
  - app/Http/Controllers/Api/PayrollPolicyController.php
  - app/Http/Controllers/Api/PayrollRunController.php
  - app/Http/Controllers/Api/PayrollDetailController.php

Documentation (4):
  - ATTENDANCE_PAYROLL_SYSTEM.md
  - IMPLEMENTATION_GUIDE.md
  - API_ROUTES_PAYROLL.php
  - POSTMAN_COLLECTION.json
  - QUICK_REFERENCE.md (this file)
```

### Modified Files (4)
```
Models:
  - app/Models/Attendance.php (added overtime fields & scopes)
  - app/Models/Assignment.php (added is_off & relationships)
  - app/Models/Schedule.php (added relationships)
  - app/Models/User.php (added relationships)
```

---

## 🚨 Important Notes

1. **No Hardcoding**: ALL financial values configurable via PayrollPolicy
2. **Conflict Prevention**: Can't have both Attendance & Absence same day
3. **Approval Flow**: Absence & Overtime must be approved before counting in payroll
4. **Immutability**: Finalized payroll can't be changed (only recalculate from scratch)
5. **Scalability**: Service-based architecture allows easy modifications
6. **Audit Trail**: All changes tracked with timestamps & user references

---

## 🆘 Troubleshooting

| Issue | Solution |
|-------|----------|
| "Payroll already exists" | Delete previous or use different period |
| No employees in payroll | Check schedules exist for period |
| Incorrect calculation | Verify policy rates, check for updates |
| Can't approve absence | Check if attendance already exists |
| OT not counted in payroll | Verify overtime_status = APPROVED |

---

## 📞 Contact & Support

- Design queries → See `ATTENDANCE_PAYROLL_SYSTEM.md`
- Implementation help → See `IMPLEMENTATION_GUIDE.md`
- API testing → Import `POSTMAN_COLLECTION.json`
- Code structure → Check file locations above

---

**Version:** 1.0.0 | **Updated:** Feb 10, 2026 | **Status:** Ready for Production
