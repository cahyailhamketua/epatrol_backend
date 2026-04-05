# 📮 POSTMAN COLLECTION - TESTING LENGKAP

## **SETUP AWAL - POSTMAN ENVIRONMENT**

### **Buat Environment Baru**
1. Klik Environments
2. Klik "+" (Create)
3. Isikan nama: "Attendance Testing"
4. Tambah variables:

```json
{
  "BASE_URL": "http://localhost:8000",
  "TOKEN": "",
  "USER_ID": "5",
  "PROJECT_ID": "1",
  "SCHEDULE_ID": "",
  "ATTENDANCE_ID": "",
  "DEVICE_LAT": "-6.200050",
  "DEVICE_LNG": "106.816700",
  "DEVICE_TIME": "2026-02-12 09:10:30"
}
```

### **Simpan & Set sebagai Active Environment**

---

## **STEP-BY-STEP TESTING GUIDE**

### **STEP 1: LOGIN & GET TOKEN**

**Request:**
```
POST {{BASE_URL}}/api/login
Content-Type: application/json

{
  "email": "user@example.com",
  "password": "password"
}
```

**Pre-request Script (auto set token):**
```javascript
// Kosongkan dulu
```

**Tests (auto set variable):**
```javascript
if (pm.response.code === 200) {
    let response = pm.response.json();
    pm.environment.set("TOKEN", response.access_token);
    pm.environment.set("USER_ID", response.user.id);
    console.log("✓ Token set: " + response.access_token.substring(0, 20) + "...");
}
```

**Expected Response (200 OK):**
```json
{
  "access_token": "eyJhbGc...",
  "user": {
    "id": 5,
    "name": "John Doe",
    "email": "user@example.com",
    "role": "anggota"
  }
}
```

---

### **STEP 2: GET SCHEDULE TODAY**

**Request:**
```
GET {{BASE_URL}}/api/users/{{USER_ID}}/schedules?date=2026-02-12
Authorization: Bearer {{TOKEN}}
```

**Tests (auto set variables):**
```javascript
if (pm.response.code === 200) {
    let response = pm.response.json();
    let schedule = response.data[0];
    pm.environment.set("SCHEDULE_ID", schedule.id);
    pm.environment.set("PROJECT_ID", schedule.project_id);
    pm.environment.set("ASSIGNMENT_CODE", schedule.assignment.code);
    pm.environment.set("START_TIME", schedule.assignment.start_time);
    pm.environment.set("GRACE_PERIOD", schedule.assignment.grace_period);
    console.log("✓ Schedule ID: " + schedule.id);
    console.log("✓ Assignment: " + schedule.assignment.code + " at " + schedule.assignment.start_time);
}
```

**Expected Response (200 OK):**
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
        "name": "Pos Gate",
        "type": "static"
      }
    }
  ]
}
```

---

### **STEP 3A: CHECK-IN ON TIME ✅**

**Request:**
```
POST {{BASE_URL}}/api/attendances/check-in
Authorization: Bearer {{TOKEN}}
Content-Type: multipart/form-data

latitude: {{DEVICE_LAT}}           (-6.200050)
longitude: {{DEVICE_LNG}}          (106.816700)
current_time: 2026-02-12 09:10:30  (before grace deadline 09:15)
selfie_photo: [image.jpg]
```

**Tests (auto set variables):**
```javascript
if (pm.response.code === 201) {
    let response = pm.response.json();
    pm.environment.set("ATTENDANCE_ID", response.data.id);
    console.log("✓ Check-in successful!");
    console.log("✓ Status: " + response.data.status.computed_status);
    console.log("✓ Attendance ID: " + response.data.id);
}
```

**Expected Response (201 Created):**
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
        "name": "Pos Gate",
        "type": "static"
      }
    },
    "timing": {
      "check_in_at": "09:10:30",
      "late_minutes": 0
    },
    "status": {
      "attendance_status": "HADIR",
      "computed_status": "HADIR"
    }
  }
}
```

---

### **STEP 3B: CHECK-IN LATE (Alternative Test) ⏰**

**Use New Collection Request atau Clear attendance sebelumnya**

**Request:**
```
POST {{BASE_URL}}/api/attendances/check-in
Authorization: Bearer {{TOKEN}}
Content-Type: multipart/form-data

latitude: {{DEVICE_LAT}}
longitude: {{DEVICE_LNG}}
current_time: 2026-02-12 09:25:30         (after grace deadline but before absolute)
selfie_photo: [image.jpg]
```

