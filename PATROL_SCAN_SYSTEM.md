# Sistem Patrol Scan - Dokumentasi Lengkap

## 📋 Gambaran Umum

Sistem Patrol Scan dirancang untuk mengelola checkpoint scanning pada sistem kehadiran dengan dua model berbeda:

### Model 1: Member (Anggota)
- **Post Type**: Mobile (bergerak)
- **Lokasi Reference**: Post yang dipilih saat check-in
- **Patrol Points**: Beberapa titik yang harus di-scan secara berurutan
- **QR Code**: Setiap patrol point memiliki QR code unik
- **Foto Requirement**: Wajib upload foto saat scan setiap point
- **Sequence Order**: Harus scan dalam urutan yang tepat (1, 2, 3, dst)
- **Altitude Check**: Validasi ketinggian lokasi sesuai dengan data patrol point

### Model 2: Komandan Regu (Task Commander)
- **Post Type**: Static (statis di lokasi proyeknya)
- **Lokasi Reference**: Dari project location (project.location_latitude/longitude)
- **Patrol Points**: Hanya 1 static point per project
- **QR Code**: 1 QR code untuk static point
- **Foto Requirement**: Wajib upload foto saat scan
- **Sequence Order**: N/A (hanya 1 point)
- **Check-in**: Tidak perlu memilih post, langsung menggunakan lokasi project

---

## 🏗️ Arsitektur Database

### Tabel: patrol_points
```sql
- id (PK)
- post_id (FK → posts)
- name: Nama titik patrol
- sequence_order: Urutan scanning (1, 2, 3, ...)
- latitude: Koordinat lintang
- longitude: Koordinat bujur
- altitude: Ketinggian lokasi (opsional)
- radius: Jarak radius area scanning (dalam km, default: 5)
- timestamps
- UNIQUE: [post_id, sequence_order]
```

### Tabel: qr_codes
```sql
- id (PK)
- patrol_point_id (FK → patrol_points, CASCADE)
- code: QR code string (UUID format)
- active: Boolean status aktif/tidak aktif
- timestamps
```

### Tabel: patrol_scans
```sql
- id (PK)
- attendance_id (FK → attendances, CASCADE)
- qr_code_id (FK → qr_codes, CASCADE)
- scan_time: Waktu scan dilakukan
- note: Catatan opsional
- timestamps
- UNIQUE: [attendance_id, qr_code_id]
```

### Tabel: patrol_scan_photos
```sql
- id (PK)
- patrol_scan_id (FK → patrol_scans, CASCADE)
- photo: Path penyimpanan foto
- timestamps
```

### Tabel: attendances (Modified)
```sql
- post_id: Opsional untuk komandan (NULL)
- (existing fields tetap sama)
```

---

## 🔐 Authorization & Policies

### PatrolScanPolicy
Menentukan siapa yang bisa melakukan tindakan apa:

```php
// viewAny: Lihat list patrol scans
- dev: ✅ Semua
- ho: ✅ Dalam organization
- admin_project: ✅ Di project
- komandan_regu: ✅ Dalam project
- anggota: ✅ Milik sendiri

// view: Lihat detail scan
- Sama dengan viewAny

// create: Melakukan scan
- anggota: ✅
- komandan_regu: ✅

// scanForAttendance: Scan untuk attendance tertentu
- dev: ✅ Untuk siapa saja
- admin_project: ✅ Di project
- anggota/komandan_regu: ✅ Milik sendiri

// addPhoto: Tambah foto ke scan
- dev: ✅ Semua
- admin_project: ✅ Di project
- anggota/komandan_regu: ✅ Milik sendiri

// deletePhoto: Hapus foto dari scan
- dev: ✅ Semua
- admin_project: ✅ Di project
- anggota/komandan_regu: ✅ Milik sendiri
```

---

## 📱 API Endpoints

### 1. Get Scan Progress
```http
GET /api/attendance/{attendance}/patrol-scan/progress
Authorization: Bearer {token}

Response:
{
    "success": true,
    "data": {
        "progress": {
            "total": 5,           // Total patrol points
            "scanned": 2,         // Sudah di-scan
            "remaining": 3,       // Belum di-scan
            "percentage": 40.0,
            "completed": false
        },
        "scans": [...],
        "patrol_points": [
            {
                "id": 1,
                "name": "Gate Utama",
                "sequence_order": 1,
                "latitude": -6.1234,
                "longitude": 106.7890,
                "altitude": 25.5,
                "radius": 5,
                "is_scanned": true
            },
            ...
        ]
    }
}
```

