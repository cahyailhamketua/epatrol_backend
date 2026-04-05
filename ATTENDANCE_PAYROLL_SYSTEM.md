# Sistem Attendance, Absence & Payroll - Design Document

## 1. DATABASE SCHEMA

### 1.1 NEW TABLES

#### **Table: absences**
**Purpose**: Mencatat saat employee tidak masuk (sakit, izin, cuti)

```sql
CREATE TABLE absences (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    project_id BIGINT NOT NULL,
    user_id BIGINT NOT NULL,
    schedule_id BIGINT NOT NULL, -- yang dijadwalkan
    assignment_id BIGINT NOT NULL,
    date DATE NOT NULL,
    
    -- Tipe absence
    absence_type ENUM('SAKIT', 'IZIN', 'CUTI') NOT NULL,
    
    -- Support dokumen
    attachment_url VARCHAR(255) NULL, -- untuk dokumen sakit/izin
    
    -- Status approval
    status ENUM('PENDING', 'APPROVED', 'REJECTED') DEFAULT 'PENDING',
    approved_by BIGINT NULL, -- user yang approve (HO/Admin)
    approved_at TIMESTAMP NULL,
    rejection_reason TEXT NULL,
    
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    
    FOREIGN KEY (project_id) REFERENCES projects(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (schedule_id) REFERENCES schedules(id),
    FOREIGN KEY (assignment_id) REFERENCES assignments(id),
    FOREIGN KEY (approved_by) REFERENCES users(id)
);

-- Unique: Hanya 1 absence atau attendance per hari per user
ALTER TABLE absences ADD UNIQUE KEY unique_user_date 
    (project_id, user_id, date);
```

**Relasi Model**:
```php
- belongsTo(Project)
- belongsTo(User)
- belongsTo(Schedule)
- belongsTo(Assignment)
- belongsTo(User, 'approved_by')
```

**Alasan**: 
- Tracking penggantian schedule yang tidak hadir
- Differensiasi antara sakit/izin/cuti untuk calculation berbeda
- Approval flow untuk absence yang perlu validasi
- Dokumen support klaim sakit

---

#### **Table: overtime_logs**
**Purpose**: Mencatat semua overtime baik planned maupun actual

```sql
CREATE TABLE overtime_logs (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    project_id BIGINT NOT NULL,
    user_id BIGINT NOT NULL,
    assignment_id BIGINT NOT NULL, -- shift yang di-overtime
    schedule_id BIGINT NOT NULL -- schedule hari itu
    date DATE NOT NULL,
    
    -- Overtime type
    overtime_type ENUM('OFF_DUTY', 'EXTEND_SHIFT') DEFAULT 'EXTEND_SHIFT',
    -- OFF_DUTY = assignment O (lembur penuh hari)
    -- EXTEND_SHIFT = melampaui end_time
    
    -- Waktu expected/planned
    planned_start_time TIME NOT NULL,
    planned_end_time TIME NOT NULL,
    planned_minutes INT NOT NULL, -- hitung dari start-end
    
    -- Waktu actual (dari attendance)
    actual_start_time TIME NULL, -- dari check_in_at
    actual_end_time TIME NULL,   -- dari check_out_at
    actual_minutes INT NULL,
    
    -- Status & approval
    status ENUM('PENDING', 'APPROVED', 'REJECTED', 'COMPLETED') DEFAULT 'PENDING',
    approved_by BIGINT NULL,
    approved_at TIMESTAMP NULL,
    
    notes TEXT NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    
    FOREIGN KEY (project_id) REFERENCES projects(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (assignment_id) REFERENCES assignments(id),
    FOREIGN KEY (schedule_id) REFERENCES schedules(id),
    FOREIGN KEY (approved_by) REFERENCES users(id)
);
```

**Relasi Model**:
```php
- belongsTo(Project)
- belongsTo(User)
- belongsTo(Assignment)
- belongsTo(Schedule)
- belongsTo(User, 'approved_by')
```

**Alasan**:
- Pisah antara planned vs actual overtime
- Track overtime requests yang perlu approval
- Foundation untuk payroll overtime calculation
- Audit trail untuk lembur

---

#### **Table: payroll_policies**
**Purpose**: Master data untuk semua rule finansial (configurable, NOT hardcoded)

```sql
CREATE TABLE payroll_policies (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    project_id BIGINT NOT NULL,
    
    -- Policy identifier
    policy_code VARCHAR(50) UNIQUE NOT NULL, -- eg: "BASE_2025_Q1"
    policy_name VARCHAR(100) NOT NULL,
    description TEXT,
    
    -- Periode berlaku
    effective_from DATE NOT NULL,
    effective_to DATE NULL, -- NULL = sampai diganti
    
    -- DASAR GAJI
    daily_rate DECIMAL(10,2) NULL, -- gaji per hari
    hourly_rate DECIMAL(10,2) NULL, -- gaji per jam (untuk O assignment)
    
    -- POTONGAN
    late_deduction_per_minute DECIMAL(10,4) NOT NULL, -- potongan/menit telat
    late_minimum_minutes INT DEFAULT 5, -- mulai potong setelah X menit
    absence_deduction_amount DECIMAL(10,2) NOT NULL, -- potongan 1x absence
    alpha_deduction_amount DECIMAL(10,2) NOT NULL, -- potongan alpha (tidak ada absence, tidak masuk)
    
    -- TAMBAHAN (LEMBUR)
    overtime_rate_percent DECIMAL(5,2) DEFAULT 150, -- 150% dari hourly
    -- atau gunakan overtime_rate_amount untuk fixed per jam
    overtime_rate_amount DECIMAL(10,2) NULL, -- gaji lembur per jam (jika fixed)
    
    -- TUNJANGAN (OPTIONAL)
    daily_allowance DECIMAL(10,2) DEFAULT 0, -- tunjangan harian
    shift_allowance_amount DECIMAL(10,2) DEFAULT 0, -- tunjangan shift tertentu
    
    -- BONUS (OPTIONAL)
    perfect_attendance_bonus DECIMAL(10,2) DEFAULT 0, -- bonus jika 0 telat, 0 alpha
    
    status ENUM('ACTIVE', 'INACTIVE') DEFAULT 'ACTIVE',
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    
    FOREIGN KEY (project_id) REFERENCES projects(id)
);
```

**Relasi Model**:
```php
- belongsTo(Project)
- hasMany(PayrollRun)
```

**Alasan**:
- ALL finansial rules configurable per project/periode
- Mudah ganti policy tanpa code change
- Audit trail versi policy
- Support multiple rate types (daily, hourly, percent)

