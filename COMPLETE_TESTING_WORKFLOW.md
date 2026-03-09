# 📋 COMPLETE TESTING WORKFLOW - START TO FINISH

## **OVERVIEW**

This document provides a **complete end-to-end testing workflow** for the Attendance System. Follow these steps sequentially to test all functionality from login through attendance tracking.

**Total Time:** ~30-45 minutes  
**Prerequisites:** Laravel server running, Postman installed, database seeded

---

## **PHASE 1: ENVIRONMENT SETUP (5 MINUTES)**

### **Step 1.1: Start Laravel Server**
```bash
cd /home/epatrol/backend
php artisan serve
# Output: Application running on [http://127.0.0.1:8000]
```

### **Step 1.2: Setup Postman Environment**
```
1. Open Postman
2. Click "Environments" (left sidebar)
3. Click "+" to create new
4. Name: "Attendance Testing"
5. Add variables (see QUICK_REFERENCE.md for full list)
6. Save and make ACTIVE
```

### **Step 1.3: Verify Database Ready**
```bash
# In new terminal:
php artisan tinker
>>> Schedule::today()->count()      # Should be > 0
>>> Project::where('id', 1)->first() # Check has location_latitude/longitude
>>> exit()
```

**State:** ✅ Ready to test

---

## **PHASE 2: AUTHENTICATION (5 MINUTES)**

### **Step 2.1: Test Login Endpoint**