### 2. Perform Patrol Scan
```http
POST /api/patrol-scan
Authorization: Bearer {token}
Content-Type: application/json

Request:
{
    "attendance_id": 1,
    "qr_code": "550E8400-E29B-41D4-A716-446655440000",
    "scan_latitude": -6.1234,
    "scan_longitude": 106.7890,
    "scan_altitude": 25.5,
    "note": "Keamanan di area ini baik"
}

Response on Success:
{
    "success": true,
    "message": "Scan 'Gate Utama' berhasil dicatat (1/5)",
    "data": {
        "scan": {
            "id": 1,
            "attendance_id": 1,
            "qr_code_id": 1,
            "scan_time": "2026-02-23T10:30:45Z",
            "note": "Keamanan di area ini baik"
        },
        "patrol_point": {
            "id": 1,
            "name": "Gate Utama",
            "sequence_order": 1,
            "latitude": -6.1234,
            "longitude": 106.7890,
            "altitude": 25.5,
            "radius": 5
        },
        "progress": {
            "total": 5,
            "scanned": 1,
            "remaining": 4,
            "percentage": 20.0,
            "completed": false
        }
    }
}

Response on Error:
{
    "success": false,
    "errors": [
        "Lokasi scan terlalu jauh. Jarak: 150.23 m, Radius: 5000.00 m",
        "Ketinggian tidak sesuai. Ketinggian expected: 25.00 m, actual: 75.50 m (diff: 50.50 m)"
    ]
}
```

### 3. Add Photo to Scan
```http
POST /api/patrol-scan/{scan}/photo
Authorization: Bearer {token}
Content-Type: multipart/form-data

Request:
- photo: <image file> (Max 5MB)

Response:
{
    "success": true,
    "message": "Foto berhasil disimpan",
    "data": {
        "id": 1,
        "patrol_scan_id": 1,
        "photo": "patrol-scan-photos/scan_1_20260223_103045.jpg",
        "created_at": "2026-02-23T10:30:45Z"
    }
}
```

### 4. Get Scan Details
```http
GET /api/patrol-scan/{scan}
Authorization: Bearer {token}

Response:
{
    "success": true,
    "data": {
        "id": 1,
        "attendance_id": 1,
        "qr_code_id": 1,
        "patrol_point": {...},
        "scan_time": "2026-02-23T10:30:45Z",
        "note": "Keamanan di area ini baik",
        "photos": [
            {
                "id": 1,
                "photo": "patrol-scan-photos/...",
                "url": "http://localhost:8000/storage/patrol-scan-photos/...",
                "created_at": "2026-02-23T10:31:00Z"
            }
        ]
    }
}
```

### 5. Get Attendance Scans
```http
GET /api/attendance/{attendance}/patrol-scans
Authorization: Bearer {token}

Response:
{
    "success": true,
    "data": {
        "attendance": {...},
        "scans": [
            {
                "id": 1,
                "patrol_point": {...},
                "scan_time": "2026-02-23T10:30:45Z",
                "note": "Keamanan baik",
                "photos": [...],
                "photo_count": 1
            }
        ]
    }
}
```

### 6. Get Scan Statistics
```http
GET /api/attendance/{attendance}/patrol-scan/statistics
Authorization: Bearer {token}

Response:
{
    "success": true,
    "data": {
        "total_scans": 5,
        "total_photos": 8,
        "completion_time_minutes": 45,
        "progress": {
            "total": 5,
            "scanned": 5,
            "remaining": 0,
            "percentage": 100.0,
            "completed": true
        },
        "all_completed": true
    }
}
```

### 7. Delete Photo
```http
DELETE /api/patrol-scan/{scan}/photo/{photoId}
Authorization: Bearer {token}

Response:
{
    "success": true,
    "message": "Foto berhasil dihapus"
}
```

### 8. Download Photo
```http
GET /api/patrol-scan-photo/{photoId}/download
Authorization: Bearer {token}

Response: File download
```

---

## 🔍 Validasi & Business Logic

### PatrolScanService

#### 1. canUserScan()
Validasi user dapat perform scan:
- ✅ Attendance milik user (atau admin/dev)
- ✅ Tanggal sama dengan hari ini
- ✅ Belum check-out
- ✅ Sudah check-in
- ✅ Member: post sudah dipilih

#### 2. validateQrCode()
Validasi QR code:
- ✅ QR code ada di database
- ✅ QR code aktif (active = true)
- ✅ QR code sesuai dengan post
  - Member: QR minimal dari patrol points di post yang dipilih
  - Komandan: QR dari static post patrol point

#### 3. validateSequenceOrder()
Validasi urutan scanning (hanya untuk member):
- ✅ Harus scan sequence 1 dulu
- ✅ Tidak boleh skip sequence
- ✅ Tidak boleh double-scan point yang sama
- Komandan: N/A (hanya 1 point)

