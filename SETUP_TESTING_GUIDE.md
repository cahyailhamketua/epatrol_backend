# 🚀 Setup & Testing Guide

Panduan lengkap untuk setup dan testing fitur Attendance Scans & Offline Mode.

## 📋 Daftar Checklist Setup

- [ ] Run migrations
- [ ] Verify database tables
- [ ] Test endpoints dengan Postman
- [ ] Test offline sync workflow
- [ ] Test PDF generation
- [ ] Verify authorization policies

---

## 1️⃣ Run Database Migrations

### Step 1: Create Migrations

Dua migration files sudah dibuat:
- `2024_01_01_000001_create_patrol_sync_queue_table.php`
- `2024_01_01_000002_create_attendance_progress_snapshots_table.php`

### Step 2: Run Migrations

```bash
# Run all pending migrations
php artisan migrate

# Jika perlu rollback
php artisan migrate:rollback

# Rollback specific migration
php artisan migrate:rollback --step=2

# Fresh migration (WARNING: akan delete all data)
php artisan migrate:fresh
```

### Step 3: Verify Tables

```bash
# Check table structure
php artisan tinker
>>> DB::select('DESCRIBE patrol_sync_queues');
>>> DB::select('DESCRIBE attendance_progress_snapshots');
```

---

## 2️⃣ Verify Database Schema

### patrol_sync_queues Table

```sql
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_NAME = 'patrol_sync_queues'
ORDER BY ORDINAL_POSITION;
```

Expected columns:
- id (BIGINT)
- user_id (BIGINT)
- attendance_id (BIGINT)
- qr_code_id (BIGINT)
- qr_code (VARCHAR)
- scan_latitude (FLOAT)
- scan_longitude (FLOAT)
- scan_altitude (FLOAT)
- note (TEXT)
- scan_time_device (DATETIME)
- scan_time_utc (DATETIME)
- photo_data (JSON)
- status (ENUM)
- error_message (TEXT)
- retry_count (INT)
- last_retry_at (DATETIME)
- patrol_scan_id (BIGINT)
- created_at (TIMESTAMP)
- updated_at (TIMESTAMP)

### attendance_progress_snapshots Table

```sql
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_NAME = 'attendance_progress_snapshots'
ORDER BY ORDINAL_POSITION;
```

Expected columns:
- id (BIGINT)
- attendance_id (BIGINT)
- assignment_id (BIGINT)
- project_id (BIGINT)
- post_id (BIGINT)
- total_patrol_points (INT)
- scanned_patrol_points (INT)
- progress_percentage (DECIMAL)
- snapshot_at (DATETIME)
- scan_details (JSON)
- snapshot_type (ENUM)
- created_at (TIMESTAMP)
- updated_at (TIMESTAMP)

---

## 3️⃣ Testing dengan Postman

### Setup Postman Collection

Buat file `Attendance_Scans.postman_collection.json`:

