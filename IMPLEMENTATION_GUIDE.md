# Panduan Implementasi Sistem Attendance, Absence & Payroll

## 📋 Overview

Dokumen ini mencakup penjelasan lengkap tentang implementasi 5 table baru dan 5 controller API untuk sistem manajemen gaji terintegrasi dengan attendance dan absence.

---

## 🗄️ Database Structure

### New Tables Created (7 files)

1. **absences Table** - Absence, Sakit, Izin, Cuti
2. **overtime_logs Table** - Overtime requests & tracking
3. **payroll_policies Table** - Configurable salary rules
4. **payroll_runs Table** - Monthly payroll batches
5. **payroll_details Table** - Individual payroll per employee
6. **attendances Table** - Modified (added overtime columns)
7. **assignments Table** - Modified (added is_off flag)

### Migration Files Location
```
database/migrations/
├── 2026_02_10_000001_create_absences_table.php
├── 2026_02_10_000002_create_overtime_logs_table.php
├── 2026_02_10_000003_create_payroll_policies_table.php
├── 2026_02_10_000004_create_payroll_runs_table.php
├── 2026_02_10_000005_create_payroll_details_table.php
├── 2026_02_10_000006_modify_attendances_table.php
└── 2026_02_10_000007_modify_assignments_table.php
```

### Run Migrations
```bash
php artisan migrate
```

---

## 📦 Models Created

### Location: `app/Models/`

1. **Absence.php** - Absence model dengan relasi lengkap
2. **OvertimeLog.php** - Overtime logging dengan approval flow
3. **PayrollPolicy.php** - Policy configuration model
4. **PayrollRun.php** - Monthly payroll batch model
5. **PayrollDetail.php** - Individual payroll calculation

### Updated Models
- **Attendance.php** - Added overtime fields & scopes
- **Assignment.php** - Added is_off flag & relationships
- **Schedule.php** - Added absence & overtime relationships
- **User.php** - Added absence, overtime, payroll relationships

---

## 🧠 Services (Business Logic)

### Location: `app/Services/`

#### 1. **PayrollCalculationService.php**
Logika perhitungan gaji single employee:
```php
// Usage
$service = new PayrollCalculationService();
$calculation = $service->calculateUserPayroll(
    userId: 5,
    projectId: 1,
    periodStart: Carbon::parse('2026-02-01'),
    periodEnd: Carbon::parse('2026-02-28'),
    policy: PayrollPolicy::find(1)
);

// Returns
[
    'metrics' => [...],
    'base_salary' => 3000000,
    'deductions' => ['late' => 45000, ...],
    'additions' => ['overtime' => 200000, ...],
    'net_salary' => 3455000
]
```

#### 2. **PayrollGenerationService.php**
Bulk generate payroll untuk semua employee:
```php
// Usage
$service = new PayrollGenerationService($calculationService);
$count = $service->generatePayrollDetails($payrollRun);

// Handle finalization
$service->finalizePayrollRun($payrollRun, approvedById: 1);
$service->markPayrollAsPaid($payrollRun, paidDate: '2026-03-05');
```

#### 3. **AbsenceService.php**
Manage absence dengan validasi conflict:
```php
// Create dengan auto-check attendance conflict
$absence = $service->createAbsence([
    'user_id' => 5,
    'date' => '2026-02-10',
    'absence_type' => 'SAKIT'
]);

// Approve/Reject
$service->approveAbsence($absence, approvedById: 1);
$service->rejectAbsence($absence, reason: 'No document');
```

#### 4. **OvertimeService.php**
Manage overtime dengan tracking actual vs planned:
```php
// Create OT request
$ot = $service->createOvertimeLog([
    'user_id' => 5,
    'planned_start_time' => '17:00:00',
    'planned_end_time' => '20:00:00'
]);

// Complete dengan actual times
$service->completeOvertimeLog(
    $ot, 
    '17:05:00',  // actual start
    '20:00:00'   // actual end
);
```

---

## 🎯 API Controllers

### Location: `app/Http/Controllers/Api/`

