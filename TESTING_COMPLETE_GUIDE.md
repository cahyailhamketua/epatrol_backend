# 🧪 TESTING GUIDE - LENGKAP DARI AWAL SAMPAI AKHIR

## **PREREQUISITE**

### **1. Database Sudah Ter-Setup**
```bash
php artisan migrate:fresh
php artisan db:seed
```

**Data yang harus ada:**
- ✓ User dengan role "anggota" (id: 5)
- ✓ Project dengan location_latitude, location_longitude, radius (id: 1)
- ✓ Assignment "Pagi" (code: P, start: 09:00, end: 17:00, grace: 15) (id: 2)
- ✓ Post "Pos Gate" dengan type: static (id: 3)
- ✓ Schedule untuk user 5 di hari hari ini (id: 12)

### **2. Server Berjalan**
```bash
php artisan serve
# Server berjalan di http://localhost:8000
```

### **3. Postman Sudah Buka**
- Buat environment baru: "Attendance Testing"
- Set variables (lihat STEP 0 di bawah)

---

## **STEP 0: SETUP POSTMAN ENVIRONMENT**

### **Buat Environment**
1. Klik **Environments** (sebelah kiri)
2. Klik **"+" New**
3. Nama: `Attendance Testing`
4. Tambah variables:

```
BASE_URL          | http://localhost:8000
TOKEN             | [kosongkan - auto set dari login]
USER_ID           | 5
PROJECT_ID        | 1  
SCHEDULE_ID       | [kosongkan - auto set]
ATTENDANCE_ID     | [kosongkan - auto set]
DEVICE_LAT        | -6.200050
DEVICE_LNG        | 106.816700
ASSIGNMENT_CODE   | [kosongkan - auto set]
START_TIME        | [kosongkan - auto set]
GRACE_PERIOD      | [kosongkan - auto set]
```

5. Klik **Save**
6. Di dropdown kanan atas, **set sebagai active environment**

---

## **STEP 1: TEST LOGIN**

### **Request:**
```
POST http://localhost:8000/api/login
Content-Type: application/json

{
  "email": "user@example.com",
  "password": "password"
}
```

### **Expected Response (200 OK):**
```json
{
  "access_token": "eyJhbGc...",
  "user": {
    "id": 5,
    "name": "John Doe",
    "email": "user@example.com",
    "role": "anggota",
    "project_id": 1
  }
}
```

### **Postman Setup:**
1. Buat request baru: `POST /api/login`
2. Tambah test script:
```javascript
if (pm.response.code === 200) {
    let response = pm.response.json();
    pm.environment.set("TOKEN", response.access_token);
    pm.environment.set("USER_ID", response.user.id);
    console.log("✓ Login berhasil!");
    console.log("✓ TOKEN: " + response.access_token.substring(0, 30) + "...");
}
```
3. **Run:** Klik **Send**
4. Verify: TOKEN sudah ter-set di environment ✓

---

## **STEP 2: GET SCHEDULE HARI INI**

### **Request:**
```
GET http://localhost:8000/api/users/5/schedules?date=2026-02-12
Authorization: Bearer {{TOKEN}}
```

### **Expected Response (200 OK):**
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
        "location_latitude": -6.200000,
        "location_longitude": 106.816667,
        "radius": 100
      },
      "assignment": {
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
  ]
}
```

### **Postman Setup:**
1. Buat request: `GET /api/users/{{USER_ID}}/schedules`
2. Tambah header: `Authorization: Bearer {{TOKEN}}`
3. Tambah query param: `date` = `2026-02-12`
4. Tambah test script:
```javascript
if (pm.response.code === 200) {
    let response = pm.response.json();
    let schedule = response.data[0];
    if (schedule) {
        pm.environment.set("SCHEDULE_ID", schedule.id);
        pm.environment.set("PROJECT_ID", schedule.project_id);
        pm.environment.set("ASSIGNMENT_CODE", schedule.assignment.code);
        pm.environment.set("START_TIME", schedule.assignment.start_time);
        pm.environment.set("GRACE_PERIOD", schedule.assignment.grace_period);
        console.log("✓ Schedule ID: " + schedule.id);
        console.log("✓ Assignment: " + schedule.assignment.code + " at " + schedule.assignment.start_time);
    }
}
```
5. **Run:** Klik **Send**
6. Verify: SCHEDULE_ID, PROJECT_ID sudah ter-set ✓

---

## **STEP 3A: TEST CHECK-IN (ON TIME) ✅**

### **Request:**
```
POST http://localhost:8000/api/attendances/check-in
Authorization: Bearer {{TOKEN}}
Content-Type: multipart/form-data