---

#### **Table: payroll_runs**
**Purpose**: Periode payroll bulanan (header)

```sql
CREATE TABLE payroll_runs (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    project_id BIGINT NOT NULL,
    payroll_policy_id BIGINT NOT NULL,
    
    -- Periode
    year INT NOT NULL,
    month INT NOT NULL,
    pay_period_start DATE NOT NULL,
    pay_period_end DATE NOT NULL,
    
    -- Status
    status ENUM('DRAFT', 'FINALIZED', 'PAID', 'CANCELLED') DEFAULT 'DRAFT',
    finalized_by BIGINT NULL,
    finalized_at TIMESTAMP NULL,
    paid_at TIMESTAMP NULL,
    
    -- Summary (cache untuk performa)
    total_employees INT NULL,
    total_payroll_amount DECIMAL(12,2) NULL,
    total_deductions DECIMAL(12,2) NULL,
    total_additions DECIMAL(12,2) NULL,
    
    notes TEXT NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    
    FOREIGN KEY (project_id) REFERENCES projects(id),
    FOREIGN KEY (payroll_policy_id) REFERENCES payroll_policies(id),
    FOREIGN KEY (finalized_by) REFERENCES users(id),
    
    UNIQUE KEY unique_project_period (project_id, year, month)
);
```

**Relasi Model**:
```php
- belongsTo(Project)
- belongsTo(PayrollPolicy)
- belongsTo(User, 'finalized_by')
- hasMany(PayrollDetail)
```

**Alasan**:
- Logical grouping untuk 1 periode gaji
- Track status approval/finalization
- Cache summary untuk performance
- Prevent multiple payroll per periode

---

#### **Table: payroll_details**
**Purpose**: Detail gaji per employee per payroll run

```sql
CREATE TABLE payroll_details (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    payroll_run_id BIGINT NOT NULL,
    project_id BIGINT NOT NULL,
    user_id BIGINT NOT NULL,
    assignment_id BIGINT NOT NULL, -- assignment yang ditugaskan
    
    -- DASAR GAJI
    working_days INT NOT NULL, -- hari actual kerja (attendance)
    worked_hours INT NOT NULL, -- jam actual kerja (dari check_in - check_out)
    base_salary DECIMAL(12,2) NOT NULL, -- hitung dari daily_rate * working_days
    
    -- ATTENDANCE DETAILS (untuk audit)
    attendance_count INT NOT NULL DEFAULT 0,
    late_count INT NOT NULL DEFAULT 0,
    late_total_minutes INT NOT NULL DEFAULT 0,
    
    -- ABSENCE DETAILS
    absence_count INT NOT NULL DEFAULT 0, -- absence yang approved
    absence_type_sakit INT DEFAULT 0,
    absence_type_izin INT DEFAULT 0,
    absence_type_cuti INT DEFAULT 0,
    
    -- ALPHA DETAILS (tidak masuk, tidak ada absence)
    alpha_count INT NOT NULL DEFAULT 0,
    
    -- OVERTIME
    overtime_count INT NOT NULL DEFAULT 0,
    overtime_total_hours INT NOT NULL DEFAULT 0,
    
    -- POTONGAN
    deduction_late DECIMAL(12,2) DEFAULT 0, -- deduction dari late
    deduction_absence DECIMAL(12,2) DEFAULT 0, -- deduction SAKIT + IZIN
    deduction_cuti DECIMAL(12,2) DEFAULT 0, -- cuti (paid atau unpaid?)
    deduction_alpha DECIMAL(12,2) DEFAULT 0, -- alpha (no absence record)
    deduction_other DECIMAL(12,2) DEFAULT 0, -- potongan lain (custom)
    total_deductions DECIMAL(12,2) DEFAULT 0, -- sum semua deduction
    
    -- TAMBAHAN
    addition_overtime DECIMAL(12,2) DEFAULT 0, -- bayar lembur
    addition_allowance DECIMAL(12,2) DEFAULT 0, -- tunjangan
    addition_bonus DECIMAL(12,2) DEFAULT 0, -- bonus
    addition_other DECIMAL(12,2) DEFAULT 0, -- tambahan lain (custom)
    total_additions DECIMAL(12,2) DEFAULT 0, -- sum semua addition
    
    -- FINAL
    net_salary DECIMAL(12,2) NOT NULL, -- base + addition - deduction
    
    -- NOTES (untuk transparency)
    notes TEXT NULL, -- breakdown notes untuk employee
    payment_method VARCHAR(50) NULL, -- TRANSFER, CASH, etc
    payment_date DATE NULL,
    
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    
    FOREIGN KEY (payroll_run_id) REFERENCES payroll_runs(id),
    FOREIGN KEY (project_id) REFERENCES projects(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (assignment_id) REFERENCES assignments(id),
    
    UNIQUE KEY unique_payroll_user (payroll_run_id, user_id)
);
```

**Relasi Model**:
```php
- belongsTo(PayrollRun)
- belongsTo(Project)
- belongsTo(User)
- belongsTo(Assignment)
- hasMany(PayrollDetailLine) -- optional, untuk itemize setiap hari
```

**Alasan**:
- Satu record = satu employee di satu periode gaji
- Detail lengkap untuk audit & transparency
- Bisa lihat breakdown deduction & addition
- Payment tracking

---

### 1.2 MODIFIED EXISTING TABLES

#### **Attendance Table - ADD columns**

```sql
ALTER TABLE attendances ADD COLUMN overtime_minutes INT DEFAULT 0;
-- Ini untuk track lembur actual dari jam kerja

ALTER TABLE attendances ADD COLUMN overtime_status ENUM('NONE', 'PENDING', 'APPROVED') DEFAULT 'NONE';
-- NONE = tidak ada lembur
-- PENDING = ada lembur, menunggu approval
-- APPROVED = lembur di-approve, bisa dihitung di payroll
```

**Alasan**:
- Dari attendance→payroll logic, harus tahu overtime yang sudah di-approve
- Minimal field, tidak mengubah struktur utama

---

#### **Assignment Table - ADD columns untuk O (OFF) assignment**

```sql
ALTER TABLE assignments ADD COLUMN is_off BOOLEAN DEFAULT 0;
-- TRUE jika assignment = O (off)

-- Kolom ini ada di SCHEDULE, bukan assignment:
-- Karena setiap hari assignment O bisa punya waktu berbeda jika dipanggil masuk
```

**Alasan**:
- Query lebih mudah filter assignment O
- Tidak mengubah pricing/timing, hanya flag

---