#### 1. AbsenceController
- `POST /api/absences` - Create absence
- `GET /api/absences` - List with filters
- `GET /api/absences/{id}` - Get detail
- `PATCH /api/absences/{id}/approve` - Approve
- `PATCH /api/absences/{id}/reject` - Reject
- `DELETE /api/absences/{id}` - Delete (PENDING only)

**Example:**
```bash
POST /api/absences
{
    "user_id": 5,
    "project_id": 1,
    "schedule_id": 120,
    "assignment_id": 3,
    "date": "2026-02-10",
    "absence_type": "SAKIT",
    "attachment_url": "https://..."
}
```

#### 2. OvertimeLogController
- `POST /api/overtime-logs` - Create OT request
- `GET /api/overtime-logs` - List OT
- `GET /api/overtime-logs/{id}` - Get detail
- `PATCH /api/overtime-logs/{id}/approve` - Approve
- `PATCH /api/overtime-logs/{id}/reject` - Reject
- `PATCH /api/overtime-logs/{id}/complete` - Complete with actual times
- `DELETE /api/overtime-logs/{id}` - Delete (PENDING only)

**Example:**
```bash
POST /api/overtime-logs
{
    "user_id": 5,
    "assignment_id": 3,
    "schedule_id": 120,
    "date": "2026-02-10",
    "overtime_type": "OFF_DUTY",
    "planned_start_time": "08:00:00",
    "planned_end_time": "17:00:00",
    "status": "APPROVED",
    "approved_by": 1
}
```

#### 3. PayrollPolicyController
- `POST /api/payroll-policies` - Create policy
- `GET /api/payroll-policies` - List policies
- `GET /api/payroll-policies/{id}` - Get policy
- `PATCH /api/payroll-policies/{id}` - Update
- `DELETE /api/payroll-policies/{id}` - Delete

**Example:**
```bash
POST /api/payroll-policies
{
    "project_id": 1,
    "policy_code": "STD_SECURITY_2026",
    "policy_name": "Standard Security 2026",
    "daily_rate": 150000,
    "hourly_rate": 18750,
    "late_deduction_per_minute": 1500,
    "absence_deduction_amount": 100000,
    "alpha_deduction_amount": 150000,
    "overtime_rate_percent": 150
}
```

#### 4. PayrollRunController
- `POST /api/payroll-runs` - Create monthly payroll
- `GET /api/payroll-runs` - List payroll runs
- `GET /api/payroll-runs/{id}` - Get detail
- `GET /api/payroll-runs/{id}/calculate` - Calculate all employees
- `PATCH /api/payroll-runs/{id}/finalize` - Lock payroll
- `PATCH /api/payroll-runs/{id}/mark-paid` - Mark as paid
- `PATCH /api/payroll-runs/{id}/cancel` - Cancel
- `PATCH /api/payroll-runs/{id}/recalculate` - Recalculate

**Flow:**
```
DRAFT → (Calculate) → DRAFT → (Finalize) → FINALIZED → (Mark Paid) → PAID
```

#### 5. PayrollDetailController
- `GET /api/payroll-details` - List with filters
- `GET /api/payroll-details/{id}` - Get detailed breakdown
- `GET /api/payroll-details/{id}/export` - Export as text

---

## 🔄 Implementation Workflow

### Step 1: Setup Database
```bash
php artisan migrate
```

### Step 2: Register Routes
Edit `routes/api.php` and include the payroll routes:
```php
// Tambahkan di file routes/api.php
require __DIR__ . '/../routes/api_payroll.php';
```

Or copy-paste route definitions into existing routes/api.php from `API_ROUTES_PAYROLL.php` file.

### Step 3: Setup Service Binding (optional, if using dependency injection)
Edit `app/Providers/AppServiceProvider.php`:
```php
public function register()
{
    $this->app->singleton(PayrollCalculationService::class, function ($app) {
        return new PayrollCalculationService();
    });
    
    $this->app->bind(PayrollGenerationService::class, function ($app) {
        return new PayrollGenerationService(
            $app->make(PayrollCalculationService::class)
        );
    });
}
```

### Step 4: Verify Models & Controllers
- Models: All models in `app/Models/` ready
- Controllers: All controllers in `app/Http/Controllers/Api/` ready
- Services: All services in `app/Services/` ready