latitude: -6.200050
longitude: 106.816700
current_time: 2026-02-12 09:10:30
selfie_photo: [image.jpg]
```

**Timing Calculation:**
```
Assignment start_time: 09:00:00
Grace period: 15 minutes
Grace deadline: 09:00:00 + 15 = 09:15:00
Device time: 09:10:30

09:10:30 < 09:15:00? YES → HADIR (on time) ✓
```

### **Expected Response (201 Created):**
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
      "check_out_at": null,
      "late_minutes": 0,
      "overtime_minutes": 0
    },
    "status": {
      "attendance_status": "HADIR",
      "computed_status": "HADIR",
      "overtime_status": "NONE"
    },
    "can_attend": true
  }
}
```

### **Postman Setup:**
1. Buat request: `POST /api/attendances/check-in`
2. Tambah header: `Authorization: Bearer {{TOKEN}}`
3. Body → form-data:
   - `latitude` = `-6.200050`
   - `longitude` = `106.816700`
   - `current_time` = `2026-02-12 09:10:30`
   - `selfie_photo` = [upload file]
4. Tambah test script:
```javascript
if (pm.response.code === 201) {
    let response = pm.response.json();
    pm.environment.set("ATTENDANCE_ID", response.data.id);
    console.log("✓ Check-in ON TIME berhasil!");
    console.log("✓ Status: " + response.data.status.computed_status);
    console.log("✓ Attendance ID: " + response.data.id);
} else {
    console.log("✗ Check-in failed: " + pm.response.status);
}
```
5. **Run:** Klik **Send**
6. **Verify:**
   - Status code: 201 ✓
   - computed_status: "HADIR" ✓
   - late_minutes: 0 ✓
   - ATTENDANCE_ID ter-set ✓

---

## **STEP 3B: TEST CHECK-IN (LATE) ⏰**

### **Request (Buat schedule baru atau delete attendance):**
```
POST http://localhost:8000/api/attendances/check-in
Authorization: Bearer {{TOKEN}}
Content-Type: multipart/form-data

latitude: -6.200050
longitude: 106.816700
current_time: 2026-02-12 09:25:00  ← TELAT!
selfie_photo: [image.jpg]
```

**Timing Calculation:**
```
Grace deadline: 09:15:00
Absolute deadline: 09:30:00  (09:00 + 30 min)
Device time: 09:25:00

09:25:00 >= 09:15:00? YES → TELAT
09:25:00 < 09:30:00? YES → Still allowed
late_minutes = 09:25:00 - 09:00:00 = 25 minutes → HADIR TELAT ✓
```

### **Expected Response (201 Created):**
```json
{
  "message": "Absen masuk berhasil.",
  "data": {
    "timing": {
      "check_in_at": "09:25:00",
      "late_minutes": 25
    },
    "status": {
      "computed_status": "HADIR TELAT"
    }
  }
}
```

### **Postman Setup:**
1. Duplicate STEP 3A request
2. Ubah `current_time` = `2026-02-12 09:25:00`
3. **Run:** Klik **Send**
4. **Verify:**
   - Status code: 201 ✓
   - computed_status: "HADIR TELAT" ✓
   - late_minutes: 25 ✓

