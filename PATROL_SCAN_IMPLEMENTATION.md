# Panduan Implementasi - Sistem Patrol Scan

## 📦 Komponen yang Sudah Diimplementasikan

### 1. Models
- ✅ `App\Models\PatrolPoint` - Titik patrol dengan unique constraint [post_id, sequence_order]
- ✅ `App\Models\QrCode` - QR code untuk setiap patrol point
- ✅ `App\Models\PatrolScan` - Record scan dengan attendance_id & qr_code_id
- ✅ `App\Models\PatrolScanPhoto` - Foto evidence dari scan
- ✅ `App\Models\Attendance` (updated) - Added helper methods untuk commander support

#### Attendance Model - New Methods
```php
// Cek jika attendance adalah komandan
$attendance->isCommanderAttendance(): bool

// Get patrol points untuk attendance
$attendance->getPatrolPoints(): Collection

// Get location koordinat berdasarkan user type
$attendance->getLocationCoordinates(): array
```

### 2. Services
- ✅ `App\Services\PatrolScanService` - Core business logic untuk patrol scanning

#### Key Methods
```php
// Cek user dapat scan
$service->canUserScan(Attendance $attendance, $user): array

// Validasi QR code
$service->validateQrCode(string $qrCode, Attendance $attendance): array

// Validasi sequence order (member only)
$service->validateSequenceOrder(Attendance $attendance, PatrolPoint $point): array

// Validasi lokasi (distance, altitude)
$service->validateLocation(...): array

// Create scan dengan validasi lengkap
$service->createScan(...): array

// Add photo ke scan
$service->addPhoto(PatrolScan $scan, $photoFile): array

// Get progress scanning
$service->getScanProgress(Attendance $attendance): array

// Check semua scans completed
$service->isAllScansCompleted(Attendance $attendance): bool
```

### 3. Policies
- ✅ `App\Policies\PatrolScanPolicy` - Authorization untuk patrol scans
- ✅ `App\Policies\PatrolScanPhotoPolicy` - Authorization untuk patrol scan photos

### 4. Controllers
- ✅ `App\Http\Controllers\PatrolScanController` - API endpoints

### 5. Routes
- ✅ Routes di `routes/api.php` untuk semua patrol scan operations

### 6. Helpers
- ✅ `App\Helpers\PatrolScanValidator` - Validation utility functions

---

## 🔄 Integration dengan Attendance Flow

### Skenario 1: Member Check-In → Patrol Scan → Check-Out

#### Step 1: Member Check-In
```http
POST /api/attendances/check-in
{
    "post_type": "mobile",
    "post_name": "Gate Utama",
    "latitude": -6.1234,
    "longitude": 106.7890,
    "current_time": "2026-02-23 10:00:00",
    "selfie_photo": <file>
}

Response: 201
{
    "message": "Absen masuk berhasil.",
    "data": {
        "id": 1,
        "attendance_id": 1,
        "post_id": 5,
        "status": "HADIR",
        ...
    }
}
```

Looping Dalam Data Model:
```
User: Anggota (role: anggota)
  ├─ Check-in
  │  ├─ Pilih: post_type="mobile", post_name="Gate Utama"
  │  ├─ Lokasi: latitude=-6.1234, longitude=106.7890
  │  ├─ Create: Attendance(post_id=5, post!=null)
  │  └─ Status: HADIR / HADIR TELAT
  │
  ├─ Get Patrol Points
  │  └─ SELECT * FROM patrol_points 
  │     WHERE post_id=5 
  │     ORDER BY sequence_order
  │     → Result: [Point1, Point2, Point3, Point4, Point5]
  │
  └─ Scanning Loop (seq 1 → 5)
```