#### 4. validateLocation()
Validasi lokasi geografis:
- ✅ Distance: User harus dalam radius patrol point
  - Formula: Haversine (great-circle distance)
  - Default radius: 5 km per patrol point, bisa di-override
  - Error jika distance > radius

- ✅ Altitude: User altitude harus sesuai (opsional)
  - Tolerance: ±50 meter
  - Error jika diff > 50m

#### 5. createScan()
Create patrol scan dengan validasi:
1. Panggil canUserScan() → validasi user
2. Panggil validateQrCode() → validasi QR
3. Panggil validateSequenceOrder() → validasi urutan
4. Panggil validateLocation() → validasi lokasi
5. Jika semua valid → CREATE scan dengan transaction

#### 6. addPhoto()
Add photo ke scan:
- ✅ File harus image
- ✅ Max size 5MB
- ✅ Store di `storage/app/public/patrol-scan-photos/`

#### 7. getScanProgress()
Get progress scanning attendance:
- Return: total, scanned, remaining, percentage, completed

#### 8. isAllScansCompleted()
Check semua scanning sudah done:
- Return: boolean

---

## 📊 Flow Diagram

### Member Scanning Flow
```
1. Member Check-in
   ├─ Submit: post_type, post_name, location, foto
   ├─ Validate: location radius, time, schedule
   └─ Create: Attendance (post_id = selected post)

2. Member Scan QR (Loop: sequence 1 → N)
   ├─ Scan QR Code
   ├─ Validate QR (belongs to selected post)
   ├─ Validate Sequence (must be next sequence)
   ├─ Validate Location (within patrol point radius)
   ├─ Create: PatrolScan
   └─ Upload: Foto(s) → PatrolScanPhoto

3. Member Check-out
   └─ All scans harus completed sebelum check-out
```

### Komandan Regu Scanning Flow
```
1. Komandan Check-in
   ├─ Submit: location, foto (NO post selection)
   ├─ Validate: location radius, time, schedule
   ├─ Get: Static post otomatis
   └─ Create: Attendance (post_id = NULL)

2. Komandan Scan QR (1x saja)
   ├─ Scan QR Code (dari static post)
   ├─ Validate QR (belongs to static point)
   ├─ Validate Location (within project location radius)
   ├─ Create: PatrolScan
   └─ Upload: Foto(s) → PatrolScanPhoto

3. Komandan Check-out
   └─ Scan harus completed sebelum check-out
```

---

## 🛠️ Implementasi & Setup

### 1. Database Migrations
```bash
# Migrations sudah tersedia:
- 2026_01_16_104426_create_patrol_points_table.php
- 2026_01_16_112200_create_qr_codes_table.php
- 2026_01_17_083457_create_patrol_scans_table.php
- 2026_01_19_075116_create_patrol_scan_photos_table.php

# Run migration
php artisan migrate
```

### 2. Models Setup
Models yang sudah ada:
- `App\Models\PatrolPoint`
- `App\Models\QrCode`
- `App\Models\PatrolScan`
- `App\Models\PatrolScanPhoto`
- `App\Models\Attendance` (updated)

Relationships:
```php
PatrolPoint::hasOne(QrCode)
QrCode::hasMany(PatrolScan)
PatrolScan::hasMany(PatrolScanPhoto)
Attendance::hasMany(PatrolScan)
```

### 3. Service Registration
Service sudah available:
- `App\Services\PatrolScanService`

Inject ke controller:
```php
public function __construct(PatrolScanService $patrolScanService)
{
    $this->patrolScanService = $patrolScanService;
}
```

### 4. Policy Registration
Policies sudah ready:
- `App\Policies\PatrolScanPolicy`
- `App\Policies\PatrolScanPhotoPolicy`

Register di `AuthServiceProvider.php` jika belum:
```php
protected $policies = [
    PatrolScan::class => PatrolScanPolicy::class,
    PatrolScanPhoto::class => PatrolScanPhotoPolicy::class,
];
```

### 5. Routes
Semua routes sudah di `routes/api.php`:
```php
Route::middleware('auth:sanctum')->group(function () {
    // Patrol Scan routes
    Route::get('/attendance/{attendance}/patrol-scan/progress', ...);
    Route::get('/attendance/{attendance}/patrol-scans', ...);
    Route::get('/attendance/{attendance}/patrol-scan/statistics', ...);
    Route::post('/patrol-scan', ...);
    Route::get('/patrol-scan/{scan}', ...);
    Route::post('/patrol-scan/{scan}/photo', ...);
    Route::delete('/patrol-scan/{scan}/photo/{photoId}', ...);
    Route::get('/patrol-scan-photo/{photoId}/download', ...);
});
```

---

## 🧪 Testing & Examples