**Expected Response (201 Created):**
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

---

### **STEP 3C: CHECK-IN TOO LATE ❌**

**Request:**
```
POST {{BASE_URL}}/api/attendances/check-in
Authorization: Bearer {{TOKEN}}
Content-Type: multipart/form-data

latitude: {{DEVICE_LAT}}
longitude: {{DEVICE_LNG}}
current_time: 2026-02-12 09:35:00         (after absolute deadline 09:30)
selfie_photo: [image.jpg]
```

**Expected Response (403 Forbidden):**
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

### **STEP 3D: CHECK-IN OUT OF RANGE ❌**

**Request:**
```
POST {{BASE_URL}}/api/attendances/check-in
Authorization: Bearer {{TOKEN}}
Content-Type: multipart/form-data

latitude: -6.210000              (far away)
longitude: 106.825000           (far away)
current_time: 2026-02-12 09:10:30
selfie_photo: [image.jpg]
```

**Expected Response (403 Forbidden):**
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

---

### **STEP 4: VIEW ATTENDANCE CREATED**

**Request:**
```
GET {{BASE_URL}}/api/attendances/{{ATTENDANCE_ID}}
Authorization: Bearer {{TOKEN}}
```

**Expected Response (200 OK):**
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
    },
    "can_attend": true
  }
}
```

---

### **STEP 5: PATROL SCAN (jika pos mobile)**

**Request:**
```
POST {{BASE_URL}}/api/attendances/patrol-scan
Authorization: Bearer {{TOKEN}}
Content-Type: multipart/form-data

attendance_id: {{ATTENDANCE_ID}}
patrol_point_id: 1
latitude: -6.195000
longitude: 106.810000
current_time: 2026-02-12 10:00:00
description_option: aman
notes: Area aman
photos: [photo1.jpg, photo2.jpg, photo3.jpg, photo4.jpg]
```

**Expected Response (201 Created):**
```json
{
  "message": "Scan titik patroli berhasil.",
  "patrol_scan": {
    "id": 1,
    "attendance_id": 42,
    "patrol_point_id": 1,
    "sequence_order": 1,
    "scan_time": "2026-02-12 10:00:00"
  }
}
```

---

### **STEP 6: CHECK-OUT**

**Request:**
```
POST {{BASE_URL}}/api/attendances/check-out
Authorization: Bearer {{TOKEN}}
Content-Type: application/json