---

## **STEP 3C: TEST CHECK-IN (TOO LATE) ❌**

### **Request:**
```
POST http://localhost:8000/api/attendances/check-in
Authorization: Bearer {{TOKEN}}
Content-Type: multipart/form-data

latitude: -6.200050
longitude: 106.816700
current_time: 2026-02-12 09:35:00  ← TERLALU TELAT!
selfie_photo: [image.jpg]
```

**Timing Calculation:**
```
Absolute deadline: 09:30:00
Device time: 09:35:00

09:35:00 >= 09:30:00? YES → REJECT! (403 Forbidden) ✗
```

### **Expected Response (403 Forbidden):**
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

### **Postman Setup:**
1. Duplicate STEP 3A request
2. Ubah `current_time` = `2026-02-12 09:35:00`
3. **Run:** Klik **Send**
4. **Verify:**
   - Status code: 403 ✓
   - Message: "Waktu absen masuk telah berakhir" ✓
   - allowed_deadline: "09:30:00" ✓

---

## **STEP 3D: TEST CHECK-IN (OUT OF RANGE) ❌**

### **Request:**
```
POST http://localhost:8000/api/attendances/check-in
Authorization: Bearer {{TOKEN}}
Content-Type: multipart/form-data

latitude: -6.210000      ← JAUH DARI OFFICE!
longitude: 106.825000    ← JAUH DARI OFFICE!
current_time: 2026-02-12 09:10:30
selfie_photo: [image.jpg]
```

**Location Calculation:**
```
Reference (Project): -6.200000, 106.816667
Device: -6.210000, 106.825000
Distance = Haversine(...) ≈ 1234+ meters

Allowed radius: 100 meters
1234 > 100? YES → REJECT! (403 Forbidden) ✗
```

### **Expected Response (403 Forbidden):**
```json
{
  "message": "Anda berada di luar radius absen masuk.",
  "your_location": {
    "latitude": -6.210000,
    "longitude": 106.825000
  },
  "reference_location": {
    "type": "project",
    "latitude": -6.200000,
    "longitude": 106.816667
  },
  "distance": "1234.56 meters",
  "allowed_radius": "100 meters"
}
```

### **Postman Setup:**
1. Duplicate STEP 3A request
2. Ubah:
   - `latitude` = `-6.210000`
   - `longitude` = `106.825000`
3. **Run:** Klik **Send**
4. **Verify:**
   - Status code: 403 ✓
   - Message: "Anda berada di luar radius" ✓
   - distance > allowed_radius ✓

---

## **STEP 4: VIEW ATTENDANCE DETAIL**

### **Request:**
```
GET http://localhost:8000/api/attendances/{{ATTENDANCE_ID}}
Authorization: Bearer {{TOKEN}}
```

### **Expected Response (200 OK):**
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
      "check_out_at": null,
      "late_minutes": 0
    },
    "status": {
      "computed_status": "HADIR"
    }
  }
}
```

### **Postman Setup:**
1. Buat request: `GET /api/attendances/{{ATTENDANCE_ID}}`
2. Tambah header: `Authorization: Bearer {{TOKEN}}`
3. **Run:** Klik **Send**
4. **Verify:**
   - Status code: 200 ✓
   - ID sesuai dengan ATTENDANCE_ID ✓
   - Semua field lengkap ✓

---

## **STEP 5: CHECK-OUT**

### **Request:**
```
POST http://localhost:8000/api/attendances/check-out
Authorization: Bearer {{TOKEN}}
Content-Type: application/json

{
  "attendance_id": {{ATTENDANCE_ID}},
  "latitude": -6.200050,
  "longitude": 106.816700,
  "current_time": "2026-02-12 17:30:00"
}
```

**Overtime Calculation:**
```
End time: 17:00:00
Check-out time: 17:30:00