#### **Schedule Table - Tetap SAMA, tapi interpretasi:**

Jika assignment code = 'O':
- Maknanya: OFF duty, tapi bisa dipanggil masuk
- Jika ada attendance di hari itu → semua jam dihitung overtime
- Grace period tetap berlaku

Jika ada overtime di hari dengan assignment P/M/SOC:
- Dicatat di overtime_logs
- Dihitung di payroll sebagai addition

---

## 2. BUSINESS LOGIC FLOW

### 2.1 Check-In Flow (Security App)

```
┌─────────────────────────────────────────────────────────┐
│                   SECURITY WANTS TO CHECK-IN             │
└─────────────────────────────────────────────────────────┘
                          ↓
┌─────────────────────────────────────────────────────────┐
│  1. VALIDATE: Ada ABSENCE yang approved hari ini?       │
│     - Cek:  SELECT * FROM absences                    │
│       WHERE user_id = X AND date = TODAY AND          │
│       status = 'APPROVED'                              │
└─────────────────────────────────────────────────────────┘
                          ↓
                    [ADA ABSENCE?]
                    /            \
                  YES             NO
                  ↓               ↓
        ┌──────────────┐   ┌──────────────────┐
        │ REJECT       │   │ LANJUT KE STEP 2 │
        │ "User sudah  │   └──────────────────┘
        │ absence      │                ↓
        │ hari ini"    │   ┌──────────────────────────────┐
        └──────────────┘   │ 2. CARI SCHEDULE HARI INI    │
                           │    SELECT * FROM schedules │
                           │    WHERE user_id = X AND    │
                           │    date = TODAY             │
                           └──────────────────────────────┘
                                      ↓
                          [SCHEDULE FOUND?]
                          /              \
                        YES               NO
                        ↓                 ↓
            ┌──────────────────────┐  ┌────────────────────┐
            │ STEP 3a: NORMAL      │  │ STEP 3b: OFF-DUTY  │
            │ CHECK-IN             │  │ (Assignment = O)   │
            │ (P, M, SOC assgnm)   │  │ Check-in as OT?    │
            └──────────────────────┘  └────────────────────┘
                      ↓                         ↓
         ┌──────────────────────────┐ ┌─────────────────────────┐
         │ 3a: VALIDATE CHECKIN     │ │ 3b: VALIDATE OT CHECKIN │
         │ - Lokasi (radius)        │ │ - Sudah approve OT?     │
         │ - Waktu vs start_time    │ │ - Create overtime_log   │
         │ - Hitung late_minutes    │ │ - Hitung late dari OT   │
         │ - Grace period?          │ │   start_time            │
         └──────────────────────────┘ └─────────────────────────┘
                      ↓                         ↓
         ┌──────────────────────────┐ ┌─────────────────────────┐
         │ 4: CREATE Attendance     │ │ 4: CREATE Attendance    │
         │ - attendance_status:     │ │ - Same as 3a but        │
         │   HADIR atau HADIR TELAT │ │ - Attach overtime_log   │
         │ - late_minutes: X        │ │ - Mark overtime_status: │
         │ - check_in_at: NOW       │ │   PENDING               │
         │ - Loc: save lat/lng      │ └─────────────────────────┘
         └──────────────────────────┘
                      ↓
         ┌──────────────────────────┐
         │ 5: RETURN TO APP         │
         │ - ✓ Check-in success     │
         │ - Show actual time diff  │
         │ - Show late status       │
         └──────────────────────────┘
```

**Key Rules**:
1. Jika ada APPROVED absence → REJECT check-in
2. Jika ada schedule + assignment = O → dihitung sebagai overtime
3. Grace period applied, hitung late_minutes
4. Overtime_status = PENDING sampai HO approve

---

### 2.2 Absence vs Attendance Conflict

```
RULE: Satu hari, user hanya boleh punya SALAH SATU:
      - Attendance (1 record)
      - ATAU Absence (1 record)
      - ATAU Alpha (tidak ada keduanya)

ENFORCEMENT:
┌──────────────────────────────────────────────────────┐
│ BEFORE INSERT Attendance:                            │
│ SELECT COUNT(*) FROM absences                       │
│ WHERE user_id = X AND date = TODAY                   │
│   AND status = 'APPROVED'                            │
│ IF > 0 → ERROR "User punya absence hari ini"        │
└──────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────┐
│ BEFORE INSERT Absence:                               │
│ SELECT COUNT(*) FROM attendances                    │
│ WHERE user_id = X AND date = TODAY                   │
│ IF > 0 → ERROR "User sudah check-in hari ini"       │
└──────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────┐
│ ALPHA Detection (dalam payroll calculation):         │
│ Untuk setiap hari dalam periode:                    │
│   IF NOT EXISTS(attendance) AND                      │
│      NOT EXISTS(absence approved)                    │
│   THEN → ALPHA (potongan penuh)                      │
└──────────────────────────────────────────────────────┘
```

---

### 2.3 Late + Overtime Same Day

```
Skenario: Assignment P (09:00-17:00), user check-in pukul 16:00, 
check-out 20:00

┌─────────────────────────────────────────────────────┐
│ Step 1: Hitung LATE                                │
│ - start_time = 09:00                               │
│ - check_in_at = 16:00 (salah, contoh saja)         │
│ - late_minutes = (16:00 - 09:00) = 420 menit TELAT │
│ - Status HADIR TELAT                               │
└─────────────────────────────────────────────────────┘
                      ↓
┌─────────────────────────────────────────────────────┐
│ Step 2: Hitung OVERTIME                            │
│ - end_time = 17:00                                │
│ - check_out_at = 20:00                            │
│ - overtime_minutes = 20:00 - 17:00 = 180 menit    │
│ - Create overtime_log(planned vs actual)          │
│ - Status PENDING (tunggu approval)                │
└─────────────────────────────────────────────────────┘
                      ↓
┌─────────────────────────────────────────────────────┐
│ Step 3: Payroll Calculation                        │
│ - Base salary = daily_rate (standar, tidak dikurangi│
│   meski telat, tapi ada POTONGAN)                  │
│ - late_deduction = late_minutes × policy_rate     │
│ - IF overtime APPROVED:                            │
│     addition_overtime = overtime_minutes × rate    │
│ - net = base + overtime_addition - late_deduction  │
└─────────────────────────────────────────────────────┘

IMPORTANT: Telat & Lembur INDEPENDENT!
- Telat = potongan gaji
- Lembur = tambahan gaji
- Bisa terjadi bersamaan
```

---

### 2.4 Assignment O (OFF) Overtime

