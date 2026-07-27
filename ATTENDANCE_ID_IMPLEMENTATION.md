# Documentation: Attendance_ID Parameter & End-Time Validation

## 🔧 Perubahan yang Telah Dilakukan

### 1. **FIX: Null Assignment Error (Line 1532)**
```php
// SEBELUM (Error):
$regularActive = $activeSchedules->filter(fn($s) => ! $s->assignment->isOffDuty())->first();
if ($regularActive) {
    $activeAssignment = $regularActive->assignment;
} else {
    $activeAssignment = $activeSchedules->first()->assignment; // ❌ Bisa null!
}

// SESUDAH (Fixed):
$regularActive = $activeSchedules->filter(fn($s) => $s->assignment && ! $s->assignment->isOffDuty())->first();
if ($regularActive) {
    $activeAssignment = $regularActive->assignment;
} else {
    $activeAssignment = $activeSchedules->filter(fn($s) => $s->assignment)->first()?->assignment; // ✅ Safe
}
```

### 2. **Tambahan: attendance_id Parameter untuk Filtering Danru**

#### Endpoint: `GET /api/attendance/progress` (progressTeamByPost)
```
Query Parameters:
- project_id: required untuk HO, auto dari user untuk lainnya
- attendance_id: OPTIONAL - filter danru tertentu (komandan_regu)
- current_time: optional - format Y-m-d H:i:s
```

**Contoh:**
```bash
# Ambil semua danru yang aktif
GET /api/attendance/progress?project_id=1

# Ambil danru dengan attendance_id=15 saja
GET /api/attendance/progress?project_id=1&attendance_id=15&current_time=2026-05-11 13:00:00
```

**Logika Filter:**
```php
$filterAttendanceId = $request->has('attendance_id') 
    ? (int) $request->input('attendance_id') 
    : null;

$commanderAttendances = $attendances->filter(function ($attendance) use ($filterAttendanceId) {
    if (!($attendance->user && $attendance->user->role === 'komandan_regu')) {
        return false;
    }
    
    // Jika attendance_id dikirim, filter hanya attendance itu
    if ($filterAttendanceId && $attendance->id !== $filterAttendanceId) {
        return false;
    }
    
    return true;
});
```

---

## 📋 End-Time Validation di Semua Endpoint

### 1. **progressTeamByPost** - Filter Danru
✅ Jika danru sudah melewati `end_time` assignment → **tidak ditampilkan**
- Support O/P (overtime) detection via `overtimeLog->workAssignment`
- Return kosong jika semua danru sudah melewati end_time

### 2. **progressPostDetail** - Filter Members
✅ Jika member sudah melewati `end_time` assignment → **tidak ditampilkan**
- Support O/P detection
- Cek sebelum include ke response

### 3. **downloadProgressPdf** - End-Time Check
✅ Jika sudah melewati `end_time` → **return 422 error**
```php
if ($nowInProjectTz->greaterThanOrEqualTo($end)) {
    return response()->json([
        'message' => 'Sesi sudah berakhir, tidak dapat download progress.',
        'end_time' => $end->toISOString(),
    ], 422);
}
```

### 4. **checkQr** - Latitude/Longitude + Radius
✅ Validasi lokasi dengan radius patrol point
✅ attendance_id wajib dikirim

### 5. **performScan** - Latitude/Longitude + Radius
✅ Validasi lokasi dengan radius patrol point
✅ Jika melampaui radius → return error dengan info jarak

---

## 🔄 Overtime (O/P) Support

Semua endpoint sekarang support logic berikut:

```php
// Tentukan assignment yang digunakan (overtime atau regular)
$assignment = null;
if ($attendance->overtimeLog && $attendance->overtimeLog->workAssignment) {
    $assignment = $attendance->overtimeLog->workAssignment; // Code: O
} else {
    $assignment = $attendance->assignment; // Code: P/M
}

// Gunakan end_time dari assignment yang tepat
$end = Carbon::createFromFormat('Y-m-d H:i:s', $schDate.' '.$assignment->end_time, $projectTimezone);
```

**Prioritas:**
1. Jika ada `overtimeLog->workAssignment` → gunakan `work_assignment->end_time`
2. Else → gunakan `assignment->end_time`

---

## 📝 Postman Collection