### Scenario 1: Member Scanning
```python
# 1. Get progress
GET /api/attendance/1/patrol-scan/progress
Auth: member token

# Response: 5 patrol points, 0 scanned

# 2. Perform scan (sequence 1)
POST /api/patrol-scan
{
    "attendance_id": 1,
    "qr_code": "UUID-1",
    "scan_latitude": -6.1234,
    "scan_longitude": 106.7890,
    "scan_altitude": 25.5,
    "note": "Situasi aman"
}
Auth: member token

# Response: Scan 'Gate Utama' berhasil (1/5)

# 3. Upload foto
POST /api/patrol-scan/1/photo
- photo: [image file]
Auth: member token

# Response: Foto berhasil disimpan

# 4. Continue scanning 2-5...

# 5. Verify all scanned
GET /api/attendance/1/patrol-scan/statistics
Auth: member token

# Response: all_completed = true
```

### Scenario 2: Komandan Scanning
```python
# 1. Get progress
GET /api/attendance/2/patrol-scan/progress
Auth: komandan token

# Response: 1 patrol point (static), 0 scanned

# 2. Perform scan
POST /api/patrol-scan
{
    "attendance_id": 2,
    "qr_code": "UUID-STATIC",
    "scan_latitude": -6.1234,
    "scan_longitude": 106.7890,
    "note": ""
}
Auth: komandan token

# Response: Scan 'Static Post' berhasil (1/1)

# 3. Upload foto
POST /api/patrol-scan/2/photo
- photo: [image file]
Auth: komandan token

# Response: Foto berhasil disimpan

# 4. Verify completed
GET /api/attendance/2/patrol-scan/statistics
Auth: komandan token

# Response: all_completed = true, completion_time_minutes: 5
```

---

## ⚠️ Error Handling

### Common Errors

#### Error: Invalid QR Code
```json
{
    "success": false,
    "errors": ["QR Code tidak ditemukan"]
}
```

#### Error: Wrong Sequence
```json
{
    "success": false,
    "errors": ["Anda harus scan point 'Gate Timur' terlebih dahulu (urutan 2)"]
}
```

#### Error: Out of Radius
```json
{
    "success": false,
    "errors": ["Lokasi scan terlalu jauh. Jarak: 150.23 m, Radius: 5000.00 m"]
}
```

#### Error: Altitude Mismatch
```json
{
    "success": false,
    "errors": ["Ketinggian tidak sesuai. Ketinggian expected: 25.00 m, actual: 75.50 m"]
}
```

#### Error: Already Checked Out
```json
{
    "success": false,
    "errors": ["Sudah check-out, tidak bisa melakukan scan lagi"]
}
```

---

## 🔧 Advanced Features

### 1. Altitude Validation
- Optional feature untuk validasi ketinggian lokasi
- Useful untuk multi-story buildings
- Tolerance: ±50 meter (configurable)

### 2. Photo Management
- Max 5MB per foto
- Supported formats: JPG, PNG, GIF, BMP
- Storage: `storage/app/public/patrol-scan-photos/`
- Access: `storage/{filename}` atau direct download

### 3. Scan History & Audit Trail
- Setiap scan terekam dengan timestamp
- Altitude & location coordinates tersimpan implisit via QR → PatrolPoint
- Photos as evidence
- Notes untuk dokumentasi

### 4. Completion Tracking
- Progress bar: scanned / total
- Completion time: last scan - first scan
- All scans verification sebelum checkout

---

## 📝 Best Practices

1. **Sequence Order**
   - Selalu validasi urutan scanning
   - Gunakan database unique constraint
   - Informkan user urutan berikutnya

2. **Location Validation**
   - Hitung distance real-time saat scan
   - Berikan feedback clear tentang jarak
   - Log semua location data untuk audit

3. **Photos**
   - Require minimum 1 foto per scan
   - Compress sebelum store jika diperlukan
   - Backup ke cloud untuk redundancy

4. **Authorization**
   - Selalu gunakan policies untuk check
   - Log unauthorized attempts
   - Prevent data leakage antar user

5. **Error Messages**
   - Berikan error yang jelas & actionable
   - Include detail lokasi/sequence untuk debugging
   - Gunakan Bahasa Indonesia untuk clarity

---

## 📞 Support & Troubleshooting

### Issue: User tidak bisa scan
- Check: Attendance check-in?
- Check: Post sudah dipilih (member)?
- Check: QR code aktif?

### Issue: Altitude validation gagal
- Check: Device GPS altitude accurate?
- Check: Tolerance ±50m cukup?
- Solusi: Disable altitude check jika device GPS tidak reliable

### Issue: Distance calculation wrong
- Check: Coordinates format (latitude -90 to 90, longitude -180 to 180)?
- Check: Radius setting di patrol point?
- Solusi: Gunakan Haversine formula untuk accuracy

