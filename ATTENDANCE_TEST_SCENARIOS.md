# 📋 ATTENDANCE CHECK-IN TEST SCENARIOS

## **SYSTEM FLOW DIAGRAM**

```
┌─────────────────────────────────────────────────────────────────┐
│                     USER CHECKS IN                              │
│                    (HP/Laptop)                                   │
└──────────────────────────┬──────────────────────────────────────┘
                           │
                           ├─ Capture Device Time: 09:30:45
                           ├─ Capture Device Location: -6.200100, 106.816750
                           ├─ Take Selfie Photo
                           │
                           ▼
┌─────────────────────────────────────────────────────────────────┐
│              SEND TO SERVER API                                 │
│  POST /api/attendances/check-in                                │
│  {                                                              │
│    "project_id": 1,                                             │
│    "latitude": -6.200100,      ← Device current location        │
│    "longitude": 106.816750,    ← Device current location        │
│    "current_time": "2026-02-10 09:30:45", ← Device current time │
│    "selfie_photo": [binary]    ← Device camera                  │
│  }                                                              │
└──────────────────────────┬──────────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────────────┐
│              SERVER VALIDATION                                  │
│                                                                 │
│  1. Check Schedule Exists                                       │
│     Schedule.where(user_id, date) = ?                          │
│     ├─ EXISTS: Continue ✓                                       │
│     └─ NOT EXIST: Error 403                                    │
│                                                                 │
│  2. Check Location (HAVERSINE)                                 │
│     Distance between:                                           │
│     - Project Location: -6.200000, 106.816667 (DB) 📍          │
│     - Device Location: -6.200100, 106.816750 (Request) 📍      │
│     = 95 meters                                                 │
│                                                                 │
│     If distance > project.radius (100m):                        │
│        ├─ OUT OF RANGE: Error 403                             │
│        └─ IN RANGE: Continue ✓                                │
│                                                                 │
│  3. Check Time (ASSIGNMENT)                                     │
│     Assignment.start_time: 09:00:00 (DB)  ⏰                   │
│     Assignment.grace_period: 15 (DB)      ⏰                   │
│     Device current_time: 09:30:45 (Request) ⏰                 │
│                                                                 │
│     Grace Deadline: 09:00:00 + 15min = 09:15:00               │
│     Absolute Deadline: 09:00:00 + 30min = 09:30:00            │
│                                                                 │
│     Check:                                                      │
│     09:30:45 < 09:00:00?  NO ❌                               │
│     09:30:45 > 09:30:00?  YES ✓ (TELAT 30 minutes)           │
│     09:30:45 > 09:15:00?  YES ✓ (masih di dalam deadline)    │
│                                                                 │
│     Result: HADIR TELAT ✓                                      │
│                                                                 │
│  4. Check Absence                                              │
│     Absence.where(user_id, schedule_id, status='APPROVED')    │
│     ├─ EXISTS: Error 403 (tidak bisa absen)                  │
│     └─ NOT EXIST: Continue ✓                                  │
│                                                                 │
│  5. Check Assignment Type (O)                                  │
│     If Assignment.code = 'O' (OFF):                            │
│     OvertimeLog.where(user_id, status='APPROVED')             │
│     ├─ EXISTS: Continue ✓                                      │
│     └─ NOT EXIST: Error 403                                   │
│                                                                 │
└──────────────────────────┬──────────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────────────┐
│              CREATE ATTENDANCE RECORD                           │
│                                                                 │
│  INSERT INTO attendances                                        │
│  {                                                              │
│    user_id: 1,                                                  │
│    project_id: 1,                                              │
│    schedule_id: 5,                                             │
│    assignment_id: 2,                                           │
│    post_id: 3,                                                 │
│    date: 2026-02-10,                                           │
│    check_in_at: 2026-02-10 09:30:45,   ← Device time         │
│    checkin_lat: -6.200100,              ← Device location     │
│    checkin_lng: 106.816750,             ← Device location     │
│    attendance_status: 'HADIR TELAT',                          │
│    computed_status: 'HADIR TELAT',                            │
│    late_minutes: 30,                                           │
│    selfie_photo_path: 'attendances/selfies/...'              │
│  }                                                              │
│                                                                 │
└──────────────────────────┬──────────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────────────┐
│              RETURN SUCCESS RESPONSE                            │
│                                                                 │
│  {                                                              │
│    "message": "Absen masuk berhasil.",                         │
│    "data": {                                                    │
│      "id": 42,                                                  │
│      "user_id": 1,                                             │
│      "date": "2026-02-10",                                     │
│      "assignment": {                                            │
│        "code": "P",                                             │
│        "start_time": "09:00:00",                               │
│        "end_time": "17:00:00",                                 │
│        "grace_period": 15                                       │
│      },                                                         │
│      "post": {                                                  │
│        "id": 3,                                                │
│        "name": "Pos Gate",                                     │
│        "type": "static"                                        │
│      },                                                         │
│      "check_in_at": "09:30:45",                               │
│      "attendance_status": "HADIR TELAT",                       │
│      "computed_status": "HADIR TELAT",                         │
│      "late_minutes": 30,                                       │
│      "can_attend": false                                        │
│    }                                                            │
│  }                                                              │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

---

## **TEST SCENARIO 1: ON TIME (HADIR)**

### Setup:
```
Project: 
  - latitude: -6.200000
  - longitude: 106.816667
  - radius: 100 meters