**Request:**
```
POST http://localhost:8000/api/login
Content-Type: application/json
Body:
{
  "email": "user@example.com",
  "password": "password"
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

**Postman Setup:**
1. Create new request: `POST /api/login`
2. Add test script:
```javascript
if (pm.response.code === 200) {
    let response = pm.response.json();
    pm.environment.set("TOKEN", response.access_token);
    pm.environment.set("USER_ID", response.user.id);
    console.log("✓ Login successful!");
    console.log("✓ TOKEN saved to environment");
} else {
    console.log("✗ Login failed: " + pm.response.status);
}
```

**Verification:**
- [ ] Status code: 200 ✓
- [ ] Response includes access_token ✓
- [ ] TOKEN variable set in environment ✓

**State:** ✅ Authenticated

---

## **PHASE 3: FETCH SCHEDULE (5 MINUTES)**

### **Step 3.1: Get Today's Schedule**

**Request:**
```
GET http://localhost:8000/api/users/5/schedules?date=2026-02-12
Authorization: Bearer {{TOKEN}}
```

**Expected Response (200 OK):**
```json
{
  "data": [
    {
      "id": 12,
      "user_id": 5,
      "project_id": 1,
      "post_id": 3,
      "assignment_id": 2,
      "date": "2026-02-12",
      "project": {
        "id": 1,
        "name": "Office",
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
        "grace_period": 15
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

**Postman Setup:**
1. Create request: `GET /api/users/{{USER_ID}}/schedules`
2. Add query param: `date` = `2026-02-12`
3. Add header: `Authorization: Bearer {{TOKEN}}`
4. Add test script:
```javascript
if (pm.response.code === 200) {
    let response = pm.response.json();
    let schedule = response.data[0];
    if (schedule) {
        pm.environment.set("SCHEDULE_ID", schedule.id);
        pm.environment.set("PROJECT_ID", schedule.project_id);
        pm.environment.set("ASSIGNMENT_CODE", schedule.assignment.code);
        console.log("✓ Schedule retrieved!");
        console.log("✓ Assignment: " + schedule.assignment.code + " at " + schedule.assignment.start_time);
    }
}
```

**Critical Data Points to Verify:**
- [ ] project.location_latitude exists ✓
- [ ] project.location_longitude exists ✓
- [ ] project.radius exists ✓
- [ ] assignment.start_time exists ✓
- [ ] assignment.grace_period exists ✓
- [ ] post.name exists ✓

**State:** ✅ Schedule data confirmed

---

## **PHASE 4: CHECK-IN TESTING (15 MINUTES)**

### **Scenario 4.1: Check-In ON TIME ✅**

**Request:**
```
POST http://localhost:8000/api/attendances/check-in
Authorization: Bearer {{TOKEN}}
Content-Type: multipart/form-data

latitude: -6.200050
longitude: 106.816700
current_time: 2026-02-12 09:10:30
selfie_photo: [image.jpg]
```

**Time Validation:**
```
Assignment start: 09:00:00
Grace period: 15 minutes
Grace deadline: 09:15:00
Device time: 09:10:30

Result: 09:10:30 < 09:15:00 → ✓ HADIR
```

**Expected Response (201 Created):**
```json
{
  "message": "Absen masuk berhasil.",
  "data": {
    "id": 42,
    "date": "2026-02-12",
    "timing": {
      "check_in_at": "09:10:30",
      "late_minutes": 0
    },
    "status": {
      "computed_status": "HADIR"
    }
  }
}
```

**Postman Setup:**
1. Create request: `POST /api/attendances/check-in`
2. Body → form-data:
   - latitude: -6.200050
   - longitude: 106.816700
   - current_time: 2026-02-12 09:10:30
   - selfie_photo: [upload jpg file]
3. Add test script to capture ATTENDANCE_ID

**Verification:**
- [ ] Status code: 201 ✓
- [ ] computed_status: "HADIR" ✓
- [ ] late_minutes: 0 ✓
- [ ] ATTENDANCE_ID captured ✓

---

### **Scenario 4.2: Check-In LATE ⏰**

**Note:** Create new schedule or delete previous attendance first

**Request:**
```
POST http://localhost:8000/api/attendances/check-in
Authorization: Bearer {{TOKEN}}
Content-Type: multipart/form-data

latitude: -6.200050
longitude: 106.816700
current_time: 2026-02-12 09:25:00
selfie_photo: [image.jpg]
```

**Time Validation:**
```
Grace deadline: 09:15:00
Device time: 09:25:00

Result: 09:25:00 >= 09:15:00 AND 09:25:00 < 09:30:00 → ✓ HADIR TELAT
```

**Expected Response (201 Created):**
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

**Verification:**
- [ ] Status code: 201 ✓
- [ ] computed_status: "HADIR TELAT" ✓
- [ ] late_minutes: 25 ✓

---

### **Scenario 4.3: Check-In TOO LATE ❌**

**Request:**
```
POST http://localhost:8000/api/attendances/check-in
Authorization: Bearer {{TOKEN}}
Content-Type: multipart/form-data

latitude: -6.200050
longitude: 106.816700
current_time: 2026-02-12 09:35:00
selfie_photo: [image.jpg]
```

**Time Validation:**
```
Absolute deadline: 09:00:00 + (15 * 2) = 09:30:00
Device time: 09:35:00

Result: 09:35:00 >= 09:30:00 → ❌ REJECTED
```

**Expected Response (403 Forbidden):**
```json
{
  "message": "Waktu absen masuk telah berakhir.",
  "allowed_deadline": "09:30:00",
  "your_time": "09:35:00"
}
```

**Verification:**
- [ ] Status code: 403 ✓
- [ ] Message mentions deadline ✓
- [ ] allowed_deadline: "09:30:00" ✓

---

### **Scenario 4.4: Check-In OUT OF RANGE ❌**

**Request:**
```
POST http://localhost:8000/api/attendances/check-in
Authorization: Bearer {{TOKEN}}
Content-Type: multipart/form-data

latitude: -6.210000
longitude: 106.825000
current_time: 2026-02-12 09:10:30
selfie_photo: [image.jpg]
```

**Distance Validation:**
```
Reference (Project): -6.200000, 106.816667
Device: -6.210000, 106.825000
Distance = ~1234+ meters

Allowed radius: 100 meters
1234 > 100 → ❌ REJECTED
```

**Expected Response (403 Forbidden):**
```json
{
  "message": "Anda berada di luar radius absen masuk.",
  "distance": "1234.56 meters",
  "allowed_radius": "100 meters"
}
```

**Verification:**
- [ ] Status code: 403 ✓
- [ ] Message mentions "di luar radius" ✓
- [ ] distance > allowed_radius ✓

---

## **PHASE 5: ATTENDANCE OPERATIONS (10 MINUTES)**

### **Step 5.1: View Attendance Detail**

**Request:**
```
GET http://localhost:8000/api/attendances/{{ATTENDANCE_ID}}
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
        "start_time": "09:00:00"
      },
      "post": {
        "name": "Pos Gate"
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

**Verification:**
- [ ] Status code: 200 ✓
- [ ] ID matches ATTENDANCE_ID ✓
- [ ] All fields present ✓

---

### **Step 5.2: Check-Out**

**Request:**
```
POST http://localhost:8000/api/attendances/check-out
Authorization: Bearer {{TOKEN}}
Content-Type: application/json

{
  "attendance_id": 42,
  "latitude": -6.200050,
  "longitude": 106.816700,
  "current_time": "2026-02-12 17:30:00"
}
```

**Overtime Calculation:**
```
End time: 17:00:00
Check-out time: 17:30:00

17:30:00 > 17:00:00 → ✓ LEMBUR
overtime_minutes = 30
computed_status = "HADIR LEMBUR"
```

**Expected Response (200 OK):**
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

**Verification:**
- [ ] Status code: 200 ✓
- [ ] computed_status: "HADIR LEMBUR" ✓
- [ ] overtime_minutes: 30 ✓

---

### **Step 5.3: List Attendances**

**Request:**
```
GET http://localhost:8000/api/attendances?date=2026-02-12
Authorization: Bearer {{TOKEN}}
```

**Expected Response (200 OK):**
```json
{
  "data": [
    {
      "id": 42,
      "date": "2026-02-12",
      "timing": {
        "check_in_at": "09:10:30",
        "check_out_at": "17:30:00"
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

**Verification:**
- [ ] Status code: 200 ✓
- [ ] Data array contains attendance ✓
- [ ] Pagination included ✓

---

## **PHASE 6: AUTHORIZATION TESTING (10 MINUTES)**

### **Step 6.1: Test Role-Based Access Control**

**Test 1: DEV Role - Can view all**
```
Login as DEV user
GET /api/attendances
Expected: 200 OK, returns all attendances
```

**Test 2: HO Role - Can view organization data only**
```
Login as HO user
GET /api/attendances
Expected: 200 OK, filtered by organization_id
```

**Test 3: ANGGOTA Role - Can check-in/out, view own**
```
Login as ANGGOTA user
GET /api/attendances
Expected: 200 OK, only own attendances

Try to view other user's attendance:
GET /api/attendances?user_id=6
Expected: 403 Forbidden (not authorized)
```

**Verification:**
- [ ] DEV can view all ✓
- [ ] HO can view org data ✓
- [ ] ANGGOTA can only view own ✓
- [ ] Unauthorized access rejected ✓

---

## **COMPLETE TEST CHECKLIST ✅**

### **Authentication (Phase 2)**
- [ ] Login successful
- [ ] TOKEN saved to environment
- [ ] Token format valid (JWT)

### **Schedule Retrieval (Phase 3)**
- [ ] GET schedule for today succeeds
- [ ] Schedule includes project location
- [ ] Schedule includes assignment times
- [ ] Schedule includes post info

### **Check-In Valid Cases (Phase 4.1-4.2)**
- [ ] Check-in ON TIME (09:10) → HADIR ✓
- [ ] Check-in LATE (09:25) → HADIR TELAT ✓
- [ ] Correct time calculations

### **Check-In Invalid Cases (Phase 4.3-4.4)**
- [ ] Check-in TOO LATE (09:35) → 403 ✓
- [ ] Check-in OUT OF RANGE → 403 ✓
- [ ] Correct error messages

### **Attendance Operations (Phase 5)**
- [ ] View detail returns correct data
- [ ] Check-out calculates overtime
- [ ] List returns paginated results

### **Authorization (Phase 6)**
- [ ] Role-based access control works
- [ ] Unauthorized access denied
- [ ] Data filtering by role correct

---

## **TROUBLESHOOTING**

| Symptom | Diagnosis | Fix |
|---------|-----------|-----|
| Login fails (401) | Invalid credentials | Check email/password in .env |
| No schedule found | Missing data | `php artisan db:seed` |
| Location rejected | Wrong coordinates | Use: -6.200050, 106.816700 |
| "Already checked in" | Already have attendance today | Delete: `Attendance::where('user_id', 5)->where('date', today())->delete()` |
| File upload fails | Wrong format/size | Use JPG < 1MB |
| 403 Forbidden | Authorization failed | Check user role |

---

## **SUCCESS CRITERIA**

**System ready for production when:**
✅ All API endpoints respond correctly  
✅ Authentication and authorization working  
✅ Time validation accurate  
✅ Location validation accurate  
✅ Database records created correctly  
✅ All status statuses calculated correctly  
✅ Error handling informative  

---

## **NEXT STEPS**

After successful testing:

1. **Load Testing**
   - Test with multiple concurrent users
   - Monitor response times
   - Check database performance

2. **Edge Case Testing**
   - Midnight shift cross-day handoff
   - GPS spoofing scenarios
   - Network failure handling

3. **Production Deployment**
   - Setup monitoring
   - Configure log rotation
   - Setup automated backups
   - Configure alerting

---

**🎉 Testing Complete! System Ready for Production!**