#### Step 2: Member Get Patrol Points
```http
GET /api/attendance/1/patrol-scan/progress

Response: 200
{
    "success": true,
    "data": {
        "progress": {
            "total": 5,
            "scanned": 0,
            "remaining": 5,
            "percentage": 0,
            "completed": false
        },
        "patrol_points": [
            {
                "id": 1,
                "name": "Gate Utama",
                "sequence_order": 1,
                "latitude": -6.1235,
                "longitude": 106.7899,
                "radius": 5,
                "is_scanned": false
            },
            // ... points 2-5
        ]
    }
}
```

#### Step 3: Member Scan QR (Sequence 1/5)
```http
POST /api/patrol-scan
{
    "attendance_id": 1,
    "qr_code": "550E8400-E29B-41D4-A716-446655440001",
    "scan_latitude": -6.1235,
    "scan_longitude": 106.7899,
    "scan_altitude": 25.5,
    "note": "Situasi aman"
}

Inside Service Flow:
1. canUserScan()
   - attendance_id 1 milik user? ✅
   - date hari ini? ✅
   - belum checkout? ✅
   - check-in sudah? ✅
   - post dipilih? ✅

2. validateQrCode()
   - QR code ada? ✅
   - QR active? ✅
   - QR milik post 5? ✅

3. validateSequenceOrder()
   - Scan belum pernah? ✅
   - Sudah scan seq 1 terlebih dahulu? N/A (first scan)
   - Next expected = 1? ✅

4. validateLocation()
   - Calculate distance -6.1235/106.7899 vs -6.1235/106.7899
   - Distance = ~0m ✅
   - Altitude |25.5-25.0| = 0.5m ✅

5. CREATE PatrolScan
   - attendance_id: 1
   - qr_code_id: 1
   - scan_time: now()

Response: 201
{
    "success": true,
    "message": "Scan 'Gate Utama' berhasil dicatat (1/5)",
    "data": {
        "scan": {...},
        "progress": {
            "total": 5,
            "scanned": 1,
            "percentage": 20,
            "completed": false
        }
    }
}
```

#### Step 4: Member Upload Photo
```http
POST /api/patrol-scan/1/photo
Content-Type: multipart/form-data
{
    "photo": <image file>
}

Response: 201
{
    "success": true,
    "message": "Foto berhasil disimpan",
    "data": {
        "id": 1,
        "patrol_scan_id": 1,
        "photo": "patrol-scan-photos/...",
        "url": "http://localhost:8000/storage/..."
    }
}
```

#### Step 5: Member Continue Scanning (Seq 2-5)
Repeat Step 3-4 untuk sequences 2, 3, 4, 5

**Jika scanning sequence salah:**
```http
POST /api/patrol-scan
{
    "attendance_id": 1,
    "qr_code": "550E8400-E29B-41D4-A716-446655440003",  // Seq 3, tapi harus seq 2
    ...
}

Response: 422
{
    "success": false,
    "errors": [
        "Anda harus scan point 'Gedung Timur' terlebih dahulu (urutan 2)"
    ]
}
```

#### Step 6: Member Check Completion
```http
GET /api/attendance/1/patrol-scan/statistics

Response: 200
{
    "success": true,
    "data": {
        "total_scans": 5,
        "total_photos": 5,
        "completion_time_minutes": 45,
        "progress": {
            "total": 5,
            "scanned": 5,
            "remaining": 0,
            "percentage": 100,
            "completed": true
        },
        "all_completed": true
    }
}
```

#### Step 7: Member Check-Out
```http
POST /api/attendances/check-out
{
    "attendance_id": 1,
    "latitude": -6.1234,
    "longitude": 106.7890,
    "current_time": "2026-02-23 17:30:00"
}

Server-side Check:
- Verify all patrol scans completed ✅
- Record checkout time
- Calculate overtime (jika ada)
- Update attendance status

Response: 200
{
    "message": "Check-out berhasil",
    "data": {
        "id": 1,
        "check_out_at": "2026-02-23T17:30:00Z",
        "scan_count": 5,
        "all_scans_completed": true
    }
}
```

---

### Skenario 2: Komandan Check-In → Patrol Scan → Check-Out