---

## 📝 Usage Examples

### Scenario 1: HO membuat Absence

```bash
curl -X POST http://localhost:8000/api/absences \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "project_id": 1,
    "user_id": 5,
    "schedule_id": 120,
    "assignment_id": 3,
    "date": "2026-02-10",
    "absence_type": "SAKIT",
    "attachment_url": "https://drive.google.com/..."
  }'
```

**Response:**
```json
{
  "success": true,
  "message": "Absence created successfully",
  "data": {
    "id": 1,
    "user_id": 5,
    "date": "2026-02-10",
    "absence_type": "SAKIT",
    "status": "PENDING",
    "created_at": "2026-02-10T10:00:00Z"
  }
}
```

### Scenario 2: Approve Overtime

```bash
curl -X PATCH http://localhost:8000/api/overtime-logs/1/approve \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "approved_by": 1
  }'
```

### Scenario 3: Generate Payroll untuk Bulan Februari

```bash
# 1. Create payroll run
curl -X POST http://localhost:8000/api/payroll-runs \
  -H "Authorization: Bearer TOKEN" \
  -d '{
    "project_id": 1,
    "payroll_policy_id": 1,
    "year": 2026,
    "month": 2,
    "pay_period_start": "2026-02-01",
    "pay_period_end": "2026-02-28"
  }'
# Returns: payroll_run_id = 1

# 2. Calculate details
curl -X GET http://localhost:8000/api/payroll-runs/1/calculate \
  -H "Authorization: Bearer TOKEN"
# Generates payroll_details untuk semua user di periode

# 3. Finalize
curl -X PATCH http://localhost:8000/api/payroll-runs/1/finalize \
  -H "Authorization: Bearer TOKEN" \
  -d '{"finalized_by": 1}'

# 4. Mark paid
curl -X PATCH http://localhost:8000/api/payroll-runs/1/mark-paid \
  -H "Authorization: Bearer TOKEN" \
  -d '{"paid_date": "2026-03-05"}'
```

### Scenario 4: Get Payroll Detail untuk Employee

```bash
curl -X GET http://localhost:8000/api/payroll-details/101 \
  -H "Authorization: Bearer TOKEN"
```

**Response includes:**
```json
{
  "success": true,
  "data": {
    "payroll_detail": {
      "id": 101,
      "user_id": 5,
      "user_name": "Budi Santoso",
      "base_salary": 2000000,
      "total_deductions": 45000,
      "total_additions": 500000,
      "net_salary": 2455000
    },
    "summary": {
      "base_salary": 2000000,
      "total_deductions": 45000,
      "total_additions": 500000,
      "net_salary": 2455000,
      "attendance_rate": "95%"
    },
    "deduction_breakdown": {
      "late": 45000,
      "absence": 0,
      "alpha": 0
    },
    "addition_breakdown": {
      "overtime": 200000,
      "allowance": 100000,
      "bonus": 200000
    },
    "daily_breakdown": [...]  // Each day with status
  }
}
```

---

## 🔒 Authorization & Policies

### Recommended Role-based Access:

```php
// HO/Admin only
- POST /api/absences (create)
- PATCH /api/absences/{id}/approve
- PATCH /api/absences/{id}/reject
- POST /api/overtime-logs
- PATCH /api/overtime-logs/{id}/approve
- POST /api/payroll-policies
- POST /api/payroll-runs
- GET /api/payroll-runs/{id}/calculate
- PATCH /api/payroll-runs/{id}/finalize
- PATCH /api/payroll-runs/{id}/mark-paid

// User (own data only)
- GET /api/payroll-details?user_id={own_id}
- GET /api/payroll-details/{own_detail_id}

// Security/Employee
- GET /api/absences (view status)
- GET /api/overtime-logs (view status)
```

To implement, create `app/Policies/PayrollPolicy.php`:
```php
<?php

namespace App\Policies;

use App\Models\User;
use App\Models\PayrollDetail;

class PayrollDetailPolicy
{
    public function view(User $user, PayrollDetail $payrollDetail)
    {
        return $user->id === $payrollDetail->user_id || $user->isHO();
    }
}
```

