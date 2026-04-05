# 🛣️ ROUTES - ATTENDANCE & SCHEDULE

## **ROUTES YANG SUDAH DITAMBAHKAN**

### **Attendance Routes**
```php
GET  /api/attendances                   → List with filters
POST /api/attendances/check-in          → Check-in
POST /api/attendances/check-out         → Check-out
POST /api/attendances/patrol-scan       → Patrol scan (mobile post)
GET  /api/attendances/{attendance}      → View detail
```

### **Schedule Routes (Sudah Ada)**
```php
GET  /api/schedules                              → List all
GET  /api/users/{user}/schedules                 → List by user (GUNAKAN INI!)
GET  /api/users/{user}/schedules?date=2026-02-12 → Get hari ini
GET  /api/schedules/{schedule}                   → View detail
POST /api/projects/{project}/schedules           → Create
```

---

## **ENDPOINT DETAILS - LENGKAP**

### **1. GET /api/users/{user_id}/schedules**

**Purpose:** Get schedule untuk user (dengan filter optional)

**Authorization:** Bearer token (authenticated user)

**URL Parameters:**
- `{user_id}` - User ID (e.g., 5)

**Query Parameters (optional):**
- `date=2026-02-12` - Get specific date
- `from_date=2026-02-01&to_date=2026-02-28` - Date range

**Example Request:**
```bash
GET /api/users/5/schedules?date=2026-02-12
Authorization: Bearer eyJhbGc...
```

**Response (200 OK):**
```json
{
  "data": [
    {
      "id": 12,
      "project_id": 1,
      "post_id": 3,
      "user_id": 5,
      "assignment_id": 2,
      "date": "2026-02-12",
      "project": {
        "id": 1,
        "name": "PT Maju Jaya",
        "location_latitude": -6.200000,
        "location_longitude": 106.816667,
        "radius": 100
      },
      "assignment": {
        "id": 2,
        "code": "P",
        "name": "Pagi",
        "start_time": "09:00:00",
        "end_time": "17:00:00",
        "grace_period": 15,
        "is_off": 0
      },
      "post": {
        "id": 3,
        "name": "Pos Gate",
        "type": "static"
      }
    }
  ],
  "pagination": {
    "total": 1,
    "per_page": 50,
    "current_page": 1,
    "last_page": 1
  }
}
```

---

### **2. POST /api/attendances/check-in**

**Purpose:** User check-in untuk mulai shift

**Authorization:** Bearer token (komandan_regu atau anggota only)

**Request Body (multipart/form-data):**
- `latitude` (required, numeric) - Device current location latitude
- `longitude` (required, numeric) - Device current location longitude
- `current_time` (required, Y-m-d H:i:s) - Device current time
- `selfie_photo` (required, image|max:1024) - Proof photo

**Example Request:**
```bash
POST /api/attendances/check-in
Authorization: Bearer eyJhbGc...
Content-Type: multipart/form-data

latitude: -6.200050
longitude: 106.816700
current_time: 2026-02-12 09:10:30
selfie_photo: @photo.jpg
```

**Response (201 Created) - ON TIME:**
```json
{
  "message": "Absen masuk berhasil.",
  "data": {
    "id": 42,
    "date": "2026-02-12",
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
      "check_in_at": "09:10:30",
      "check_out_at": null,
      "late_minutes": 0,
      "overtime_minutes": 0
    },
    "status": {
      "attendance_status": "HADIR",
      "computed_status": "HADIR",
      "overtime_status": "NONE"
    }
  }
}
```

**Response (201 Created) - LATE:**
```json
{
  "message": "Absen masuk berhasil.",
  "data": {
    "timing": {
      "check_in_at": "09:25:30",
      "late_minutes": 25
    },
    "status": {
      "computed_status": "HADIR TELAT"
    }
  }
}
```

**Response (403 Forbidden) - TOO LATE:**
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

**Response (403 Forbidden) - OUT OF RANGE:**
```json
{
  "message": "Anda berada di luar radius absen masuk.",
  "distance": "1234.56 meters",
  "allowed_radius": "100 meters"
}
```

**Response (409 Conflict) - DOUBLE CHECK-IN:**
```json
{
  "message": "Anda sudah absen masuk hari ini."
}
```

---

### **3. POST /api/attendances/check-out**

**Purpose:** User check-out untuk selesai shift

**Authorization:** Bearer token

**Request Body (JSON):**
- `attendance_id` (required, integer) - Attendance ID yang akan di-checkout
- `latitude` (required, numeric) - Device current location latitude
- `longitude` (required, numeric) - Device current location longitude
- `current_time` (required, Y-m-d H:i:s) - Device current time

**Example Request:**
```bash
POST /api/attendances/check-out
Authorization: Bearer eyJhbGc...
Content-Type: application/json

{
  "attendance_id": 42,
  "latitude": -6.200050,
  "longitude": 106.816700,
  "current_time": "2026-02-12 17:30:00"
}
```

**Response (200 OK) - NO OVERTIME:**
```json
{
  "message": "Absen pulang berhasil.",
  "data": {
    "id": 42,
    "timing": {
      "check_in_at": "09:10:30",
      "check_out_at": "17:30:00",
      "overtime_minutes": 0
    },
    "status": {
      "computed_status": "HADIR"
    }
  }
}
```

**Response (200 OK) - WITH OVERTIME:**
```json
{
  "message": "Absen pulang berhasil.",
  "data": {
    "timing": {
      "overtime_minutes": 30
    },
    "status": {
      "computed_status": "HADIR LEMBUR"
    }
  }
}
```

**Response (403 Forbidden) - NO CHECK-IN:**
```json
{
  "message": "Anda belum absen masuk."
}
```

