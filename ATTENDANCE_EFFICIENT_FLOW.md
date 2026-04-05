# 📋 ATTENDANCE SYSTEM - EFFICIENT DATA FLOW

## **PERTANYAAN UTAMA**
> Lebih efisien mana: Get semua data dari Schedule atau pisah-pisah?

**JAWABAN: GET DARI SCHEDULE dengan Eager Loading (1 Query) ✅**

---

## **PERBANDINGAN EFISIENSI**

### ❌ **INEFFICIENT: Pisah-Pisah Query**
```php
// 4+ Database Queries!
$schedule = Schedule::where('user_id', $user->id)->where('date', $today)->first(); // Query 1
$assignment = Assignment::find($schedule->assignment_id); // Query 2
$post = Post::find($schedule->post_id); // Query 3
$project = Project::find($assignment->project_id); // Query 4
```
**Cost: 4 queries = LAMBAT**

---

### ✅ **EFFICIENT: Schedule dengan Eager Load (1 Query)**
```php
// 1 Database Query dengan Eager Load!
$schedule = Schedule::where('user_id', $user->id)
    ->where('date', $today)
    ->with('assignment', 'post', 'project')  // ← Eager load
    ->first();
```
**Cost: 1 query = CEPAT**

---

## **ATTENDANCE FLOW - DIAGRAM**