```
Scenario: User dijadwalkan OFF (assignment O), tapi dipanggil masuk

┌──────────────────────────────────────────────────────┐
│ Schedule:                                            │
│ - assignment_id = O (no start_time/end_time)        │
│ - Status = OFF duty                                 │
└──────────────────────────────────────────────────────┘
                      ↓
┌──────────────────────────────────────────────────────┐
│ HO membuat Overtime Request:                         │
│ POST /api/overtime-logs/create                      │
│ {                                                   │
│   "schedule_id": X,                                 │
│   "overtime_type": "OFF_DUTY",                      │
│   "planned_start_time": "08:00",                    │
│   "planned_end_time": "17:00",                      │
│   "status": "APPROVED"  ← HO langsung approve       │
│ }                                                   │
└──────────────────────────────────────────────────────┘
                      ↓
┌──────────────────────────────────────────────────────┐
│ Security check-in:                                   │
│ - Lihat planned_start_time dari overtime_log       │
│ - Check-in at 08:10 (10 menit telat)              │
│ - late_minutes = 10                                │
│ - Status: HADIR TELAT (dari OT)                    │
│ - Create Attendance + mark overtime_status:        │
│   APPROVED (sudah di-approve sebelum check-in)     │
└──────────────────────────────────────────────────────┘
                      ↓
┌──────────────────────────────────────────────────────┐
│ Payroll:                                             │
│ - Seluruh jam (08:00-17:00) = OVERTIME PAY          │
│ - Bandingkan dengan hourly_rate                     │
│ - Hitung late dari 08:00 (start OT), tidak dari    │
│   jam normal                                        │
│ - addition_overtime = 9 jam × hourly_rate × 150%   │
│ - deduction_late = 10 menit × policy_rate          │
└──────────────────────────────────────────────────────┘
```

---

## 3. API ENDPOINTS

### 3.1 Absence Management (HO Admin)

#### **POST /api/absences**
Create new absence for an employee

```php
// Request
{
    "project_id": 1,
    "user_id": 5,
    "schedule_id": 120,  // yang dijadwalkan
    "assignment_id": 3,
    "date": "2026-02-10",
    "absence_type": "SAKIT",  // SAKIT | IZIN | CUTI
    "attachment_url": "https://...",  // optional sertifikat
    "notes": "Demam tinggi"
}

// Response 201
{
    "id": 1,
    "project_id": 1,
    "user_id": 5,
    "date": "2026-02-10",
    "absence_type": "SAKIT",
    "status": "PENDING",
    "created_at": "2026-02-10T10:00:00Z"
}
```

#### **GET /api/absences?project_id=1&date=2026-02-10&user_id=5**
Get absence list (filter by date/user/status)

```php
// Response
[
    {
        "id": 1,
        "user_id": 5,
        "user_name": "Budi",
        "date": "2026-02-10",
        "absence_type": "SAKIT",
        "status": "PENDING",
        "attachment_url": "https://...",
        "created_at": "2026-02-10T10:00:00Z"
    }
]
```

#### **PATCH /api/absences/{id}/approve**
Approve absence (HO only)

```php
// Request
{
    "approved_by": 1,  // User ID of HO
    "notes": "Approved, perlu surat dokter"
}

// Response
{
    "id": 1,
    "status": "APPROVED",
    "approved_by": 1,
    "approved_at": "2026-02-10T10:30:00Z"
}
```

#### **PATCH /api/absences/{id}/reject**
Reject absence dengan alasan

```php
// Request
{
    "rejection_reason": "Belum ada surat dokter"
}

// Response
{
    "id": 1,
    "status": "REJECTED",
    "rejection_reason": "Belum ada surat dokter"
}
```

---

### 3.2 Overtime Management (HO Admin)

#### **POST /api/overtime-logs**
Create overtime request (HO bisa direct approve)

```php
// Request
{
    "project_id": 1,
    "user_id": 5,
    "schedule_id": 120,
    "assignment_id": 3,
    "date": "2026-02-10",
    "overtime_type": "OFF_DUTY",  // OFF_DUTY | EXTEND_SHIFT
    "planned_start_time": "08:00",
    "planned_end_time": "17:00",
    "status": "APPROVED",  // HO bisa langsung approve
    "notes": "Dipanggil masuk emergency"
}

// Response 201
{
    "id": 1,
    "user_id": 5,
    "date": "2026-02-10",
    "overtime_type": "OFF_DUTY",
    "planned_start_time": "08:00",
    "planned_end_time": "17:00",
    "planned_minutes": 540,
    "status": "APPROVED",
    "approved_at": "2026-02-10T07:00:00Z"
}
```

#### **PATCH /api/overtime-logs/{id}/approve**
Approve pending overtime (jika sudah ada attendance)

```php
// Request
{
    "approved_by": 1
}

// Response
{
    "id": 1,
    "status": "APPROVED",
    "actual_start_time": "08:10",
    "actual_end_time": "17:05",
    "actual_minutes": 535
}
```

#### **GET /api/overtime-logs?project_id=1&month=2&year=2026**
Get overtime logs untuk payroll reference

```php
// Response
[
    {
        "id": 1,
        "user_id": 5,
        "date": "2026-02-10",
        "overtime_type": "OFF_DUTY",
        "planned_minutes": 540,
        "actual_minutes": 535,
        "status": "APPROVED"
    }
]
```

---

### 3.3 Payroll Policy Management

#### **POST /api/payroll-policies**
Create new payroll policy

```php
// Request
{
    "project_id": 1,
    "policy_code": "BASE_2025_Q1",
    "policy_name": "Kebijakan Gaji Q1 2025",
    "effective_from": "2025-01-01",
    "effective_to": "2025-03-31",
    "daily_rate": 100000,  // gaji harian
    "hourly_rate": 12500,  // untuk O assignment
    "late_deduction_per_minute": 1000,
    "late_minimum_minutes": 5,
    "absence_deduction_amount": 75000,  // SAKIT + IZIN
    "alpha_deduction_amount": 100000,   // tidak masuk tanpa absence
    "overtime_rate_percent": 150,       // 150% × hourly
    "daily_allowance": 20000,
    "perfect_attendance_bonus": 200000
}

// Response 201
{
    "id": 1,
    "policy_code": "BASE_2025_Q1",
    "status": "ACTIVE",
    "created_at": "2025-12-15T00:00:00Z"
}
```

#### **GET /api/payroll-policies?project_id=1**
List active policies