```json
{
  "info": {
    "name": "Attendance Scans API",
    "schema": "https://schema.getpostman.com/json/collection/v2.1.0/collection.json"
  },
  "item": [
    {
      "name": "1. Get Attendance Scans",
      "request": {
        "method": "GET",
        "header": [
          {
            "key": "Authorization",
            "value": "Bearer {{token}}"
          }
        ],
        "url": {
          "raw": "{{base_url}}/api/attendances/{{attendance_id}}/scans",
          "host": ["{{base_url}}"],
          "path": ["api", "attendances", "{{attendance_id}}", "scans"]
        }
      }
    },
    {
      "name": "2. Check QR Code",
      "request": {
        "method": "POST",
        "header": [
          {
            "key": "Authorization",
            "value": "Bearer {{token}}"
          },
          {
            "key": "Content-Type",
            "value": "application/json"
          }
        ],
        "body": {
          "mode": "raw",
          "raw": "{\"qr_code\": \"PP001\"}"
        },
        "url": {
          "raw": "{{base_url}}/api/attendances/{{attendance_id}}/check-qr",
          "host": ["{{base_url}}"],
          "path": ["api", "attendances", "{{attendance_id}}", "check-qr"]
        }
      }
    },
    {
      "name": "3. Offline Scan Queue",
      "request": {
        "method": "POST",
        "header": [
          {
            "key": "Authorization",
            "value": "Bearer {{token}}"
          }
        ],
        "body": {
          "mode": "formdata",
          "formdata": [
            {
              "key": "qr_code",
              "value": "PP001"
            },
            {
              "key": "scan_latitude",
              "value": "-6.452060"
            },
            {
              "key": "scan_longitude",
              "value": "106.731170"
            },
            {
              "key": "scan_altitude",
              "value": "10.5"
            },
            {
              "key": "note",
              "value": "Kondisi normal"
            },
            {
              "key": "current_time",
              "value": "2024-04-30 10:30:45"
            },
            {
              "key": "photos",
              "type": "file",
              "src": []
            }
          ]
        },
        "url": {
          "raw": "{{base_url}}/api/attendances/{{attendance_id}}/offline-scan",
          "host": ["{{base_url}}"],
          "path": ["api", "attendances", "{{attendance_id}}", "offline-scan"]
        }
      }
    },
    {
      "name": "4. Sync Offline Scans",
      "request": {
        "method": "POST",
        "header": [
          {
            "key": "Authorization",
            "value": "Bearer {{token}}"
          }
        ],
        "url": {
          "raw": "{{base_url}}/api/attendances/sync-offline-scans",
          "host": ["{{base_url}}"],
          "path": ["api", "attendances", "sync-offline-scans"]
        }
      }
    },
    {
      "name": "5. Get Sync Status",
      "request": {
        "method": "GET",
        "header": [
          {
            "key": "Authorization",
            "value": "Bearer {{token}}"
          }
        ],
        "url": {
          "raw": "{{base_url}}/api/attendances/sync-status",
          "host": ["{{base_url}}"],
          "path": ["api", "attendances", "sync-status"]
        }
      }
    },
    {
      "name": "6. Download Progress PDF",
      "request": {
        "method": "GET",
        "header": [
          {
            "key": "Authorization",
            "value": "Bearer {{token}}"
          }
        ],
        "url": {
          "raw": "{{base_url}}/api/attendances/{{attendance_id}}/progress/pdf",
          "host": ["{{base_url}}"],
          "path": ["api", "attendances", "{{attendance_id}}", "progress/pdf"]
        }
      }
    }
  ],
  "variable": [
    {
      "key": "base_url",
      "value": "http://localhost:8000"
    },
    {
      "key": "token",
      "value": ""
    },
    {
      "key": "attendance_id",
      "value": "123"
    }
  ]
}
```

### Postman Variables Setup

```
base_url     : http://localhost:8000
token        : [Dari login response]
attendance_id: [ID attendance yang aktif]
```

---

## 4️⃣ Test Cases

### Test Case 1: Get Attendance Scans

```bash
# Pre-requisite: User sudah check-in

# 1. Get scans
curl -X GET "http://localhost:8000/api/attendances/123/scans" \
  -H "Authorization: Bearer {token}"

# Expected:
# - Status: 200
# - patrol_points array dengan structure lengkap
# - progress menunjukkan scanned/total points
```

### Test Case 2: Check QR Code

```bash
# 1. Check valid QR code
curl -X POST "http://localhost:8000/api/attendances/123/check-qr" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{"qr_code": "PP001"}'

# Expected:
# - Status: 200
# - success: true
# - data.post.name: "Post Name"
# - data.patrol_point: patrol point details

# 2. Check invalid QR code
curl -X POST "http://localhost:8000/api/attendances/123/check-qr" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{"qr_code": "INVALID"}'

# Expected:
# - Status: 422
# - success: false
# - errors: array of error messages
```

### Test Case 3: Offline Scan Queue

```bash
# 1. Queue offline scan
curl -X POST "http://localhost:8000/api/attendances/123/offline-scan" \
  -H "Authorization: Bearer {token}" \
  -F "qr_code=PP001" \
  -F "scan_latitude=-6.452060" \
  -F "scan_longitude=106.731170" \
  -F "scan_altitude=10.5" \
  -F "note=Kondisi baik" \
  -F "current_time=2024-04-30 10:30:45" \
  -F "photos=@photo1.jpg" \
  -F "is_offline=true"

# Expected:
# - Status: 201
# - success: true
# - sync_queue_id: [ID dari queue]
# - status: "pending"

# 2. Verify queue created
php artisan tinker
>>> DB::table('patrol_sync_queues')->latest()->first();
```

### Test Case 4: Sync Offline Scans