```
┌─────────────────────────────────────────────────────────────┐
│ 1. USER CHECK-IN (dari Device/HP)                          │
│                                                             │
│ POST /api/attendances/check-in                             │
│ {                                                          │
│   "project_id": 1,                     ← user ke project   │
│   "latitude": -6.200050,               ← device location   │
│   "longitude": 106.816700,             ← device location   │
│   "current_time": "2026-02-10 09:30",  ← device time      │
│   "selfie_photo": file                 ← selfie proof      │
│ }                                                          │
└────────────────┬──────────────────────────────────────────┘
                 │
                 ▼
┌─────────────────────────────────────────────────────────────┐
│ 2. GET SCHEDULE (Single Efficient Query + Eager Load)      │
│                                                             │
│ Database Query:                                            │
│ SELECT schedules.* FROM schedules WHERE user_id = $user   │
│   AND date = 2026-02-10                                   │
│   WITH schedule.assignment (FK)     ← Eager load          │
│   WITH schedule.post (FK)           ← Eager load          │
│   WITH schedule.project (FK)        ← Eager load          │
│                                                             │
│ Result: Schedule object with nested:                      │
│ ├─ schedule.assignment                                    │
│ │  ├─ code: 'P' (Pagi), 'M' (Malam), 'O' (OFF)           │
│ │  ├─ start_time: '09:00:00'                             │
│ │  ├─ end_time: '17:00:00'                               │
│ │  ├─ grace_period: 15 (minutes)                         │
│ │  └─ is_off: false                                       │
│ │                                                         │
│ ├─ schedule.post                                          │
│ │  ├─ name: 'Pos Gate', 'Patroli Mobile', etc           │
│ │  ├─ type: 'static' atau 'mobile'                      │
│ │  └─ latitude/longitude: (jika mobile post)             │
│ │                                                         │
│ └─ schedule.project                                       │
│    ├─ location_latitude: -6.200000 (fixed office)        │
│    ├─ location_longitude: 106.816667                     │
│    └─ radius: 100 (meters, allowed geofence)             │
│                                                             │
│ ✓ SEMUA DATA TERSEDIA DARI 1 QUERY!                       │
└────────────────┬──────────────────────────────────────────┘
                 │
                 ▼
┌─────────────────────────────────────────────────────────────┐
│ 3. VALIDATION (using eager-loaded data)                    │
│                                                             │
│ A. AUTHORIZATION CHECK                                    │
│    ├─ User role = komandan_regu atau anggota? ✓          │
│    └─ (HO/DEV/Admin tidak boleh check-in) ✗              │
│                                                             │
│ B. SCHEDULE CHECK                                         │
│    ├─ Schedule exists untuk hari ini? ✓                  │
│    &lt;─ Assignment code? (P/M/O) ← dari eager load          │
│                                                             │
│ C. ABSENCE CHECK                                          │
│    └─ Ada APPROVED absence (sakit/izin/cuti)? ✗          │
│                                                             │
│ D. OFF-DUTY CHECK (jika assignment.code = 'O')           │
│    └─ Ada APPROVED overtime? ✗ (harus ada!)              │
│                                                             │
│ E. LOCATION VERIFICATION (Haversine)                      │
│    ├─ Reference: project.location_lat/lng ← eager load   │
│    ├─ Device: request.latitude/longitude                 │
│    ├─ Distance = Haversine(ref, device)                  │
│    └─ Distance <= project.radius? ✓                      │
│                                                             │
│ F. TIME VERIFICATION                                      │
│    ├─ Assignment start_time ← eager load: '09:00:00'    │
│    ├─ Device time: '09:30:00'                            │
│    ├─ Grace period: 15 minutes                           │
│    ├─ Grace deadline: 09:00 + 15 = 09:15:00            │
│    ├─ Absolute deadline: 09:00 + 30 = 09:30:00        │
│    │                                                     │
│    └─ Time check:                                        │
│       ├─ IF device_time < grace_deadline → HADIR ✓      │
│       ├─ IF grace_deadline ≤ device_time < absolute      │
│       │   → HADIR TELAT + late_minutes ✓               │
│       └─ IF device_time >= absolute_deadline            │
│           → REJECT (403 - too late) ✗                   │
│                                                             │
└────────────────┬──────────────────────────────────────────┘
                 │
                 ▼
┌─────────────────────────────────────────────────────────────┐
│ 4. SAVE TO DATABASE                                        │
│                                                             │
│ INSERT INTO attendances (                                  │
│   project_id = 1,           ← from request                │
│   user_id = 5,              ← from auth                   │
│   schedule_id = 12,         ← from scheduled lookup       │
│   assignment_id = 2,        ← from schedule.assignment    │
│   post_id = 3,              ← from schedule.post          │
│   date = '2026-02-10',      ← today                       │
│   check_in_at = '09:30:00', ← DEVICE TIME (not server)   │
│   checkin_lat = -6.200050,  ← device latitude            │
│   checkin_lng = 106.816700, ← device longitude           │
│   attendance_status = 'HADIR TELAT',  ← calculated       │
│   computed_status = 'HADIR TELAT',    ← calculated       │
│   late_minutes = 30,        ← from time diff             │
│   overtime_minutes = 0,     ← default at check-in        │
│   overtime_status = 'NONE', ← default                    │
│   selfie_photo_path = '...' ← from file upload           │
│ )                                                         │
│                                                             │
└────────────────┬──────────────────────────────────────────┘
                 │
                 ▼
┌─────────────────────────────────────────────────────────────┐
│ 5. RETURN RESPONSE (201 Created)                           │
│                                                             │
│ {                                                          │
│   "message": "Absen masuk berhasil.",                     │
│   "data": {                                               │
│     "id": 42,                                             │
│     "date": "2026-02-10",                                 │
│     "schedule": {                                         │
│       "assignment": {                                     │
│         "code": "P",          ← FROM EAGER LOAD          │
│         "name": "Pagi",       ← FROM EAGER LOAD          │
│         "start_time": "09:00:00",      ← TIMING          │
│         "end_time": "17:00:00",        ← TIMING          │
│         "grace_period": "15 minutes"   ← GRACE INFO      │
│       },                                                  │
│       "post": {                                           │
│         "name": "Pos Gate",   ← FROM EAGER LOAD          │
│         "type": "static"      ← FOR VALIDATION            │
│       }                                                   │
│     },                                                    │
│     "timing": {                                           │
│       "check_in_at": "09:30:00",                         │
│       "late_minutes": 30                                  │
│     },                                                    │
│     "status": {                                           │
│       "attendance_status": "HADIR TELAT",                 │
│       "computed_status": "HADIR TELAT"                    │
│     }                                                     │
│   }                                                       │
│ }                                                          │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

---

## **STATUS CALCULATION - LOGIC**

```
INPUT:
├─ device_time: 09:30:45 (dari HP/request)
├─ assignment.start_time: 09:00:00 (dari DB eager load)
├─ assignment.grace_period: 15 (dari DB eager load)
└─ project.radius: 100 meters

CALCULATION:
├─ grace_deadline = 09:00:00 + 15 min = 09:15:00
├─ absolute_deadline = 09:00:00 + 30 min = 09:30:00
└─ device_time = 09:30:45