Assignment (code=P):
  - start_time: 09:00:00
  - end_time: 17:00:00
  - grace_period: 15 minutes

User Location when Check-in:
  - latitude: -6.200050
  - longitude: 106.816700
  - distance: ~50 meters ✓ (dalam radius)

User Time when Check-in:
  - current_time: 09:10:30 (10.5 minutes after start)
```

### Request:
```bash
POST http://localhost:8000/api/attendances/check-in
Authorization: Bearer TOKEN

{
  "project_id": 1,
  "latitude": -6.200050,
  "longitude": 106.816700,
  "current_time": "2026-02-10 09:10:30",
  "selfie_photo": [binary_image]
}
```

### Validations:
```
✓ Location: 50 meters < 100 meters (project radius)
✓ Time: 09:10:30 < 09:15:00 (grace deadline)
✓ Status: HADIR (on time)
✓ Late minutes: 0
```

### Response:
```json
{
  "message": "Absen masuk berhasil.",
  "data": {
    "id": 1,
    "date": "2026-02-10",
    "assignment": {
      "code": "P",
      "start_time": "09:00:00",
      "end_time": "17:00:00",
      "grace_period": 15
    },
    "check_in_at": "09:10:30",
    "attendance_status": "HADIR",
    "computed_status": "HADIR",
    "late_minutes": 0
  }
}
```

---

## **TEST SCENARIO 2: LATE (HADIR TELAT)**

### Setup sama dengan Scenario 1, tapi:

### User Time when Check-in:
```
current_time: 09:25:30 (25 minutes after start, 10 minutes setelah grace)
```

### Request:
```bash
POST http://localhost:8000/api/attendances/check-in
Authorization: Bearer TOKEN

{
  "project_id": 1,
  "latitude": -6.200050,
  "longitude": 106.816700,
  "current_time": "2026-02-10 09:25:30",
  "selfie_photo": [binary_image]
}
```

### Validations:
```
✓ Location: 50 meters < 100 meters
✓ Time: 09:25:30 > 09:15:00 (grace deadline) = TELAT ✓
✓ Time: 09:25:30 < 09:30:00 (absolute deadline) = MASIH BISA MASUK ✓
✗ Late: 25 minutes
```

### Response:
```json
{
  "message": "Absen masuk berhasil.",
  "data": {
    "check_in_at": "09:25:30",
    "attendance_status": "HADIR TELAT",
    "computed_status": "HADIR TELAT",
    "late_minutes": 25
  }
}
```

---

## **TEST SCENARIO 3: TERLALU TELAT (Reject)**

### User Time when Check-in:
```
current_time: 09:35:00 (35 minutes after start, LEWAT deadline)
```

### Request:
```bash
POST http://localhost:8000/api/attendances/check-in
Authorization: Bearer TOKEN

{
  "project_id": 1,
  "latitude": -6.200050,
  "longitude": 106.816700,
  "current_time": "2026-02-10 09:35:00",
  "selfie_photo": [binary_image]
}
```

### Validations:
```
✓ Location: OK
✗ Time: 09:35:00 > 09:30:00 (absolute deadline) = REJECT
```

### Response (Error):
```json
{
  "message": "Waktu absen masuk telah berakhir.",
  "assignment": {
    "code": "P",
    "start_time": "09:00:00"
  },
  "allowed_deadline": "09:30:00",
  "your_time": "09:35:00"
}
```

---

## **TEST SCENARIO 4: OUT OF LOCATION RADIUS (Reject)**

### Same setup, tapi:

### User Location:
```
latitude: -6.202000
longitude: 106.820000
distance: 2.5 KM (2500 meters) ❌ OUT OF RANGE
```

### Request:
```bash
POST http://localhost:8000/api/attendances/check-in
Authorization: Bearer TOKEN