```bash
# 1. Sync pending scans
curl -X POST "http://localhost:8000/api/attendances/sync-offline-scans" \
  -H "Authorization: Bearer {token}"

# Expected:
# - Status: 200
# - success: true
# - synced_count: [number]
# - failed_count: [number]

# 2. Verify synced
php artisan tinker
>>> DB::table('patrol_sync_queues')->where('status', 'synced')->count();
```

### Test Case 5: Get Sync Status

```bash
# 1. Get sync status
curl -X GET "http://localhost:8000/api/attendances/sync-status" \
  -H "Authorization: Bearer {token}"

# Expected:
# - Status: 200
# - success: true
# - data.pending_count: number
# - data.synced_count: number
# - data.last_sync_at: timestamp
```

### Test Case 6: Download Progress PDF

```bash
# 1. Download latest progress
curl -X GET "http://localhost:8000/api/attendances/123/progress/pdf" \
  -H "Authorization: Bearer {token}" \
  -o progress.pdf

# Expected:
# - Status: 200
# - Content-Type: application/pdf
# - File saved as progress.pdf

# 2. Download session-specific PDF
curl -X GET "http://localhost:8000/api/attendances/123/progress/pdf?session_start=2024-04-30%2008:00:00&session_end=2024-04-30%2016:00:00" \
  -H "Authorization: Bearer {token}" \
  -o progress-session.pdf

# 3. Verify PDF content
# - Organisasi name
# - Project name
# - Petugas name
# - Patrol points list
# - Scanned photos
# - Timestamps
```

---

## 5️⃣ Offline Sync Workflow Test

### Scenario: User Offline, Scan Multiple Points, Sync Online

```bash
# Step 1: User check-in (normal)
curl -X POST "http://localhost:8000/api/attendances/check-in" \
  -H "Authorization: Bearer {token}" \
  -F "latitude=-6.452060" \
  -F "longitude=106.731170" \
  -F "current_time=2024-04-30 08:00:00" \
  -F "selfie_photo=@selfie.jpg"

# Result: attendance_id = 123

# Step 2: Device goes OFFLINE
# Simulation: Network disconnected

# Step 3: Queue multiple scans offline
for i in {1..5}; do
  curl -X POST "http://localhost:8000/api/attendances/123/offline-scan" \
    -H "Authorization: Bearer {token}" \
    -F "qr_code=PP00$i" \
    -F "scan_latitude=-6.452$i" \
    -F "scan_longitude=106.731$i" \
    -F "current_time=2024-04-30 10:3$i:45" \
    -F "photos=@photo$i.jpg" \
    -F "is_offline=true"
done

# Result: 5 pending scans in patrol_sync_queues

# Step 4: Verify pending syncs
curl -X GET "http://localhost:8000/api/attendances/sync-status" \
  -H "Authorization: Bearer {token}"

# Result:
# {
#   "pending_count": 5,
#   "synced_count": 0,
#   "last_sync_at": null
# }

# Step 5: Device goes ONLINE
# Simulation: Network reconnected

# Step 6: Sync pending scans
curl -X POST "http://localhost:8000/api/attendances/sync-offline-scans" \
  -H "Authorization: Bearer {token}"

# Result:
# {
#   "synced_count": 5,
#   "failed_count": 0
# }

# Step 7: Verify all scans synced
curl -X GET "http://localhost:8000/api/attendances/sync-status" \
  -H "Authorization: Bearer {token}"

# Result:
# {
#   "pending_count": 0,
#   "synced_count": 5,
#   "last_sync_at": "2024-04-30T10:40:00+07:00"
# }

# Step 8: Download PDF dengan semua scans
curl -X GET "http://localhost:8000/api/attendances/123/progress/pdf" \
  -H "Authorization: Bearer {token}" \
  -o progress-full.pdf

# Result: PDF berisi 5 scans dengan fotos
```

---

## 6️⃣ Authorization Testing

### Test Unauthorized Access

```bash
# Test 1: User lain tidak bisa lihat scans
curl -X GET "http://localhost:8000/api/attendances/123/scans" \
  -H "Authorization: Bearer {other_user_token}"

# Expected: 403 Forbidden

# Test 2: User lain tidak bisa download PDF
curl -X GET "http://localhost:8000/api/attendances/123/progress/pdf" \
  -H "Authorization: Bearer {other_user_token}"

# Expected: 403 Forbidden

# Test 3: Admin project bisa akses
curl -X GET "http://localhost:8000/api/attendances/123/scans" \
  -H "Authorization: Bearer {admin_project_token}"

# Expected: 200 OK (jika di project yang sama)

# Test 4: HO bisa akses
curl -X GET "http://localhost:8000/api/attendances/123/progress/pdf" \
  -H "Authorization: Bearer {ho_token}"

# Expected: 200 OK (jika di organization yang sama)
```

