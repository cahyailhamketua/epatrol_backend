# Testing Patrol Scan Lintas Malam

Berdasarkan response check-in Anda:
- **User ID**: 17
- **Attendance ID**: 47
- **Date**: 2026-04-01
- **Assignment**: "malam 3" (19:00 - 07:00) ✓ Lintas Malam
- **Post**: Patroli luar (mobile)
- **Check-in**: 07:10:30
- **Project**: bujp project 1
- **Timezone**: Asia/Jakarta

## Cara Testing Lintas Malam

### Scenario 1: Scan pada malam hari (01 April 2026, jam 23:30)
Scan ini HARUS VALID karena masih pada hari yang sama dengan attendance.

```bash
curl -X POST http://localhost:8000/api/patrol-scan \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "attendance_id": 47,
    "qr_code": "PATROL-POINT-001",
    "scan_latitude": -6.1234,
    "scan_longitude": 106.7890,
    "scan_altitude": 25.5,
    "note": "Scan malam hari",
    "current_time": "2026-04-01 23:30:00"
  }'
```

**Expected Response**: ✅ Status 201 (Created)
```json
{
  "success": true,
  "message": "Patrol scan berhasil disimpan",
  "data": {
    "scan": { ... },
    "patrol_point": { ... },
    "progress": { ... }
  }
}
```

---

### Scenario 2: Scan pada pagi hari (02 April 2026, jam 03:00)
Scan ini HARUS VALID karena:
- Shift lintas malam (end_time 07:00 > current time 03:00)
- Masih dalam window lintas malam

```bash
curl -X POST http://localhost:8000/api/patrol-scan \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "attendance_id": 47,
    "qr_code": "PATROL-POINT-002",
    "scan_latitude": -6.1234,
    "scan_longitude": 106.7890,
    "scan_altitude": 25.5,
    "note": "Scan pagi lintas malam",
    "current_time": "2026-04-02 03:00:00"
  }'
```

**Expected Response**: ✅ Status 201 (Created)

---

### Scenario 3: Scan pada pagi hari AFTER END TIME (02 April 2026, jam 08:00)
Scan ini HARUS INVALID karena sudah melampaui jam end_time (07:00)

```bash
curl -X POST http://localhost:8000/api/patrol-scan \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "attendance_id": 47,
    "qr_code": "PATROL-POINT-003",
    "scan_latitude": -6.1234,
    "scan_longitude": 106.7890,
    "scan_altitude": 25.5,
    "note": "Scan setelah shift berakhir",
    "current_time": "2026-04-02 08:00:00"
  }'
```

**Expected Response**: ❌ Status 422 (Validation Error)
```json
{
  "success": false,
  "errors": [
    "Scan hanya bisa dilakukan hingga jam 07:00 pada hari berikutnya"
  ]
}
```

---

## Test Tanpa attendance_id (Auto-detect dari token)

Jika tidak mengirim `attendance_id`, sistem akan auto-detect attendance aktif terbaru dari user yang ter-login:

```bash
curl -X POST http://localhost:8000/api/patrol-scan \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "qr_code": "PATROL-POINT-001",
    "scan_latitude": -6.1234,
    "scan_longitude": 106.7890,
    "current_time": "2026-04-01 23:30:00"
  }'
```

**Note**: Pastikan user yang ter-login adalah user_id 17

---

## Common Issues & Solutions

### Error: "Scan hanya bisa dilakukan hingga jam 07:00 pada hari berikutnya"
**Penyebab**: Anda scan di luar window lintas malam  
**Solusi**: Pastikan `current_time` harus:
- Pada hari yang sama dengan attendance date (2026-04-01), ATAU
- Pada hari berikutnya (2026-04-02) tapi SEBELUM jam 07:00

### Error: "Hanya bisa scan pada hari yang sama dengan jadwal"
**Penyebab**: Shift bukan lintas malam  
**Solusi**: Periksa `assignment->start_time` dan `assignment->end_time`
- Jika end_time > start_time → shift biasa (tidak lintas malam)
- Jika end_time <= start_time → shift lintas malam ✓

### Error: "Attendance tidak valid"
**Penyebab**: User sudah check-out atau belum check-in  
**Solusi**: Pastikan:
- `check_in_at` sudah ada
- `check_out_at` masih NULL

---

## Field Requirements

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| attendance_id | integer | No | Jika kosong, auto-detect dari token |
| qr_code | string | Yes | UUID atau kode QR patrol point |
| scan_latitude | float | Yes | Range: -90 sampai 90 |
| scan_longitude | float | Yes | Range: -180 sampai 180 |
| scan_altitude | float | No | Ketinggian dalam meter |
| note | string | No | Max 500 karakter |
| current_time | string | Yes | Format: "Y-m-d H:i:s" (24-hour) |
| photos | file | No | Multiple files allowed |

---

## Response Data

### Success (201 Created)
```json
{
  "success": true,
  "message": "Patrol scan berhasil disimpan",
  "data": {
    "scan": {
      "id": 1,
      "attendance_id": 47,
      "qr_code_id": 2,
      "scan_time": "2026-04-01T23:30:00Z",
      "note": "...",
      "photos": [...]
    },
    "patrol_point": {
      "id": 1,
      "name": "Point A",
      "latitude": -6.1234,
      "longitude": 106.7890
    },
    "progress": {
      "total_points": 5,
      "scanned": 1,
      "remaining": 4,
      "percentage": 20
    },
    "validation_warnings": []
  }
}
```

### Error (422 Unprocessable Entity)
```json
{
  "success": false,
  "errors": [
    "Error message 1",
    "Error message 2"
  ]
}
```