LOGIC:
├─ IF 09:30:45 < 09:15:00? NO
├─ ELIF 09:30:45 >= 09:30:00? YES → REJECT 403
├─ ELSE (09:15:00 ≤ 09:30:45 < 09:30:00) → HADIR TELAT
│   └─ late_minutes = 09:30:45 - 09:00:00 = 30 min 45 sec ≈ 30 minutes
└─ Result: status = 'HADIR TELAT', late_minutes = 30

POSSIBLE STATUS VALUES:
├─ HADIR (on time, before grace deadline)
├─ HADIR TELAT (late, but within grace period * 2)
├─ HADIR LEMBUR (overtime confirmed at check-out)
└─ HADIR TELAT LEMBUR (both late and overtime)
```

---

## **KEY POINTS - EFFICIENT LOADING**

### **1. Schedule adalah Single Source of Truth**
```php
// ✅ GOOD - Single eager load query
$schedule = Schedule::where('user_id', $user->id)
    ->where('date', $today)
    ->with('assignment', 'post', 'project')
    ->first();

// Dari sini dapat semua:
$assignment = $schedule->assignment;      // code, time info
$post = $schedule->post;                   // name, type
$project = $schedule->project;             // location, radius
```

### **2. Response Include Post Details**
```php
// Dalam response:
"post": {
    "name": "Pos Gate",    // ← User tahu di mana
    "type": "static"       // ← System tahu jenis pos
}
```

### **3. Time Validation Flow**
```php
// Semua dari eager load:
$startTime = $assignment->start_time;     // '09:00:00'
$gracePeriod = $assignment->grace_period; // 15
$graceDeadline = startTime + gracePeriod; // 09:15:00
```

### **4. Location Reference**
```php
// Always dari project (fixed office location):
$refLat = $project->location_latitude;
$refLng = $project->location_longitude;
$radius = $project->radius;

// Compare dengan device location:
$distance = Haversine(refLat, refLng, deviceLat, deviceLng);
if ($distance > $radius) reject; ✗
```

---

## **IMPLEMENTATION CODE**

```php
// In AttendanceController::checkIn()

// ✅ EFFICIENT: Single query dengan eager load
$schedule = Schedule::where('user_id', $user->id)
    ->where('date', $today)
    ->with('assignment', 'post', 'project')
    ->first();

if (!$schedule) {
    return response()->json([
        'message' => 'Anda tidak memiliki jadwal hari ini.',
        'date' => $today->format('Y-m-d'),
    ], 403);
}

// Extract dari single query result
$assignment = $schedule->assignment;  // assignment.code, start_time, grace_period
$post = $schedule->post;              // post.name, post.type
$project = $schedule->project;        // project.location_*, radius

// Semua validasi menggunakan data dari single query
// No more N+1 problems!
```

---

## **RESPONSE FORMAT - COMPLETE**

```json
{
  "message": "Absen masuk berhasil.",
  "data": {
    "id": 42,
    "date": "2026-02-10",
    "schedule": {
      "assignment": {
        "code": "P",
        "name": "Pagi",
        "start_time": "09:00:00",
        "end_time": "17:00:00",
        "grace_period": "15 minutes",
        "is_off_duty": false
      },
      "post": {
        "id": 3,
        "name": "Pos Gate",
        "type": "static"
      }
    },
    "timing": {
      "check_in_at": "09:30:00",
      "check_out_at": null,
      "late_minutes": 30,
      "overtime_minutes": 0
    },
    "status": {
      "attendance_status": "HADIR TELAT",
      "computed_status": "HADIR TELAT",
      "overtime_status": "NONE"
    },
    "can_attend": true
  }
}
```

---

## **KESIMPULAN**

| Aspek | Pisah-Pisah Query ❌ | Schedule Eager Load ✅ |
|-------|-------------------|----------------------|
| **DB Queries** | 4+ | 1 |
| **Speed** | SLOW | FAST |
| **N+1 Problem** | YES | NO |
| **Data Availability** | Scattered | Centralized |
| **Code Clarity** | Complex | Simple |
| **Recommended** | ❌ NO | ✅ YES |

**→ ALWAYS use Schedule eager loading untuk attendance!** 🚀
