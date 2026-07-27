# 📋 API Endpoints - Attendance Scans & Offline Mode

Dokumentasi lengkap untuk semua endpoint yang berhubungan dengan attendance scans dan fitur offline-first.

## 🔍 Overview

Implementasi ini mencakup:
1. **Get Attendance Scans** - Menampilkan list patrol points yang harus di-scan
2. **Offline Sync** - Queue dan sync scans saat offline/online
3. **QR Code Validation** - Check QR code dengan response post_name
4. **Progress Tracking** - Track history dengan reset per assignment
5. **PDF Export** - Download laporan progress dengan foto

---

## 📌 1. GET ATTENDANCE SCANS

Menampilkan list patrol points yang harus di-scan beserta statusnya.
Untuk danru: menampilkan semua static posts di project.
Untuk member: menampilkan post spesifik yang dipilih.

### Request

```
GET /api/attendances/{attendance}/scans
Authorization: Bearer {token}
```

### Parameters

- `attendance` (path): ID attendance yang sedang aktif

### Response (200 OK)

```json
{
  "success": true,
  "message": "Data patrol points berhasil diambil.",
  "data": {
    "attendance_id": 123,
    "user": {
      "id": 5,
      "name": "Ahmad Perdana",
      "role": "komandan_regu"
    },
    "timezone": "Asia/Jakarta",
    "patrol_points": [
      {
        "id": 5,
        "name": "luar 1",
        "sequence_order": 1,
        "post_id": 1,
        "post_name": "Post A",
        "post_type": "static",
        "latitude": "-6.4520600",
        "longitude": "106.7311700",
        "altitude": "10.5",
        "radius": 50.0,
        "is_scanned": false,
        "scanned_count": 0,
        "last_scan_time": null,
        "last_scan_user": null,
        "last_scan_note": null
      },
      {
        "id": 6,
        "name": "luar2",
        "sequence_order": 2,
        "post_id": 1,
        "post_name": "Post A",
        "post_type": "static",
        "latitude": "-6.4520600",
        "longitude": "106.7311700",
        "altitude": null,
        "radius": 50.0,
        "is_scanned": true,
        "scanned_count": 1,
        "last_scan_time": "2024-04-30T10:30:45+07:00",
        "last_scan_user": "Ahmad Perdana",
        "last_scan_note": "Kondisi normal"
      }
    ],
    "progress": {
      "total_points": 2,
      "scanned_points": 1,
      "remaining_points": 1,
      "percentage": 50.0
    }
  }
}
```

### Error Response (400/403)

```json
{
  "message": "Attendance tidak valid atau sudah check-out."
}
```

---

## 🔄 2. OFFLINE SCAN QUEUE

Queue patrol scan untuk disimpan locally dan disync saat online.
Professional offline-first approach untuk reliability maksimal.

### Request

```
POST /api/attendances/{attendance}/offline-scan
Authorization: Bearer {token}
Content-Type: multipart/form-data
```

### Parameters

- `qr_code` (string): QR code string (required jika tidak ada patrol_point_id)
- `patrol_point_id` (integer): Patrol point ID (required jika tidak ada qr_code)
- `scan_latitude` (float): Latitude lokasi scan (-90 to 90)
- `scan_longitude` (float): Longitude lokasi scan (-180 to 180)
- `scan_altitude` (float, optional): Altitude lokasi scan
- `note` (string, optional): Catatan tambahan (max 500 chars)
- `current_time` (string): Device time dalam format Y-m-d H:i:s
- `photos` (array): Maksimal 10 foto (max 5MB per foto)
- `is_offline` (boolean, optional): Flag menunjukkan mode offline

### Example Request

```bash
curl -X POST http://localhost:8000/api/attendances/123/offline-scan \
  -H "Authorization: Bearer {token}" \
  -F "qr_code=PP001" \
  -F "scan_latitude=-6.452060" \
  -F "scan_longitude=106.731170" \
  -F "scan_altitude=10.5" \
  -F "note=Kondisi baik, tidak ada gangguan" \
  -F "current_time=2024-04-30 10:30:45" \
  -F "photos=@photo1.jpg" \
  -F "photos=@photo2.jpg" \
  -F "is_offline=true"
```

### Response (201 Created)

```json
{
  "success": true,
  "message": "Scan berhasil disimpan offline. Akan disync otomatis saat online.",
  "sync_queue_id": 456,
  "status": "pending"
}
```

### Error Response (422)

```json
{
  "success": false,
  "message": "Attendance tidak valid untuk sync offline"
}
```

---

## 🔗 3. SYNC OFFLINE SCANS

Sync semua pending offline scans saat device sudah online.
Automatic retry dengan max 3 attempts per item.

### Request