---

## 7️⃣ PDF Generation Testing

### Test PDF Contents

```bash
# 1. Generate PDF
curl -X GET "http://localhost:8000/api/attendances/123/progress/pdf" \
  -H "Authorization: Bearer {token}" \
  -o progress.pdf

# 2. Verify PDF structure (using pdftotext tool)
pdftotext progress.pdf - | head -30

# Expected output:
# 📋 LAPORAN PROGRESS PATROL SCAN
# Organisasi     : PT. Maju Jaya
# Proyek         : Security Project
# Tanggal        : 30/04/2024 10:30:45
# Petugas        : Ahmad Perdana
# Jabatan        : Komandan Regu
# 
# PROGRESS: 5/10 Titik Patroli Selesai (50%)
# 
# 🚩 Post A - Front Gate
#   📍 Pintu Utama
#    ✓ 30/04/2024 10:15:30 (Ahmad Perdana)
#     💬 Kondisi baik

# 3. Verify photos included (using pdfimages)
pdfimages -list progress.pdf

# Expected: 4+ images per scan entry
```

---

## 8️⃣ Performance & Load Testing

### Bulk Offline Scan Test

```bash
# Queue 100 offline scans
php artisan tinker

# Script untuk queue bulk scans
>>> use App\Models\Attendance;
>>> use App\Services\OfflineSyncService;
>>> 
>>> $attendance = Attendance::first();
>>> $service = app(OfflineSyncService::class);
>>> 
>>> for ($i = 1; $i <= 100; $i++) {
>>>   $service->queueOfflineScan(
>>>     $attendance,
>>>     "PP" . str_pad($i, 3, "0", STR_PAD_LEFT),
>>>     -6.452 + ($i / 10000),
>>>     106.731 + ($i / 10000),
>>>     null,
>>>     "Batch test scan #$i",
>>>     now(),
>>>     []
>>>   );
>>> }

# Count queued
>>> DB::table('patrol_sync_queues')->count();
# Expected: 100

# Sync all
>>> $result = $service->syncPendingScans();
>>> dd($result);

# Check sync performance
>>> DB::table('patrol_sync_queues')
...   ->where('status', 'synced')
...   ->avg('retry_count');
```

---

## ⚠️ Troubleshooting

### Issue 1: Migration gagal

```bash
# Check migration status
php artisan migrate:status

# Rollback to specific point
php artisan migrate:rollback --step=2

# Rerun migrations
php artisan migrate
```

### Issue 2: PDF tidak generate

```bash
# Check DomPDF installation
composer require barryvdh/laravel-dompdf

# Check view file exists
ls resources/views/pdf/patrol-progress.blade.php

# Test view render
php artisan tinker
>>> view('pdf.patrol-progress', [])->render();
```

### Issue 3: Offline sync tidak berjalan

```bash
# Check queue status
php artisan tinker
>>> DB::table('patrol_sync_queues')->where('status', '!=', 'synced')->get();

# Check error message
>>> DB::table('patrol_sync_queues')
...   ->where('status', 'failed')
...   ->pluck('error_message');

# Retry failed
>>> use App\Services\OfflineSyncService;
>>> $service = app(OfflineSyncService::class);
>>> $service->syncPendingScans();
```

---

## 📊 Monitoring & Logs

```bash
# Check application logs
tail -f storage/logs/laravel.log

# Monitor offline sync activity
php artisan tinker
>>> DB::table('patrol_sync_queues')
...   ->where('updated_at', '>', now()->subHours(1))
...   ->get(['id', 'status', 'retry_count', 'updated_at']);

# Check PDF generation errors
grep -i "pdf" storage/logs/laravel.log | tail -20
```

---

## ✅ Final Verification Checklist

- [ ] Migrations run successfully
- [ ] Database tables created
- [ ] All 6 endpoints accessible
- [ ] Offline scan queuing works
- [ ] Sync completes successfully
- [ ] PDF generation works
- [ ] Authorization policies enforced
- [ ] Performance acceptable (< 2s per request)
- [ ] No errors in logs