17:30:00 > 17:00:00? YES → LEMBUR
overtime_minutes = 17:30:00 - 17:00:00 = 30 minutes
computed_status = "HADIR LEMBUR" ✓
```

### **Expected Response (200 OK):**
```json
{
  "message": "Absen pulang berhasil.",
  "data": {
    "id": 42,
    "timing": {
      "check_in_at": "09:10:30",
      "check_out_at": "17:30:00",
      "late_minutes": 0,
      "overtime_minutes": 30
    },
    "status": {
      "computed_status": "HADIR LEMBUR"
    }
  }
}
```

### **Postman Setup:**
1. Buat request: `POST /api/attendances/check-out`
2. Tambah header: `Authorization: Bearer {{TOKEN}}`
3. Body (raw JSON):
```json
{
  "attendance_id": {{ATTENDANCE_ID}},
  "latitude": {{DEVICE_LAT}},
  "longitude": {{DEVICE_LNG}},
  "current_time": "2026-02-12 17:30:00"
}
```
4. **Run:** Klik **Send**
5. **Verify:**
   - Status code: 200 ✓
   - computed_status: "HADIR LEMBUR" ✓
   - overtime_minutes: 30 ✓

---

## **STEP 6: LIST ATTENDANCES**

### **Request:**
```
GET http://localhost:8000/api/attendances?date=2026-02-12
Authorization: Bearer {{TOKEN}}
```

### **Expected Response (200 OK):**
```json
{
  "data": [
    {
      "id": 42,
      "date": "2026-02-12",
      "timing": {
        "check_in_at": "09:10:30",
        "check_out_at": "17:30:00",
        "late_minutes": 0
      },
      "status": {
        "computed_status": "HADIR LEMBUR"
      }
    }
  ],
  "pagination": {
    "total": 1,
    "per_page": 15,
    "current_page": 1
  }
}
```

### **Postman Setup:**
1. Buat request: `GET /api/attendances`
2. Tambah header: `Authorization: Bearer {{TOKEN}}`
3. Query param: `date` = `2026-02-12`
4. **Run:** Klik **Send**
5. **Verify:**
   - Status code: 200 ✓
   - Data array tidak kosong ✓
   - Pagination info ada ✓

---

## **TESTING CHECKLIST ✅**

### **Phase 1: Setup**
- [ ] Server running (http://localhost:8000)
- [ ] Postman environment active
- [ ] Database sudah seeded

### **Phase 2: Authentication**
- [ ] Login successful (200 OK)
- [ ] TOKEN ter-set di environment
- [ ] USER_ID ter-set di environment

### **Phase 3: Schedule**
- [ ] Get schedule (200 OK)
- [ ] Response include project, assignment, post
- [ ] SCHEDULE_ID ter-set di environment

### **Phase 4: Check-In Scenarios**
- [ ] ON TIME (09:10) → Status HADIR (201) ✓
- [ ] LATE (09:25) → Status HADIR TELAT (201) ✓
- [ ] TOO LATE (09:35) → 403 Forbidden ✓
- [ ] OUT OF RANGE → 403 Forbidden ✓

### **Phase 5: Attendance Operations**
- [ ] View detail (200 OK, all fields present)
- [ ] Check-out (200 OK, status HADIR LEMBUR)
- [ ] List attendances (200 OK, paginated)

**All tests passed? READY FOR PRODUCTION! 🚀**

---

## **TROUBLESHOOTING**

### **Error: "No schedule found"**
→ Pastikan ada schedule untuk date hari ini di database

### **Error: "Failed to upload selfie"**
→ Pastikan file upload format image (jpg, png) dan < 1MB

### **Error: "Unauthorized" (401)**
→ TOKEN tidak valid/expired, ambil TOKEN baru dari login

### **Error: "Not found" (404)**
→ Attendance ID tidak valid, gunakan ATTENDANCE_ID dari check-in response

---

**TESTING COMPLETE!** 🎉