{
  "project_id": 1,
  "latitude": -6.202000,
  "longitude": 106.820000,
  "current_time": "2026-02-10 09:10:30",
  "selfie_photo": [binary_image]
}
```

### Response (Error):
```json
{
  "message": "Anda berada di luar radius absen masuk.",
  "your_location": {
    "latitude": -6.2,
    "longitude": 106.82
  },
  "project_location": {
    "latitude": -6.2,
    "longitude": 106.816667
  },
  "distance": "2500.45 meters",
  "allowed_radius": "100 meters"
}
```

---

## **TEST SCENARIO 5: ASSIGNMENT O (OFF) - WITHOUT OVERTIME (Reject)**

### Setup:
```
Assignment:
  - code: "O" (OFF)
  - is_off: true
  
OvertimeLog:
  - TIDAK ADA approved overtime untuk hari ini
```

### Request:
```bash
POST http://localhost:8000/api/attendances/check-in
Authorization: Bearer TOKEN

{
  "project_id": 1,
  "latitude": -6.200050,
  "longitude": 106.816700,
  "current_time": "2026-02-10 09:10:30",
  "selfie_photo": [binary_image]
}
```

### Response (Error):
```json
{
  "message": "Hari ini adalah hari OFF. Memerlukan persetujuan lembur terlebih dahulu.",
  "code": "O"
}
```

---

## **TEST SCENARIO 6: ASSIGNMENT O (OFF) - WITH OVERTIME (Success)**

### Setup:
```
Assignment:
  - code: "O" (OFF)

OvertimeLog:
  - status: "APPROVED"
  - planned_start_time: "18:00:00"
  - planned_end_time: "21:00:00"
```

### Request:
```bash
POST http://localhost:8000/api/attendances/check-in
Authorization: Bearer TOKEN

{
  "project_id": 1,
  "latitude": -6.200050,
  "longitude": 106.816700,
  "current_time": "2026-02-10 18:05:00",
  "selfie_photo": [binary_image]
}
```

### Validation:
```
✓ Assignment = O, tapi ada approved overtime
✓ Check-in time 18:05:00 > start time 18:00:00 = OK
✓ Status: HADIR (tidak telat dari planned_start_time)
```

### Response:
```json
{
  "message": "Absen masuk berhasil.",
  "data": {
    "assignment": {
      "code": "O",
      "is_off_duty": true
    },
    "check_in_at": "18:05:00",
    "attendance_status": "HADIR",
    "computed_status": "HADIR"
  }
}
```

---

## **TEST SCENARIO 7: APPROVED ABSENCE (Reject)**

### Setup:
```
Absence:
  - status: "APPROVED"
  - absence_type: "SAKIT"
```

### Request:
```bash
POST http://localhost:8000/api/attendances/check-in
Authorization: Bearer TOKEN

{
  "project_id": 1,
  "latitude": -6.200050,
  "longitude": 106.816700,
  "current_time": "2026-02-10 09:10:30",
  "selfie_photo": [binary_image]
}
```

### Response (Error):
```json
{
  "message": "Anda telah disetujui untuk SAKIT. Tidak dapat absen masuk.",
  "absence_type": "SAKIT"
}
```

---

## **DATABASE SETUP FOR TESTING**

### 1. Ensure Project has location:
```sql
UPDATE projects 
SET latitude = -6.200000, 
    longitude = 106.816667, 
    radius = 100
WHERE id = 1;
```

### 2. Create Assignment P:
```sql
INSERT INTO assignments (project_id, name, code, is_off, start_time, end_time, grace_period, created_at, updated_at)
VALUES (1, 'Pagi', 'P', 0, '09:00:00', '17:00:00', 15, NOW(), NOW());
```

### 3. Create Schedule for today:
```sql
INSERT INTO schedules (project_id, user_id, assignment_id, post_id, date, created_at, updated_at)
VALUES (1, 1, 1, 1, CURDATE(), NOW(), NOW());
```

### 4. Get current user token:
```bash
POST /api/login
{
  "email": "user@example.com",
  "password": "password"
}
```

---

## **KEY TAKEAWAYS**

| Komponen | Dari Mana | Tujuan |
|----------|-----------|--------|
| **Project lat/lng** | Database (setup project) | Lokasi kantor/project |
| **Device lat/lng** | Request (dari HP user) | Lokasi user saat check-in |
| **Assignment time** | Database | Waktu kerja yang dijadwalkan |
| **Device current_time** | Request (dari HP user) | Waktu user saat check-in |
| **Distance** | Haversine calc | Verifikasi user dalam radius |
| **Late minutes** | Time difference | Deteksi keterlambatan |
| **Status** | Logic check | HADIR / HADIR TELAT / HADIR LEMBUR |