```php
// Response
[
    {
        "id": 1,
        "policy_code": "BASE_2025_Q1",
        "policy_name": "Kebijakan Gaji Q1 2025",
        "daily_rate": 100000,
        "hourly_rate": 12500,
        "status": "ACTIVE",
        "effective_from": "2025-01-01",
        "effective_to": "2025-03-31"
    }
]
```

---

### 3.4 Payroll Run (Monthly Payroll)

#### **POST /api/payroll-runs**
Initiate payroll run untuk bulan tertentu

```php
// Request
{
    "project_id": 1,
    "payroll_policy_id": 1,
    "year": 2026,
    "month": 2,
    "pay_period_start": "2026-02-01",
    "pay_period_end": "2026-02-28",
    "notes": "Payroll Februari 2026"
}

// Response 201
{
    "id": 1,
    "project_id": 1,
    "year": 2026,
    "month": 2,
    "status": "DRAFT",
    "pay_period_start": "2026-02-01",
    "pay_period_end": "2026-02-28"
}
```

#### **GET /api/payroll-runs/{id}/calculate**
Calculate payroll details untuk semua users

Endpoint ini:
1. Ambil semua schedules dalam periode
2. Grouping by user dan assignment
3. Count attendance, late, absence, alpha, overtime
4. Calculate gaji berdasarkan policy
5. Populate payroll_details

```php
// Response
{
    "payroll_run_id": 1,
    "status": "DRAFT",
    "total_employees": 15,
    "total_payroll_amount": 1500000,
    "details": [
        {
            "user_id": 5,
            "user_name": "Budi",
            "working_days": 20,
            "base_salary": 2000000,
            "deduction_late": 30000,
            "deduction_absence": 75000,
            "deduction_alpha": 0,
            "addition_overtime": 150000,
            "addition_bonus": 200000,
            "net_salary": 2245000
        }
    ]
}
```

#### **PATCH /api/payroll-runs/{id}/finalize**
Finalize/lock payroll (Mark as FINALIZED, ready to pay)

```php
// Request
{
    "finalized_by": 1,
    "notes": "Final approval dari Direktur"
}

// Response
{
    "id": 1,
    "status": "FINALIZED",
    "finalized_by": 1,
    "finalized_at": "2026-03-03T15:00:00Z",
    "total_payroll_amount": 1500000
}
```

#### **PATCH /api/payroll-runs/{id}/mark-paid**
Mark payroll as PAID

```php
// Request
{
    "paid_at": "2026-03-05",
    "notes": "Sudah transfer ke semua rekening"
}

// Response
{
    "id": 1,
    "status": "PAID",
    "paid_at": "2026-03-05"
}
```

---

### 3.5 Payroll Details (Per User)

#### **GET /api/payroll-details?payroll_run_id=1**
Get semua payroll details untuk 1 periode

```php
// Response
[
    {
        "id": 101,
        "payroll_run_id": 1,
        "user_id": 5,
        "user_name": "Budi",
        "assignment_id": 3,
        "assignment_name": "Shift Malam",
        "working_days": 20,
        "worked_hours": 160,
        "base_salary": 2000000,
        "attendance_count": 20,
        "late_count": 2,
        "late_total_minutes": 30,
        "absence_count": 0,
        "alpha_count": 0,
        "overtime_count": 2,
        "overtime_total_hours": 6,
        "deduction_late": 30000,
        "deduction_absence": 0,
        "deduction_alpha": 0,
        "total_deductions": 30000,
        "addition_overtime": 150000,
        "addition_allowance": 40000,
        "addition_bonus": 200000,
        "total_additions": 390000,
        "net_salary": 2360000,
        "notes": "2x telat di minggu pertama, 2x lembur approved"
    }
]
```

#### **GET /api/payroll-details/{id}**
Get detail payroll 1 user dengan breakdown per hari (optional)

```php
// Response (dengan optional daily breakdown)
{
    "id": 101,
    "payroll_run_id": 1,
    "user_id": 5,
    "net_salary": 2360000,
    "daily_breakdown": [
        {
            "date": "2026-02-01",
            "day_name": "Sunday",
            "assignment_name": "Shift Malam",
            "attendance": {
                "status": "HADIR",
                "check_in_at": "20:05",
                "check_out_at": "04:00",
                "late_minutes": 5
            },
            "overtime": null,
            "net_day": 100000
        },
        {
            "date": "2026-02-02",
            "day_name": "Monday",
            "attendance": null,
            "absence": {
                "absence_type": "SAKIT",
                "status": "APPROVED"
            },
            "net_day": 75000  // deduction
        }
    ]
}
```

---

## 4. POSTMAN REQUEST EXAMPLES

### 4.1 Create Absence

```json
POST {{base_url}}/api/absences
Authorization: Bearer {{token}}
Content-Type: application/json

{
    "project_id": 1,
    "user_id": 5,
    "schedule_id": 120,
    "assignment_id": 3,
    "date": "2026-02-10",
    "absence_type": "SAKIT",
    "attachment_url": "https://drive.google.com/...",
    "notes": "Demam tinggi, ada surat dokter"
}

// Response
{
    "success": true,
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

### 4.2 Approve Absence

```json
PATCH {{base_url}}/api/absences/1/approve
Authorization: Bearer {{token}}
Content-Type: application/json

{
    "approved_by": 1,
    "notes": "Diterima, recovery yang baik"
}

// Response
{
    "success": true,
    "data": {
        "id": 1,
        "status": "APPROVED",
        "approved_by": 1,
        "approved_at": "2026-02-10T10:30:00Z"
    }
}
```

### 4.3 Create Overtime Request

```json
POST {{base_url}}/api/overtime-logs
Authorization: Bearer {{token}}
Content-Type: application/json

{
    "project_id": 1,
    "user_id": 5,
    "schedule_id": 120,
    "assignment_id": 3,
    "date": "2026-02-10",
    "overtime_type": "EXTEND_SHIFT",
    "planned_start_time": "17:00",
    "planned_end_time": "20:00",
    "status": "APPROVED",
    "notes": "Shift extension emergency"
}

// Response
{
    "success": true,
    "data": {
        "id": 1,
        "user_id": 5,
        "overtime_type": "EXTEND_SHIFT",
        "planned_minutes": 180,
        "status": "APPROVED",
        "approved_at": "2026-02-10T16:45:00Z"
    }
}
```

### 4.4 Create Payroll Policy

```json
POST {{base_url}}/api/payroll-policies
Authorization: Bearer {{token}}
Content-Type: application/json