---

## 🧪 Testing

### Unit Tests for Payroll Calculation

```php
// tests/Unit/PayrollCalculationServiceTest.php

public function test_calculate_no_late_no_absence()
{
    $service = new PayrollCalculationService();
    $result = $service->calculateUserPayroll(
        userId: 5,
        projectId: 1,
        periodStart: Carbon::parse('2026-02-01'),
        periodEnd: Carbon::parse('2026-02-28'),
        policy: $this->policy
    );
    
    // Should get perfect attendance bonus
    $this->assertEquals(300000, $result['additions']['bonus']);
}

public function test_calculate_with_late()
{
    // ... test late deduction logic
}

public function test_calculate_with_overtime()
{
    // ... test overtime addition logic
}
```

---

## 📊 Payroll Calculation Examples

### Example 1: Perfect Attendance
- Working days: 20
- Daily rate: Rp 150,000
- Base: 3,000,000
- No late, no absence, no alpha
- Bonus: 300,000
- **Net: 3,300,000**

### Example 2: With Late + Overtime
- Working days: 20
- Base: 3,000,000
- Deduction late (30 min × 1500): (45,000)
- Addition overtime (6 jam × 18,750 × 150%): +168,750
- Addition bonus (0 late, 0 alpha): +300,000
- **Net: 3,423,750**

### Example 3: With Absence + Alpha
- Working days: 20
- Attended: 17 days → Base: 2,550,000
- Absence (1 × 100,000): (100,000)
- Alpha (1 × 150,000): (150,000)
- No bonus (ada alpha)
- **Net: 2,300,000**

---

## ⚙️ Configuration & Customization

### Modifying Payroll Policy

To change pricing globally, create new policy:
```php
PayrollPolicy::create([
    'project_id' => 1,
    'policy_code' => 'SPECIAL_RATE_2026_Q2',
    'policy_name' => 'Special Rate Q2 2026',
    'effective_from' => '2026-04-01',
    'effective_to' => '2026-06-30',
    'daily_rate' => 200000,  // Increased
    'hourly_rate' => 25000,
    'late_deduction_per_minute' => 2000,  // Stricter
    'absence_deduction_amount' => 150000,
    'alpha_deduction_amount' => 200000,
    'overtime_rate_percent' => 175,  // Higher OT rate
    'perfect_attendance_bonus' => 500000,
    'status' => 'ACTIVE'
]);
```

Existing payroll won't be affected - they use the policy at time of creation.

---

## 🐛 Troubleshooting

### Issue: "Payroll sudah ada untuk periode ini"
**Solution:** Delete previous run or change period

### Issue: "Belum ada payroll details. Generate dulu."
**Solution:** Hit `GET /payroll-runs/{id}/calculate` endpoint first

### Issue: Payroll details tidak updated saat recalculate
**Solution:** Ensure no attendance/absence updates after calculate. If needed:
1. Update attendance/absence
2. Hit recalculate endpoint
3. Check new results

### Issue: Employee tidak muncul di payroll
**Solution:** Verify:
- Ada schedule untuk employee di periode
- Schedule ada assignment_id valid
- Check logs untuk error

---

## 📚 Additional Resources

- **Design Document:** `ATTENDANCE_PAYROLL_SYSTEM.md`
- **Postman Collection:** `POSTMAN_COLLECTION.json`
- **Routes File:** `API_ROUTES_PAYROLL.php`

---

## ✅ Checklist untuk Production

- [ ] Database migrations run successfully
- [ ] Models created dan relationships tested
- [ ] Controllers created dengan validation
- [ ] Services created dengan business logic
- [ ] Routes registered
- [ ] Authorization policies implemented
- [ ] Tests written & passing
- [ ] API documentation updated
- [ ] Error handling implemented
- [ ] Logging configured
- [ ] Backup strategy planned

---

## 📞 Support

Pertanyaan atau masalah implementasi:
1. Check design document untuk business rule
2. Check controller untuk endpoint spec
3. Check service untuk calculation logic
4. Review test cases untuk expected behavior

---

**Last Updated:** February 10, 2026
**Version:** 1.0.0