**Response (409 Conflict) - ALREADY CHECKED OUT:**
```json
{
  "message": "Anda sudah absen pulang."
}
```

---

### **4. GET /api/attendances**

**Purpose:** List attendance dengan filter

**Authorization:** Bearer token

**Query Parameters (optional):**
- `date=2026-02-12` - Filter by date
- `user_id=5` - Filter by user (dev only)
- `project_id=1` - Result di-filter by project untuk admin_project

**Example Request:**
```bash
GET /api/attendances?date=2026-02-12&user_id=5
Authorization: Bearer eyJhbGc...
```

**Response (200 OK):**
```json
{
  "data": [
    {
      "id": 42,
      "date": "2026-02-12",
      "schedule": {
        "assignment": {...},
        "post": {...}
      },
      "timing": {
        "check_in_at": "09:10:30",
        "late_minutes": 0
      },
      "status": {
        "computed_status": "HADIR"
      }
    }
  ],
  "pagination": {
    "total": 5,
    "per_page": 15,
    "current_page": 1,
    "last_page": 1
  }
}
```

---

### **5. GET /api/attendances/{attendance_id}**

**Purpose:** View attendance detail

**Authorization:** Bearer token

**URL Parameters:**
- `{attendance_id}` - Attendance ID (e.g., 42)

**Example Request:**
```bash
GET /api/attendances/42
Authorization: Bearer eyJhbGc...
```

**Response (200 OK):**
```json
{
  "data": {
    "id": 42,
    "user_id": 5,
    "date": "2026-02-12",
    "schedule": {
      "assignment": {
        "code": "P",
        "name": "Pagi",
        "start_time": "09:00:00",
        "end_time": "17:00:00",
        "grace_period": "15 minutes"
      },
      "post": {
        "id": 3,
        "name": "Pos Gate",
        "type": "static"
      }
    },
    "timing": {
      "check_in_at": "09:10:30",
      "check_out_at": "17:30:00",
      "late_minutes": 0,
      "overtime_minutes": 30
    },
    "status": {
      "attendance_status": "HADIR",
      "computed_status": "HADIR LEMBUR",
      "overtime_status": "NONE"
    }
  }
}
```

**Response (404 Not Found):**
```json
{
  "message": "Tidak dapat menemukan resource."
}
```

---

### **6. POST /api/attendances/patrol-scan**

**Purpose:** Scan titik patroli (hanya untuk mobile posts)

**Authorization:** Bearer token

**Request Body (multipart/form-data):**
- `attendance_id` (required) - Attendance ID
- `patrol_point_id` (required) - Patrol point ID
- `latitude` (required, numeric) - Scan location latitude
- `longitude` (required, numeric) - Scan location longitude
- `current_time` (required, Y-m-d H:i:s) - Scan time
- `description_option` (required) - "aman" atau "ada kendala"
- `notes` (optional) - Additional notes
- `photos` (required, array|min:4) - Minimum 4 photos

**Example Request:**
```bash
POST /api/attendances/patrol-scan
Authorization: Bearer eyJhbGc...
Content-Type: multipart/form-data

attendance_id: 42
patrol_point_id: 1
latitude: -6.195000
longitude: 106.810000
current_time: 2026-02-12 10:00:00
description_option: aman
notes: Area aman, tidak ada masalah
photos: @photo1.jpg @photo2.jpg @photo3.jpg @photo4.jpg
```

**Response (201 Created):**
```json
{
  "message": "Scan titik patroli berhasil.",
  "patrol_scan": {
    "id": 1,
    "attendance_id": 42,
    "patrol_point_id": 1,
    "sequence_order": 1,
    "scan_time": "2026-02-12 10:00:00",
    "description_option": "aman"
  }
}
```

---

## **AUTHORIZATION RULES**

| Endpoint | GET | POST | PUT | DELETE |
|----------|-----|------|-----|--------|
| `/attendances` | DEV/HO/Admin/Komandan/Anggota* | - | - | - |
| `/attendances/check-in` | - | Komandan/Anggota only | - | - |
| `/attendances/check-out` | - | Owner only | - | - |
| `/attendances/{id}` | Owner/Admin up | - | - | - |
| `/users/{user}/schedules` | User + Project check | - | - | - |

*Role-based filtering applied

---

## **STATUS CODES**

| Code | Meaning | Example |
|------|---------|---------|
| 200 | OK | Check-out success |
| 201 | Created | Check-in success |
| 403 | Forbidden | Out of range, role denied |
| 404 | Not found | Attendance tidak ditemukan |
| 409 | Conflict | Double check-in |
| 422 | Validation error | Missing fields |

---

## **CURL EXAMPLES**

### **Check Schedule Today**
```bash
curl -X GET "http://localhost:8000/api/users/5/schedules?date=2026-02-12" \
  -H "Authorization: Bearer TOKEN" \
  -H "Accept: application/json"
```

### **Check-In**
```bash
curl -X POST "http://localhost:8000/api/attendances/check-in" \
  -H "Authorization: Bearer TOKEN" \
  -F "latitude=-6.200050" \
  -F "longitude=106.816700" \
  -F "current_time=2026-02-12 09:10:30" \
  -F "selfie_photo=@photo.jpg"
```

### **Check-Out**
```bash
curl -X POST "http://localhost:8000/api/attendances/check-out" \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "attendance_id": 42,
    "latitude": -6.200050,
    "longitude": 106.816700,
    "current_time": "2026-02-12 17:30:00"
  }'
```

### **Get Attendance List**
```bash
curl -X GET "http://localhost:8000/api/attendances?date=2026-02-12" \
  -H "Authorization: Bearer TOKEN" \
  -H "Accept: application/json"
```

---

**✅ Routes Verified & Ready!**