#### Step 1: Komandan Check-In (NO post selection)
```http
POST /api/attendances/check-in
{
    // Komandan tidak perlu post_type & post_name
    "latitude": -6.1234,
    "longitude": 106.7890,
    "current_time": "2026-02-23 10:00:00",
    "selfie_photo": <file>
}

Inside Controller:
$user = Auth::user(); // role: komandan_regu
$isCommander = true

// Skip post_type & post_name validation
// Auto-get static post untuk project
$post = Post::where('type', 'static')
           ->where('project_id', $user->project_id)
           ->first();

// Create attendance dengan post_id = null (atau static post id)
$attendance = Attendance::create([
    'post_id' => null,  // atau $post->id jika prefer reference
    'user_id' => $user->id,
    ...
]);

Response: 201
{
    "message": "Absen masuk berhasil",
    "data": {
        "id": 2,
        "post_id": null,  // Komandan tidak punya post
        "is_commander": true,
        ...
    }
}
```

#### Step 2: Komandan Get Patrol Point (Static = 1 point)
```http
GET /api/attendance/2/patrol-scan/progress

Response: 200
{
    "success": true,
    "data": {
        "progress": {
            "total": 1,  // Hanya 1 static point
            "scanned": 0,
            "remaining": 1,
            "percentage": 0,
            "completed": false
        },
        "patrol_points": [
            {
                "id": 101,
                "name": "Static Post - Project HQ",
                "sequence_order": 1,  // Always 1 for static
                "latitude": -6.1234,
                "longitude": 106.7890,
                "radius": 5,
                "is_scanned": false
            }
        ]
    }
}
```

#### Step 3: Komandan Scan QR (1x only)
```http
POST /api/patrol-scan
{
    "attendance_id": 2,
    "qr_code": "550E8400-E29B-41D4-A716-STATIC-001",
    "scan_latitude": -6.1234,
    "scan_longitude": 106.7890,
    "note": ""
}

Inside Service Flow:
1. canUserScan()
   - attendance_id 2 milik komandan? ✅
   - check-in sudah? ✅
   - belum checkout? ✅

2. validateQrCode()
   - QR code ada? ✅
   - QR dari static post? ✅

3. validateSequenceOrder()
   - isCommanderAttendance()? ✅ → SKIP validation
   - Komandan tidak perlu urutan

4. validateLocation()
   - Distance: user vs project location ✅

5. CREATE PatrolScan

Response: 201
{
    "success": true,
    "message": "Scan 'Static Post - Project HQ' berhasil dicatat (1/1)",
    "data": {
        "progress": {
            "total": 1,
            "scanned": 1,
            "percentage": 100,
            "completed": true
        }
    }
}
```

#### Step 4: Komandan Upload Photo & Check-Out
Same as member, but only 1 photo required (1 scan)

---

## 🧪 Testing Scenarios

### Test Case 1: Wrong Sequence Detection
```php
// Setup
$member = User::where('role', 'anggota')->first();
$attendance = Attendance::where('user_id', $member->id)->first();

// Get patrol points (5 points)
$points = $attendance->getPatrolPoints();

// Try scanning seq 3 first (should fail)
$result = $service->createScan(
    $attendance,
    $points[2]->qrCode->code,  // seq 3
    -6.1234,
    106.7890
);

assert($result['success'] === false);
assert(strpos($result['errors'][0], 'urutan 1') !== false);
```

### Test Case 2: Out of Radius
```php
// Setup
$result = $service->createScan(
    $attendance,
    $qrCode,
    -6.5000,  // Too far away
    106.9000,
);

assert($result['success'] === false);
assert(strpos($result['errors'][0], 'terlalu jauh') !== false);
```

### Test Case 3: Altitude Mismatch
```php
// Setup
$result = $service->createScan(
    $attendance,
    $qrCode,
    -6.1234,
    106.7890,
    100.0,  // Too high
);

assert($result['success'] === false);
assert(strpos($result['errors'][0], 'Ketinggian') !== false);
```