{
    "project_id": 1,
    "policy_code": "SECURITY_2026_Q1",
    "policy_name": "Security Payroll Q1 2026",
    "description": "Kebijakan gaji untuk security Q1 2026",
    "effective_from": "2026-01-01",
    "effective_to": "2026-03-31",
    "daily_rate": 150000,
    "hourly_rate": 18750,
    "late_deduction_per_minute": 1500,
    "late_minimum_minutes": 5,
    "absence_deduction_amount": 100000,
    "alpha_deduction_amount": 150000,
    "overtime_rate_percent": 150,
    "daily_allowance": 25000,
    "perfect_attendance_bonus": 300000,
    "status": "ACTIVE"
}

// Response
{
    "success": true,
    "data": {
        "id": 1,
        "project_id": 1,
        "policy_code": "SECURITY_2026_Q1",
        "daily_rate": 150000,
        "status": "ACTIVE"
    }
}
```

### 4.5 Initiate Payroll Run

```json
POST {{base_url}}/api/payroll-runs
Authorization: Bearer {{token}}
Content-Type: application/json

{
    "project_id": 1,
    "payroll_policy_id": 1,
    "year": 2026,
    "month": 2,
    "pay_period_start": "2026-02-01",
    "pay_period_end": "2026-02-28",
    "notes": "Payroll Februari 2026 - Pre-Holiday"
}

// Response 201
{
    "success": true,
    "data": {
        "id": 1,
        "project_id": 1,
        "payroll_policy_id": 1,
        "year": 2026,
        "month": 2,
        "status": "DRAFT",
        "created_at": "2026-02-28T10:00:00Z"
    }
}
```

### 4.6 Calculate Payroll

```json
GET {{base_url}}/api/payroll-runs/1/calculate
Authorization: Bearer {{token}}

// Response
{
    "success": true,
    "data": {
        "payroll_run_id": 1,
        "status": "DRAFT",
        "total_employees": 15,
        "summary": {
            "total_base_salary": 2250000,
            "total_deductions": 125000,
            "total_additions": 425000,
            "total_payroll_amount": 2550000
        },
        "details_created": 15
    }
}
```

### 4.7 Get Payroll Details

```json
GET {{base_url}}/api/payroll-details?payroll_run_id=1&user_id=5
Authorization: Bearer {{token}}

// Response
{
    "success": true,
    "data": [
        {
            "id": 101,
            "payroll_run_id": 1,
            "user_id": 5,
            "user_name": "Budi Santoso",
            "assignment_name": "Shift Malam",
            "working_days": 20,
            "worked_hours": 160,
            "base_salary": 2000000,
            "deduction_late": 45000,
            "deduction_absence": 0,
            "deduction_alpha": 0,
            "total_deductions": 45000,
            "addition_overtime": 200000,
            "addition_bonus": 300000,
            "total_additions": 500000,
            "net_salary": 2455000,
            "notes": "3x telat dengan total 30 menit, 2x lembur approved"
        }
    ]
}
```

### 4.8 Finalize Payroll

```json
PATCH {{base_url}}/api/payroll-runs/1/finalize
Authorization: Bearer {{token}}
Content-Type: application/json

{
    "finalized_by": 1,
    "notes": "Final approval - siap bayar"
}

// Response
{
    "success": true,
    "data": {
        "id": 1,
        "status": "FINALIZED",
        "total_payroll_amount": 2550000,
        "finalized_by": 1,
        "finalized_at": "2026-02-28T15:30:00Z"
    }
}
```

---

## 5. SALARY RECAP EXAMPLES

### 5.1 Individual Payroll Summary

**Employee**: Budi Santoso (ID: 5)
**Period**: February 2026 (01-28)
**Assignment**: Shift Malam (M)

```
════════════════════════════════════════════════════════════
                     PAYROLL RECAP
════════════════════════════════════════════════════════════

Employee        : Budi Santoso (ID: 5)
Period          : Feb 1-28, 2026 (20 hari kerja)
Assignment      : Shift Malam
Policy          : SECURITY_2026_Q1

────────────────────────────────────────────────────────────
ATTENDANCE DETAIL
────────────────────────────────────────────────────────────
Total Hari Kerja        : 20 hari
Total Jam Kerja         : 160 jam
Attended (HADIR)        : 18 hari
Late (HADIR TELAT)      : 2 hari
Late Minutes Total      : 30 menit
  - Feb 3  : 15 menit
  - Feb 15 : 15 menit

Absence (SAKIT)         : 0 hari
Absence (IZIN)          : 0 hari
Absence (CUTI)          : 0 hari
Alpha (No Absence)      : 0 hari

Overtime Approved       : 2 hari
Overtime Total Hours    : 6 jam
  - Feb 10 (EXTEND_SHIFT) : 3 jam (17:00-20:00)
  - Feb 22 (EXTEND_SHIFT) : 3 jam (17:00-20:00)

────────────────────────────────────────────────────────────
SALARY CALCULATION
────────────────────────────────────────────────────────────

BASE SALARY
  20 hari × Rp 150.000/hari        = Rp 3.000.000

ALLOWANCES
  Daily Allowance
  20 hari × Rp 25.000/hari          = Rp   500.000
                                      ───────────────
  Total Allowances                  = Rp   500.000

ADDITIONS (LEMBUR & BONUS)
  Overtime
    6 jam × Rp 18.750/jam × 150%    = Rp   168.750
  
  Perfect Attendance Bonus
    (0 telat + 0 alpha)             = Rp   300.000  ← APPROVED
                                      ───────────────
  Total Additions                   = Rp   468.750

DEDUCTIONS
  Late Deduction
    30 menit × Rp 1.500/menit       = Rp    45.000
  
  Absence Deduction (SAKIT+IZIN)
    0 × Rp 100.000                  = Rp        0
  
  Alpha Deduction
    0 × Rp 150.000                  = Rp        0
                                      ───────────────
  Total Deductions                  = Rp    45.000

────────────────────────────────────────────────────────────
FINAL SALARY
────────────────────────────────────────────────────────────
  Base Salary                       = Rp 3.000.000
  + Total Allowances                = Rp   500.000
  + Total Additions                 = Rp   468.750
  - Total Deductions                = Rp   (45.000)
                                      ═══════════════
  NET SALARY                        = Rp 3.923.750

Payment Method        : TRANSFER
Payment Date          : 2026-03-05
Status                : FINALIZED

════════════════════════════════════════════════════════════
```

---

### 5.2 Comparative Payroll Report

**Multiple Employees in February 2026**

```
════════════════════════════════════════════════════════════
        PAYROLL REPORT - FEBRUARY 2026 (Akhir Bulan)
════════════════════════════════════════════════════════════

