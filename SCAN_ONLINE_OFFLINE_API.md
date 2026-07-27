# Dokumentasi Scan Online & Offline

Dokumen ini merangkum alur scan patroli online dan offline yang aktif saat ini, termasuk endpoint baru yang sudah diseragamkan pada namespace `/api/patrol-scan/*`.

## Ringkasan Endpoint

Semua endpoint butuh header:

`Authorization: Bearer {token}`

### Online Scan

- `POST /api/patrol-scan/check-qr`  
  Validasi QR berdasarkan `attendance_id` + `qr_code`.
- `POST /api/patrol-scan`  
  Simpan scan online langsung ke tabel patrol scan.

### Offline Scan (Namespace Baru - Direkomendasikan)

- `POST /api/patrol-scan/offline`  
  Queue scan offline (pakai `attendance_id` di body).
- `POST /api/patrol-scan/sync-offline`  
  Sinkronkan queue offline milik user login.

### Offline Scan (Legacy - Tetap Didukung)

- `POST /api/attendances/{attendance}/offline-scan`
- `POST /api/attendances/sync-offline-scans`

> Catatan: endpoint legacy tetap hidup untuk backward compatibility FE lama.

---

## 1) Online Scan

### 1.1 Check QR

`POST /api/patrol-scan/check-qr`

Body JSON:

```json
{
  "attendance_id": 123,
  "qr_code": "PP001"
}
```

Response sukses (contoh ringkas):

```json
{
  "success": true,
  "data": {
    "attendance_id": 123,
    "is_valid": true,
    "already_scanned": false,
    "scan_progress": {
      "total_patrol_points": 10,
      "scanned_patrol_points": 4
    }
  }
}
```

### 1.2 Perform Scan Online

`POST /api/patrol-scan`  
`Content-Type: multipart/form-data`

Field wajib:

- `attendance_id` (integer)
- `qr_code` (string)
- `scan_latitude` (float)
- `scan_longitude` (float)
- `current_time` (`Y-m-d H:i:s`)

Field opsional:

- `scan_altitude` (float)
- `note` (string, max 500)
- `photos[]` (file image)

Response sukses:

```json
{
  "success": true,
  "message": "Scan berhasil disimpan.",
  "data": {
    "scan_id": 999,
    "progress": {
      "total_patrol_points": 10,
      "scanned_patrol_points": 5
    }
  }
}
```

---

## 2) Offline Scan

## 2.1 Queue Offline (Endpoint Baru)

`POST /api/patrol-scan/offline`  
`Content-Type: multipart/form-data`

Field wajib:

- `attendance_id` (integer, attendance aktif)
- `qr_code` **atau** `patrol_point_id`
- `scan_latitude`
- `scan_longitude`
- `current_time` (`Y-m-d H:i:s`)

Field opsional:

- `scan_altitude`
- `note` (max 500)
- `photos[]` (max 10 file, max 5MB/file)
- `is_offline` (boolean)

Response sukses:

```json
{
  "success": true,
  "message": "Scan berhasil disimpan offline. Akan disync otomatis saat online.",
  "sync_queue_id": 456,
  "status": "pending",
  "data": {
    "attendance_id": 123,
    "project_name": "Project A",
    "note": "Area aman",
    "qr_code": "PP001",
    "queued_at": "2026-04-30T10:20:00Z"
  }
}
```

## 2.2 Sync Offline

`POST /api/patrol-scan/sync-offline`

Response sukses:

```json
{
  "success": true,
  "message": "Sync selesai. 3 berhasil, 0 gagal.",
  "synced_count": 3,
  "failed_count": 0
}
```

Perilaku penting:

- Sync hanya memproses queue milik user login.
- Proses sync tetap menggunakan validasi scan yang sama dengan online scan (`PatrolScanService`).

---

## 3) Urutan Integrasi FE (Disarankan)

1. Panggil `POST /api/patrol-scan/check-qr`.
2. Jika online stabil, kirim ke `POST /api/patrol-scan`.
3. Jika offline/tidak stabil, kirim ke `POST /api/patrol-scan/offline`.
4. Saat internet kembali, trigger `POST /api/patrol-scan/sync-offline`.

---

## 4) Error Umum

- `400` attendance tidak aktif / sudah checkout
- `403` tidak punya akses attendance terkait
- `404` attendance/QR tidak ditemukan
- `422` validasi field gagal
- `423` scan sedang diproses lock concurrency
- `500` internal server error

---

## 5) Catatan PDF Progress

Export progress PDF sekarang sudah membawa data:

- `project_name`
- `note` per scan

dan sudah diperbaiki agar tidak error pada proses grouping data scan.