{
  "attendance_id": {{ATTENDANCE_ID}},
  "latitude": {{DEVICE_LAT}},
  "longitude": {{DEVICE_LNG}},
  "current_time": "2026-02-12 17:30:00"
}
```

**Tests (verify overtime):**
```javascript
if (pm.response.code === 200) {
    let response = pm.response.json();
    console.log("✓ Check-out successful!");
    console.log("✓ Status: " + response.data.status.computed_status);
    console.log("✓ Overtime: " + response.data.timing.overtime_minutes + " minutes");
}
```

**Expected Response (200 OK):**
```json
{
  "message": "Absen pulang berhasil.",
  "data": {
    "id": 42,
    "status": {
      "computed_status": "HADIR LEMBUR"
    },
    "timing": {
      "check_in_at": "09:10:30",
      "check_out_at": "17:30:00",
      "overtime_minutes": 30
    }
  }
}
```

---

### **STEP 7: LIST ALL ATTENDANCES**

**Request:**
```
GET {{BASE_URL}}/api/attendances?date=2026-02-12
Authorization: Bearer {{TOKEN}}
```

**Optional Filters:**
```
?date=2026-02-12
?user_id=5
?project_id=1
?date=2026-02-12&user_id=5
```

**Expected Response (200 OK):**
```json
{
  "data": [
    {
      "id": 42,
      "date": "2026-02-12",
      "status": {
        "computed_status": "HADIR LEMBUR"
      },
      "timing": {
        "check_in_at": "09:10:30",
        "late_minutes": 0
      }
    }
  ],
  "pagination": {
    "total": 1,
    "per_page": 15,
    "current_page": 1,
    "last_page": 1
  }
}
```

---

## **POSTMAN JSON COLLECTION**

Copy-paste ke Postman sebagai collection baru:

```json
{
  "info": {
    "name": "Attendance System - Complete Flow",
    "schema": "https://schema.getpostman.com/json/collection/v2.1.0/collection.json"
  },
  "item": [
    {
      "name": "1. Login",
      "request": {
        "method": "POST",
        "header": [
          {
            "key": "Content-Type",
            "value": "application/json"
          }
        ],
        "body": {
          "mode": "raw",
          "raw": "{\n  \"email\": \"user@example.com\",\n  \"password\": \"password\"\n}"
        },
        "url": {
          "raw": "{{BASE_URL}}/api/login",
          "host": ["{{BASE_URL}}"],
          "path": ["api", "login"]
        }
      }
    },
    {
      "name": "2. Get Schedule Today",
      "request": {
        "method": "GET",
        "header": [
          {
            "key": "Authorization",
            "value": "Bearer {{TOKEN}}"
          }
        ],
        "url": {
          "raw": "{{BASE_URL}}/api/users/{{USER_ID}}/schedules?date=2026-02-12",
          "host": ["{{BASE_URL}}"],
          "path": ["api", "users", "{{USER_ID}}", "schedules"],
          "query": [
            {
              "key": "date",
              "value": "2026-02-12"
            }
          ]
        }
      }
    },
    {
      "name": "3a. Check-In (ON TIME)",
      "request": {
        "method": "POST",
        "header": [
          {
            "key": "Authorization",
            "value": "Bearer {{TOKEN}}"
          }
        ],
        "body": {
          "mode": "formdata",
          "formdata": [
            {
              "key": "latitude",
              "value": "{{DEVICE_LAT}}",
              "type": "text"
            },
            {
              "key": "longitude",
              "value": "{{DEVICE_LNG}}",
              "type": "text"
            },
            {
              "key": "current_time",
              "value": "2026-02-12 09:10:30",
              "type": "text"
            },
            {
              "key": "selfie_photo",
              "type": "file",
              "src": "C:/path/to/photo.jpg"
            }
          ]
        },
        "url": {
          "raw": "{{BASE_URL}}/api/attendances/check-in",
          "host": ["{{BASE_URL}}"],
          "path": ["api", "attendances", "check-in"]
        }
      }
    },
    {
      "name": "3b. Check-In (LATE)",
      "request": {
        "method": "POST",
        "header": [
          {
            "key": "Authorization",
            "value": "Bearer {{TOKEN}}"
          }
        ],
        "body": {
          "mode": "formdata",
          "formdata": [
            {
              "key": "latitude",
              "value": "{{DEVICE_LAT}}",
              "type": "text"
            },
            {
              "key": "longitude",
              "value": "{{DEVICE_LNG}}",
              "type": "text"
            },
            {
              "key": "current_time",
              "value": "2026-02-12 09:25:00",
              "type": "text"
            },
            {
              "key": "selfie_photo",
              "type": "file",
              "src": "C:/path/to/photo.jpg"
            }
          ]
        },
        "url": {
          "raw": "{{BASE_URL}}/api/attendances/check-in",
          "host": ["{{BASE_URL}}"],
          "path": ["api", "attendances", "check-in"]
        }
      }
    },
    {
      "name": "3c. Check-In (TOO LATE - 403)",
      "request": {
        "method": "POST",
        "header": [
          {
            "key": "Authorization",
            "value": "Bearer {{TOKEN}}"
          }
        ],
        "body": {
          "mode": "formdata",
          "formdata": [
            {
              "key": "latitude",
              "value": "{{DEVICE_LAT}}",
              "type": "text"
            },
            {
              "key": "longitude",
              "value": "{{DEVICE_LNG}}",
              "type": "text"
            },
            {
              "key": "current_time",
              "value": "2026-02-12 09:35:00",
              "type": "text"
            },
            {
              "key": "selfie_photo",
              "type": "file",
              "src": "C:/path/to/photo.jpg"
            }
          ]
        },
        "url": {
          "raw": "{{BASE_URL}}/api/attendances/check-in",
          "host": ["{{BASE_URL}}"],
          "path": ["api", "attendances", "check-in"]
        }
      }
    },
    {
      "name": "3d. Check-In (OUT OF RANGE - 403)",
      "request": {
        "method": "POST",
        "header": [
          {
            "key": "Authorization",
            "value": "Bearer {{TOKEN}}"
          }
        ],
        "body": {
          "mode": "formdata",
          "formdata": [
            {
              "key": "latitude",
              "value": "-6.210000",
              "type": "text"
            },
            {
              "key": "longitude",
              "value": "106.825000",
              "type": "text"
            },
            {
              "key": "current_time",
              "value": "2026-02-12 09:10:30",
              "type": "text"
            },
            {
              "key": "selfie_photo",
              "type": "file",
              "src": "C:/path/to/photo.jpg"
            }
          ]
        },
        "url": {
          "raw": "{{BASE_URL}}/api/attendances/check-in",
          "host": ["{{BASE_URL}}"],
          "path": ["api", "attendances", "check-in"]
        }
      }
    },
    {
      "name": "4. View Attendance Detail",
      "request": {
        "method": "GET",
        "header": [
          {
            "key": "Authorization",
            "value": "Bearer {{TOKEN}}"
          }
        ],
        "url": {
          "raw": "{{BASE_URL}}/api/attendances/{{ATTENDANCE_ID}}",
          "host": ["{{BASE_URL}}"],
          "path": ["api", "attendances", "{{ATTENDANCE_ID}}"]
        }
      }
    },
    {
      "name": "5. Check-Out",
      "request": {
        "method": "POST",
        "header": [
          {
            "key": "Authorization",
            "value": "Bearer {{TOKEN}}"
          },
          {
            "key": "Content-Type",
            "value": "application/json"
          }
        ],
        "body": {
          "mode": "raw",
          "raw": "{\n  \"attendance_id\": {{ATTENDANCE_ID}},\n  \"latitude\": {{DEVICE_LAT}},\n  \"longitude\": {{DEVICE_LNG}},\n  \"current_time\": \"2026-02-12 17:30:00\"\n}"
        },
        "url": {
          "raw": "{{BASE_URL}}/api/attendances/check-out",
          "host": ["{{BASE_URL}}"],
          "path": ["api", "attendances", "check-out"]
        }
      }
    },
    {
      "name": "6. List Attendances",
      "request": {
        "method": "GET",
        "header": [
          {
            "key": "Authorization",
            "value": "Bearer {{TOKEN}}"
          }
        ],
        "url": {
          "raw": "{{BASE_URL}}/api/attendances?date=2026-02-12",
          "host": ["{{BASE_URL}}"],
          "path": ["api", "attendances"],
          "query": [
            {
              "key": "date",
              "value": "2026-02-12"
            }
          ]
        }
      }
    }
  ]
}
```

---

## **TESTING CHECKLIST**

**✅ Phase 1: Setup**
- [ ] Set BASE_URL ke `http://localhost:8000`
- [ ] Import collection atau buat manual
- [ ] Set environment variables