Project             : PT Security Guard Services
Payroll Period      : Feb 1-28, 2026
Total Employees     : 15
Total Payroll       : Rp 64.825.000

────────────────────────────────────────────────────────────
PAYROLL SUMMARY BY EMPLOYEE
────────────────────────────────────────────────────────────

# NAME              ASSIGN  BASE      DEDUCT    ADDITION  NET
──────────────────────────────────────────────────────────────
1 Budi Santoso      Malam   3000000   45000     468750    3423750
2 Ari Wijaya        Siang   3000000   60000     150000    3090000
3 Doni Permana      Pagi    3000000   0         300000    3300000
4 Siti Nurhaliza    Malam   3000000   90000     168750    3078750
5 Bambang Hidayat   Siang   3000000   0         120000    3120000
6 Cicik Pratiwi     Pagi    3000000   150000    0         2850000
7 Eka Susanto       Malam   3000000   30000     300000    3270000
8 Farida Lestari    Siang   3000000   75000     150000    3075000
9 Gun Gunawan       Pagi    3000000   0         240000    3240000
10 Hendra Kurniawan Malam   3000000   60000     180000    3120000
11 Intan Putri      Siang   3000000   45000     120000    3075000
12 Joko Sutrisno    Pagi    3000000   0         60000     3060000
13 Kartini         Malam   3000000   120000    0         2880000
14 Lidia Kusuma    Siang   3000000   30000     180000    3150000
15 Mohamad Saul    Pagi    3000000   0         210000    3210000
──────────────────────────────────────────────────────────────
   TOTAL                   45000000  705000    2620000   48915000

────────────────────────────────────────────────────────────
DEDUCTION ANALYSIS
────────────────────────────────────────────────────────────
Late Deduction          : Rp   405.000  (8 employees)
Absence Deduction       : Rp   300.000  (3 employees)
Alpha Deduction         : Rp        0  (0 employees)
                          ──────────────
Total Deductions        : Rp   705.000

────────────────────────────────────────────────────────────
ADDITION ANALYSIS
────────────────────────────────────────────────────────────
Overtime Addition       : Rp 1.200.000  (10 employees, 24 jam)
Allowance Addition      : Rp   500.000  (15 employees × Rp25k)
Perfect Attendance      : Rp   920.000  (3 employees)
                          ──────────────
Total Additions         : Rp 2.620.000

────────────────────────────────────────────────────────────
PAYROLL STATUS
────────────────────────────────────────────────────────────
Status              : FINALIZED
Finalized By        : Direktur HO (ID: 1)
Finalized Date      : 2026-02-28 15:30:00
Total to Pay        : Rp 48.915.000

Payment Method      : Bank Transfer (MANDIRI)
Payment Date        : 2026-03-05
Batch Number        : PAYROLL_2026_02_001

════════════════════════════════════════════════════════════
```

---

### 5.3 Absence & Alpha Impact Example

**Scenario: Employee dengan Absence dan Alpha**

```
Employee            : Kartini (ID: 11)
Period              : February 2026
Assignment          : Shift Malam

ATTENDANCE DETAIL
────────────────────────────────────────────────
Feb 1  - HADIR                      ✓
Feb 2  - HADIR TELAT (15 menit)     ⚠
Feb 3  - SAKIT (APPROVED)           🏥
Feb 4  - HADIR                      ✓
Feb 5  - HADIR                      ✓
Feb 6  - HADIR TELAT (30 menit)     ⚠
Feb 7  - HADIR                      ✓
Feb 8  - NO RECORD, NO ABSENCE      ✗ ALPHA
Feb 9  - HADIR                      ✓
Feb 10-28 - All HADIR              ✓ (19 hari)

SUMMARY
Total Hari Kerja    : 20 hari
Attended            : 17 hari
Late                : 2 hari (30+15 = 45 menit)
Absence (SAKIT)     : 1 hari
Alpha               : 1 hari (Feb 8)
Overtime            : 0 hari

SALARY CALCULATION
────────────────────────────────────────────────
Base Salary         : 17 × Rp150k = Rp 2.550.000
                      (hanya hari actual attend)

Deduction:
  Late (45 min × Rp1.500)           = Rp    67.500
  Absence SAKIT (1 × Rp100k)        = Rp   100.000
  Alpha (1 × Rp150k)                = Rp   150.000
                                      ──────────────
  Total Deduction                   = Rp   317.500

Allowance
  Daily (17 × Rp25k)                = Rp   425.000

Additions
  (Tidak ada lembur, tidak ada bonus karna ada alpha)
                                      ──────────────
  Total Addition                    = Rp        0

NET SALARY                          = Rp 2.657.500
                                    (2.550.000 + 425.000 - 317.500)

EXPLANATION
────────────────────────────────────────────────
- Alpha (Feb 8) → Potongan Rp150.000 (paling besar)
- Absence SAKIT → Potongan Rp100.000 (meski ada "alasan", tetap potong)
- Late 45 menit → Potongan Rp67.500
- Tidak eligible bonus attendance karna ada alpha
- Tetap dapat daily allowance

════════════════════════════════════════════════

KALKULASI JIKA TIDAK ADA ABSENCE/ALPHA
────────────────────────────────────────────────
Same employee TANPA Feb 3 (absence) & Feb 8 (alpha):

Base Salary         : 20 × Rp150k = Rp 3.000.000
Late Deduction                    = Rp    67.500
Daily Allowance     : 20 × Rp25k  = Rp   500.000
Perfect Attendance Bonus          = Rp   300.000
                                    ──────────────
NET SALARY                        = Rp 3.732.500

PERBEDAAN              = Rp 3.732.500 - Rp 2.657.500
                        = Rp 1.075.000  (27% LOSS)

Breakdown loss:
  - 1 absence: Rp100.000
  - 1 alpha: Rp150.000
  - Lost bonus: Rp300.000
  - Lost daily allowance: Rp25 × 2 = Rp50.000
  - Lost base salary: Rp150.000 × 2 = Rp300.000
                      ────────────────
  Total Loss: Rp900.000 + Rp175.000 = Rp1.075.000 ✓
```

---

## 6. MINIMAL CHANGES SUMMARY

### 6.1 Attendance Table Modification

```sql
ALTER TABLE attendances ADD COLUMN (
    overtime_minutes INT DEFAULT 0,
    overtime_status ENUM('NONE', 'PENDING', 'APPROVED') DEFAULT 'NONE'
);
```

### 6.2 Assignment Table Modification

```sql
ALTER TABLE assignments ADD COLUMN (
    is_off BOOLEAN DEFAULT 0
);