```
POST /api/attendances/sync-offline-scans
Authorization: Bearer {token}
```

### Response (200 OK)

```json
{
  "success": true,
  "message": "Sync selesai. 5 berhasil, 1 gagal.",
  "synced_count": 5,
  "failed_count": 1
}
```

---

## 📊 4. GET SYNC STATUS

Cek status pending sync untuk current user.

### Request

```
GET /api/attendances/sync-status
Authorization: Bearer {token}
```

### Response (200 OK)

```json
{
  "success": true,
  "data": {
    "pending_count": 2,
    "synced_count": 15,
    "last_sync_at": "2024-04-30T10:35:20+07:00"
  }
}
```

---

## ✅ 5. CHECK QR CODE

Validate QR code sebelum melakukan scan.
Response includes post_name dan patrol point details.

### Request

```
POST /api/attendances/{attendance}/check-qr
Authorization: Bearer {token}
Content-Type: application/json
```

### Body

```json
{
  "qr_code": "PP001"
}
```

### Response (200 OK)

```json
{
  "success": true,
  "message": "QR code valid.",
  "data": {
    "qr_code_id": 10,
    "qr_code": "PP001",
    "patrol_point": {
      "id": 5,
      "name": "luar 1",
      "sequence_order": 1,
      "latitude": "-6.452060",
      "longitude": "106.731170",
      "radius": 50.0
    },
    "post": {
      "id": 1,
      "name": "Post A - Front Gate",
      "type": "static"
    },
    "previous_scan_count": 0,
    "already_scanned": false
  }
}
```

### Error Response (422)

```json
{
  "success": false,
  "message": "QR code tidak valid atau sudah expired",
  "errors": [
    "QR code tidak ditemukan",
    "Patrol point tidak aktif"
  ]
}
```

---

## 📥 6. DOWNLOAD PROGRESS PDF

Download laporan progress patrol scan dalam format PDF.
Includes: nama post, patrol points, foto scan, timestamp, user yang scan.

### Request

```
GET /api/attendances/{attendance}/progress/pdf
Authorization: Bearer {token}
```

### Query Parameters (Optional)

- `snapshot_id` (integer): Download specific snapshot
- `session_start` (string): Y-m-d H:i:s (untuk download session spesifik)
- `session_end` (string): Y-m-d H:i:s (untuk download session spesifik)

### Example URLs

```
# Download latest progress
GET /api/attendances/123/progress/pdf

# Download specific session
GET /api/attendances/123/progress/pdf?session_start=2024-04-30%2008:00:00&session_end=2024-04-30%2016:00:00

# Download specific snapshot
GET /api/attendances/123/progress/pdf?snapshot_id=789
```

### Response (200 OK)

- Content-Type: application/pdf
- Content-Disposition: attachment; filename="progress-123-2024-04-30-10-30-45.pdf"

### PDF Contents

```
┌─────────────────────────────────────────────────────────┐
│            📋 LAPORAN PROGRESS PATROL SCAN              │
├─────────────────────────────────────────────────────────┤
│ Organisasi    : PT. Maju Jaya                          │
│ Proyek        : Security Project - Jakarta             │
│ Tanggal       : 30/04/2024 10:30:45                    │
│                                                         │
│ Petugas       : Ahmad Perdana                          │
│ Jabatan       : Komandan Regu (Danru)                  │
├─────────────────────────────────────────────────────────┤
│ PROGRESS: 12/20 Titik Patroli Selesai (60%)           │
├─────────────────────────────────────────────────────────┤
│                                                         │
│ 🚩 Post A - Front Gate (Static)                        │
│   ├─ 📍 Pintu Utama (Urutan: 1)                       │
│   │  ├─ ✓ 2024-04-30 08:15:30 (Ahmad Perdana)        │
│   │  │  💬 Kondisi baik, tidak ada gangguan          │
│   │  │  [Foto 1] [Foto 2]                            │
│   │  └─ ✓ 2024-04-30 12:15:30 (Ahmad Perdana)        │
│   │     💬 Sudah di-maintenance                      │
│   │     [Foto 1] [Foto 2]                            │
│   └─ 📍 Pintu Samping (Urutan: 2)                    │
│      └─ Belum di-scan                                │
│                                                         │
│ 🚩 Post B - Back Gate (Static)                        │
│   └─ 📍 Pintu Belakang (Urutan: 1)                   │
│      └─ ✓ 2024-04-30 09:30:15 (Ahmad Perdana)        │
│         💬 Normal                                     │
│         [Foto 1] [Foto 2]                            │
│                                                         │
├─────────────────────────────────────────────────────────┤
│ Laporan dibuat: 30/04/2024 10:30:45                   │
│ Sistem E-Patrol © 2024                                │
└─────────────────────────────────────────────────────────┘
```