File: `ATTENDANCE_ID_POSTMAN.json` (di root backend)

### Import ke Postman:
1. Buka Postman
2. Click **Import**
3. Pilih **File** → select `ATTENDANCE_ID_POSTMAN.json`
4. Set variable `BASE_URL` = `http://localhost:8000`
5. Set `Bearer token` di Authorization

### Endpoints dalam Collection:

#### 1️⃣ Progress Team by Post (with attendance_id)
```
GET /api/attendance/progress?project_id=1&attendance_id=15&current_time=2026-05-11 13:00:00

Response:
{
  "message": "Progress assignment aktif berhasil diambil.",
  "project_id": 1,
  "date": "2026-05-11",
  "time": "13:00:00",
  "assignment": {...},
  "danru": [
    {
      "danru_id": 15,
      "full_name": "John Danru",
      "attendance_id": 15,
      "check_in_at": "2026-05-11T10:00:00+07:00",
      "assignment_code": "M"
    }
  ],
  "progress": {...},
  "members": [...]
}
```

#### 2️⃣ Check QR with Radius
```
POST /api/patrol-scan/check-qr

Body:
{
  "attendance_id": 15,
  "qr_code": "QR001",
  "scan_latitude": -6.123456,
  "scan_longitude": 106.789012,
  "scan_altitude": 50
}

Response:
{
  "success": true,
  "data": {
    "attendance_id": 15,
    "is_valid": true,
    "already_scanned": false,
    "is_in_radius": true,
    "distance": 12.5,
    "radius": 50,
    "remaining_patrol_points": [...]
  }
}
```

#### 3️⃣ Perform Patrol Scan
```
POST /api/patrol-scan

Body:
{
  "attendance_id": 15,
  "qr_code": "QR001",
  "scan_latitude": -6.123456,
  "scan_longitude": 106.789012,
  "scan_altitude": 50,
  "note": "Scan dari checkpoint 1",
  "current_time": "2026-05-11 13:15:00"
}

Response:
{
  "success": true,
  "message": "Scan 'Patrol Point 1' berhasil dicatat",
  "data": {
    "scan_id": 123,
    "progress": {
      "total": 10,
      "scanned": 3,
      "percentage": 30
    }
  }
}
```

#### 4️⃣ Download Progress PDF
```
GET /api/attendance/15/download-progress

Notes:
- Jika sudah melewati end_time assignment → error 422
- Support session_start & session_end untuk range tertentu
```

---

## 🚨 Error Handling

### Error 422: Sesi Sudah Berakhir
```json
{
  "message": "Sesi sudah berakhir, tidak dapat download progress.",
  "end_time": "2026-05-11T18:00:00+07:00"
}
```

### Error: Melampaui Radius
```json
{
  "success": false,
  "errors": [
    "Lokasi scan terlalu jauh. Jarak: 125.50 m, Radius: 50.00 m"
  ],
  "data": {
    "is_in_radius": false,
    "distance": 125.50,
    "radius": 50.00
  }
}
```

### Error: Null Assignment
```json
{
  "message": "Tidak ada assignment aktif saat ini.",
  "progress": {...}
}
```

---

## ✅ Testing Checklist

- [ ] Test progressTeamByPost tanpa attendance_id (ambil semua danru)
- [ ] Test progressTeamByPost dengan attendance_id (filter danru tertentu)
- [ ] Test checkQr dengan radius validation
- [ ] Test performScan dengan radius validation (jika melampaui → error)
- [ ] Test downloadProgressPdf sebelum end_time (success)
- [ ] Test downloadProgressPdf setelah end_time (error 422)
- [ ] Test dengan overtime assignment (O code)
- [ ] Test dengan regular assignment (P/M code)

---

## 📌 Database Relations untuk Reference

### Attendance
- `id`: ID attendance
- `assignment_id`: Regular assignment (P/M)
- `schedule_id`: Schedule reference
- `check_in_at`, `check_out_at`: Waktu checkin/out

### OvertimeLog
- `attendance_id`: Reference ke Attendance
- `work_assignment_id`: Overtime assignment (O/P)

### Assignment
- `start_time`, `end_time`: Waktu kerja
- `code`: P/M/O/L
- `is_off`: Boolean

---

Semua perubahan sudah di-implement dan siap di-test! 🚀