**✅ Phase 2: Authentication**
- [ ] Login dengan email user & password
- [ ] Verify TOKEN di-set di environment
- [ ] Verify USER_ID di-set di environment

**✅ Phase 3: Schedule**
- [ ] Get schedule untuk hari ini
- [ ] Verify ada assignment, post, project data
- [ ] Verify post_id ada di response
- [ ] Copy SCHEDULE_ID ke environment

**✅ Phase 4: Check-In Scenarios**
- [ ] Check-in ON TIME (09:10) → Status HADIR ✓
- [ ] Check-in LATE (09:25) → Status HADIR TELAT ✓
- [ ] Check-in TOO LATE (09:35) → 403 Forbidden ✓
- [ ] Check-in OUT OF RANGE → 403 Forbidden ✓

**✅ Phase 5: Attendance Operations**
- [ ] View attendance detail → All fields present
- [ ] Check-out → Status HADIR LEMBUR (if overtime)
- [ ] List attendances → Paginated response

**✅ Phase 6: Advanced**
- [ ] Patrol scan (jika mobile post) → Sequential points
- [ ] Double check-in → 409 Conflict
- [ ] Check-out without check-in → 403 Forbidden

---

## **QUICK SHORTCUTS**

**Test dengan cepat:**

1. **Full flow (ON TIME):**
   - Login → Get Schedule → Check-In (09:10) → Check-Out → List

2. **Error handling:**
   - Check-In (09:35) → Expect 403
   - Check-In (different location) → Expect 403

3. **Authorization:**
   - Use token HO → Check-In → Expect 403

**Environment reset:**
- Clear TOKEN, ATTENDANCE_ID
- Keep BASE_URL, USER_ID

---

**SIAP TESTING!** 🚀