---

## 🔐 Authorization & Policies

### viewScans
- Owner (attendance user)
- Admin Project (untuk project yang sama)
- Dev (all)

### downloadProgressPdf
- Owner (attendance user)
- Admin Project (untuk project yang sama)
- HO (untuk organization yang sama)
- Dev (all)

---

## 📦 Database Schema

### patrol_sync_queues

```sql
CREATE TABLE patrol_sync_queues (
  id BIGINT PRIMARY KEY,
  user_id BIGINT,
  attendance_id BIGINT,
  qr_code_id BIGINT,
  qr_code VARCHAR(255),
  scan_latitude FLOAT,
  scan_longitude FLOAT,
  scan_altitude FLOAT,
  note TEXT,
  scan_time_device DATETIME,
  scan_time_utc DATETIME,
  photo_data JSON,
  status ENUM('pending', 'synced', 'failed', 'processing'),
  error_message TEXT,
  retry_count INT,
  last_retry_at DATETIME,
  patrol_scan_id BIGINT,
  created_at TIMESTAMP,
  updated_at TIMESTAMP
);
```

### attendance_progress_snapshots

```sql
CREATE TABLE attendance_progress_snapshots (
  id BIGINT PRIMARY KEY,
  attendance_id BIGINT,
  assignment_id BIGINT,
  project_id BIGINT,
  post_id BIGINT,
  total_patrol_points INT,
  scanned_patrol_points INT,
  progress_percentage DECIMAL(5,2),
  snapshot_at DATETIME,
  scan_details JSON,
  snapshot_type ENUM('session_start', 'session_end', 'manual_reset'),
  created_at TIMESTAMP,
  updated_at TIMESTAMP
);
```

---

## 🎯 Usage Examples

### Scenario 1: Member Offline Patrol Scan

```bash
# 1. Get patrol points untuk current attendance
curl -X GET "http://localhost:8000/api/attendances/123/scans" \
  -H "Authorization: Bearer {token}"

# 2. Check QR code sebelum scan
curl -X POST "http://localhost:8000/api/attendances/123/check-qr" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{"qr_code": "PP001"}'

# 3. Queue offline scan (jika offline)
curl -X POST "http://localhost:8000/api/attendances/123/offline-scan" \
  -H "Authorization: Bearer {token}" \
  -F "qr_code=PP001" \
  -F "scan_latitude=-6.452060" \
  -F "scan_longitude=106.731170" \
  -F "current_time=2024-04-30 10:30:45" \
  -F "photos=@photo1.jpg" \
  -F "photos=@photo2.jpg" \
  -F "is_offline=true"

# 4. Sync saat online
curl -X POST "http://localhost:8000/api/attendances/sync-offline-scans" \
  -H "Authorization: Bearer {token}"
```

### Scenario 2: Download Progress Report

```bash
# Download latest progress
curl -X GET "http://localhost:8000/api/attendances/123/progress/pdf" \
  -H "Authorization: Bearer {token}" \
  -o progress-report.pdf

# Download specific session
curl -X GET "http://localhost:8000/api/attendances/123/progress/pdf?session_start=2024-04-30%2008:00:00&session_end=2024-04-30%2016:00:00" \
  -H "Authorization: Bearer {token}" \
  -o progress-session.pdf
```

---

## 🔧 Configuration

### Environment Variables

```env
# PDF Generation
PDF_PAPER_SIZE=A4
PDF_MARGIN_TOP=10
PDF_MARGIN_BOTTOM=10
PDF_MARGIN_LEFT=10
PDF_MARGIN_RIGHT=10
PDF_DPI=96

# Offline Sync
OFFLINE_SYNC_RETRY_MAX=3
OFFLINE_SYNC_RETRY_DELAY=60 # seconds
```

---

## ⚠️ Error Codes

| Code | Message | Meaning |
|------|---------|---------|
| 400  | Attendance tidak valid | Attendance tidak checked-in atau sudah checked-out |
| 403  | Unauthorized | User tidak memiliki permission untuk resource ini |
| 404  | Not Found | Attendance atau QR code tidak ditemukan |
| 422  | Validation Error | Input validation gagal |
| 500  | Server Error | Internal server error saat generate PDF |

---

## 📝 Notes

1. **Offline-First Design**: Semua operasi scan bisa dilakukan offline dan akan disync otomatis
2. **Atomicity**: Setiap sync menggunakan transaction untuk ensure data consistency
3. **Retry Logic**: Failed syncs akan di-retry max 3 kali sebelum ditandai gagal
4. **Progress History**: History di-track tapi progress di-reset per assignment
5. **PDF Generation**: Menggunakan DomPDF dengan support untuk multiple posts dan photos