### Test Case 4: Commander Single Scan
```php
// Setup
$commander = User::where('role', 'komandan_regu')->first();
$attendance = Attendance::where('user_id', $commander->id)->first();

// Should have only 1 patrol point
$points = $attendance->getPatrolPoints();
assert(count($points) === 1);

// Scan the static point
$result = $service->createScan(
    $attendance,
    $points[0]->qrCode->code,
    -6.1234,
    106.7890
);

assert($result['success'] === true);

// Should be completed
$progress = $service->getScanProgress($attendance);
assert($progress['completed'] === true);
```

---

## 🔗 Relationship Diagram

```
User (role: anggota)
  └─ Attendance (post_id: 5)
       ├─ Schedule
       ├─ Assignment
       ├─ Project
       │   └─ Post (id: 5, type: 'mobile')
       │        └─ PatrolPoint (post_id: 5)
       │             ├─ sequence_order: 1, 2, 3, ...
       │             └─ QrCode (unique code)
       │
       └─ PatrolScan (multiple)
            ├─ qr_code_id → QrCode
            └─ PatrolScanPhoto (multiple)

User (role: komandan_regu)
  └─ Attendance (post_id: null)
       ├─ Schedule
       ├─ Assignment
       ├─ Project
       │   └─ Post (type: 'static')
       │        └─ PatrolPoint (sequence_order: 1)
       │             └─ QrCode
       │
       └─ PatrolScan (1x)
            └─ PatrolScanPhoto (1+)
```

---

## 🔒 Authorization Flow

```
POST /api/patrol-scan
  ├─ Middleware: auth:sanctum
  │  └─ Check token valid
  │
  ├─ Controller: PatrolScanController::performScan()
  │  └─ authorize('create', PatrolScan::class)
  │     └─ PatrolScanPolicy::create()
  │        └─ return in_array($user->role, ['anggota', 'komandan_regu'])
  │
  ├─ Validation: Input validation
  ├─ Business Logic: PatrolScanService
  └─ Response: 201 or error

DELETE /api/patrol-scan/{scan}/photo/{photoId}
  ├─ Find PatrolScanPhoto
  ├─ authorize('deletePhoto', $scan)
  │  └─ PatrolScanPolicy::deletePhoto()
  │     └─ Check user adalah owner atau admin
  └─ Delete file & DB record
```

---

## 📊 Performance Considerations

### Indexes
```sql
-- Recommended indexes
CREATE INDEX idx_patrol_scans_attendance ON patrol_scans(attendance_id);
CREATE INDEX idx_patrol_scans_qr_code ON patrol_scans(qr_code_id);
CREATE INDEX idx_qr_codes_patrol_point ON qr_codes(patrol_point_id);
CREATE INDEX idx_patrol_points_post ON patrol_points(post_id);
CREATE INDEX idx_patrol_points_sequence ON patrol_points(post_id, sequence_order);
```

### Query Optimization
```php
// Good ✅
$scans = $attendance->patrolScans()
    ->with(['qrCode.patrolPoint', 'photos'])
    ->orderBy('scan_time')
    ->get();

// Bad ❌
$scans = $attendance->patrolScans()->get();
$scans->each(fn($s) => $s->qrCode->patrolPoint); // N+1 query!
```

---

## 🚀 Deployment Checklist

- [ ] Run migrations: `php artisan migrate`
- [ ] Create static posts untuk setiap project
- [ ] Create patrol points untuk setiap post
- [ ] QR codes auto-generated saat patrol point dibuat
- [ ] Test dengan Postman/API client
- [ ] Verify authorization policies
- [ ] Configure storage disk untuk public access
- [ ] Test photo upload & download
- [ ] Document untuk frontend team
- [ ] Load testing untuk scan API

---

## 📚 Additional Resources

- Database schema: See migrations
- API documentation: PATROL_SCAN_SYSTEM.md
- Policy documentation: app/Policies/
- Service documentation: app/Services/PatrolScanService.php
- Helper utilities: app/Helpers/PatrolScanValidator.php