-- Update existing O assignments:
UPDATE assignments SET is_off = 1 WHERE code = 'O';
```

### 6.3 New Models Required

- `Absence` model
- `OvertimeLog` model  
- `PayrollPolicy` model
- `PayrollRun` model
- `PayrollDetail` model

### 6.4 Controllers to Extend

- `AttendanceController` - Add overtime_status validation
- `ScheduleController` - Add absence conflict check

### 6.5 New Controllers

- `AbsenceController`
- `OvertimeLogController`
- `PayrollPolicyController`
- `PayrollRunController`
- `PayrollDetailController`

### 6.6 Services to Add

- `AbsenceService` - Handle absence logic
- `OvertimeService` - Handle overtime logic
- `PayrollCalculationService` - Core gaji calculation
- `PayrollGenerationService` - Bulk generate payroll_details

---

## 7. POLICY CONFIGURATION EXAMPLES

### Policy Template 1: Standard Security

```php
[
    'policy_code' => 'STD_SECURITY_2026',
    'daily_rate' => 150000,
    'hourly_rate' => 18750,
    'late_deduction_per_minute' => 1500,
    'absence_deduction_amount' => 100000,
    'alpha_deduction_amount' => 150000,
    'overtime_rate_percent' => 150,
    'daily_allowance' => 25000,
    'perfect_attendance_bonus' => 300000,
]
```

### Policy Template 2: Premium Security

```php
[
    'policy_code' => 'PREMIUM_SECURITY_2026',
    'daily_rate' => 200000,
    'hourly_rate' => 25000,
    'late_deduction_per_minute' => 2000,  // lebih ketat
    'absence_deduction_amount' => 150000,
    'alpha_deduction_amount' => 200000,
    'overtime_rate_percent' => 175,  // lebih tinggi
    'daily_allowance' => 50000,
    'perfect_attendance_bonus' => 500000,
]
```

### Policy Template 3: Contract Worker

```php
[
    'policy_code' => 'CONTRACT_2026',
    'daily_rate' => 100000,
    'hourly_rate' => 12500,
    'late_deduction_per_minute' => 1000,  // lebih ringan
    'absence_deduction_amount' => 75000,
    'alpha_deduction_amount' => 100000,
    'overtime_rate_percent' => 120,  // lebih rendah
    'daily_allowance' => 15000,
    'perfect_attendance_bonus' => 150000,
]
```

---

## 8. IMPLEMENTATION CHECKLIST

### Phase 1: Database & Models
- [ ] Create migrations for absences, overtime_logs, payroll_policies, payroll_runs, payroll_details
- [ ] Create Absence, OvertimeLog, PayrollPolicy, PayrollRun, PayrollDetail models
- [ ] Add relations to existing models
- [ ] Add columns to Attendance & Assignment tables

### Phase 2: Services (Core Logic)
- [ ] PayrollCalculationService - single employee calculation
- [ ] PayrollGenerationService - bulk generate payroll per periode
- [ ] AbsenceService - validate & manage absence
- [ ] OvertimeService - validate & manage overtime
- [ ] AttendanceService - update check-in logic for absence/OT conflict

### Phase 3: Controllers & Endpoints
- [ ] AbsenceController (CRUD + approve/reject)
- [ ] OvertimeLogController (CRUD + approve)
- [ ] PayrollPolicyController (CRUD)
- [ ] PayrollRunController (CRUD + calculate + finalize + mark-paid)
- [ ] PayrollDetailController (GET + breakdown)

### Phase 4: Protection & Validation
- [ ] Middleware untuk HO-only endpoints
- [ ] Policy authorization (Absence, OvertimeLog dapat di-approve hanya HO)
- [ ] Conflict detection (absence vs attendance)
- [ ] Grace period validation dalam AttendanceController

### Phase 5: Testing & Documentation
- [ ] Unit tests untuk PayrollCalculationService
- [ ] Feature tests untuk payroll endpoint
- [ ] API documentation (Postman collection)
- [ ] Database schema documentation

---

## 9. INTEGRATION POINTS WITH EXISTING CODE

### AttendanceController::checkIn()

**Current flow**: Create attendance after geo validation
**New flow**:
```php
// Before creating attendance:
1. Check if APPROVED absence exists
   → Reject if ada
2. Get Schedule + Assignment
3. If Assignment = O (is_off=true):
   - Check overtime_log untuk hari ini
   - Calculate late dari overtime start_time
   - Set overtime_status = APPROVED (dari overtime_log)
4. If Assignment != O:
   - Calculate late normal
   - Check jika ada overtime (dari overtime_log)
   - Set overtime_status = PENDING jika ada
5. Create Attendance with new fields
```

### ScheduleController::store()

**Current flow**: Create schedule
**New flow** (minimal):
```php
// Tetap sama, tidak perlu banyak change
// Hanya perlu pastikan assignment_id valid & ada
```

### PayrollCalculationService (New)

**Input**: payroll_run_id, user_id
**Process**:
```php
1. Get Schedule untuk periode (user_id, date range)
2. Count attendance per assignment
3. Sum late_minutes dari attendance
4. Count absence per type (SAKIT/IZIN/CUTI)
5. Count alpha = schedule count - attendance - absence
6. Get approved overtime sum
7. Get applicable payroll_policy
8. Calculate:
   - base_salary = attendance_count × daily_rate
   - deduction_late = late_minutes × rate
   - deduction_absence = absence_count × amount
   - deduction_alpha = alpha_count × amount
   - addition_overtime = overtime_hours × hourly_rate × (percent/100)
   - addition_bonus = (late_count == 0 && alpha_count == 0) ? bonus_amount : 0
   - net = base + addition - deduction
9. Return PayrollDetail populated
```

---

## 10. NOTES & CAVEATS

1. **Grace Period**: Sudah exist di Attendance logic, ensure diapply juga untuk OvertimeLog
2. **Timezone**: Ensure semua timestamp handling timezone-aware (beri tahu user timezone mana)
3. **Overtime Approval**: Bisa langsung di-approve HO saat create, atau pending tunggu attendance
4. **Cuti Handling**: Belum dijelaskan apakah paid atau unpaid. Saat ini treat sama seperti SAKIT
5. **Multiple Assignment**: Spec belum jelas jika 1 user bisa punya 2+ assignment dalam 1 bulan
6. **Contract Period**: PayrollRun per month, tapi jika user hanya contract setengah bulan?
7. **Rounding**: Specify rounding rule untuk gaji (round up, down, nearest?)

---

**End of Design Document**
