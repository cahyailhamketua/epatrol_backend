# 🏗️ ATTENDANCE SYSTEM ARCHITECTURE

## **SYSTEM OVERVIEW**

```
┌─────────────────────────────────────────────────────────────────┐
│                   ATTENDANCE SYSTEM FLOW                        │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  1. USER AUTHENTICATION                                        │
│     Login → Get TOKEN (Sanctum)                               │
│                                                                 │
│  2. FETCH SCHEDULE                                            │
│     GET /users/{id}/schedules?date=TODAY                      │
│     → Project, Assignment, Post data                          │
│                                                                 │
│  3. CHECK-IN                                                  │
│     POST /attendances/check-in                                │
│     ├─ Validate time (grace period + 15 min buffer)          │
│     ├─ Validate location (Haversine distance)                │
│     └─ Create attendance record                              │
│                                                                 │
│  4. WORK HOURS                                                │
│     Perform assigned duties                                  │
│                                                                 │
│  5. CHECK-OUT                                                 │
│     POST /attendances/check-out                              │
│     ├─ Validate location                                     │
│     ├─ Calculate overtime                                    │
│     └─ Update attendance record                              │
│                                                                 │
│  6. VIEW ATTENDANCE                                           │
│     GET /attendances/{id}  (detail)                          │
│     GET /attendances       (list with filters)               │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

---

## **DATA MODEL RELATIONSHIPS**

```
User (1:M)
├─ Schedule (1:M)
│  ├─ Project (M:1)  ← Location reference
│  ├─ Assignment (M:1)  ← Time & grace period
│  ├─ Post (M:1)  ← Assignment location type
│  └─ Attendance (1:M)
│     ├─ user_id (FK)
│     ├─ project_id (FK) ← From schedule.project_id
│     ├─ post_id (FK) ← From schedule.post_id
│     └─ status → Computed from times

Organization (1:M)
├─ Project (1:M)  ← Has location_latitude/longitude/radius
│  └─ Schedule (1:M)
│     └─ Attendance (1:M)
└─ PostType
   └─ Post (1:M)
      └─ Schedule (1:M)

Assignment (1:M)
├─ Schedule (1:M)  ← Has start_time, end_time, grace_period
└─ Attendance (via Schedule)
```

---

## **KEY CONCEPTS**

### **1. THREE TIME REFERENCES**

| Reference | Source | Purpose | Example |
|-----------|--------|---------|---------|
| **Device Time** | Request.current_time | User's local time | 2026-02-12 09:10:30 |
| **Assignment Time** | Assignment.start_time, end_time | Work hours | 09:00 - 17:00 |
| **Grace Period** | Assignment.grace_period | Late tolerance | 15 minutes |

### **2. TIME VALIDATION LOGIC**

```
GRACE DEADLINE = Assignment.start_time + Assignment.grace_period
ABSOLUTE DEADLINE = Assignment.start_time + (Assignment.grace_period * 2)

CHECK-IN RULES:
├─ before grace_deadline 
│  └─ Status: HADIR (On time)
├─ between grace_deadline and absolute_deadline
│  └─ Status: HADIR TELAT (Late)
└─ after absolute_deadline
   └─ Status: REJECTED (403 Forbidden)

OVERTIME RULES:
├─ checkout_time <= end_time
│  └─ Status: HADIR (Normal)
├─ checkout_time > end_time
│  └─ Status: HADIR LEMBUR (Overtime)
```

### **3. LOCATION VERIFICATION**

```
REFERENCE LOCATION = Project location (from database)
  - latitude: Project.location_latitude
  - longitude: Project.location_longitude
  - radius: Project.radius (in meters)

DEVICE LOCATION = From check-in/check-out request
  - latitude: From request.latitude
  - longitude: From request.longitude

DISTANCE CALCULATION = Haversine formula
  → Returns distance in meters

VALIDATION = distance <= radius
  ├─ YES → Location valid, proceed
  └─ NO → Location invalid, reject (403)
```

### **4. STATUS COMPUTATION**

```
Attendance Status = Function(check_in_time, check_out_time, grace_period)

Check-in Status:
├─ On time    → "HADIR"
├─ Late       → "HADIR TELAT"
└─ Too late   → ❌ REJECTED

Final Status (after checkout):
├─ On time + Normal checkout     → "HADIR"
├─ On time + Overtime checkout   → "HADIR LEMBUR"
├─ Late + Normal checkout        → "HADIR TELAT"
├─ Late + Overtime checkout      → "HADIR LEMBUR"
└─ Other combinations            → Custom status
```

---

## **LAYER ARCHITECTURE**

```
┌──────────────────────────────────────┐
│     PRESENTATION LAYER               │
│  (Controllers, Request/Response)     │
├──────────────────────────────────────┤
│   AttendanceController               │
│   - checkIn()                        │
│   - checkOut()                       │
│   - patrolScan()                     │
│   - show()                           │
│   - index()                          │
│   - formatAttendanceResponse()       │
│   - calculateDistance()              │
└──────────────────────────────────────┘
              ↓
┌──────────────────────────────────────┐
│     BUSINESS LOGIC LAYER             │
│        (Services, Policies)          │
├──────────────────────────────────────┤
│   AttendancePolicy                   │
│   - view()                           │
│   - viewAny()                        │
│   - create()                         │
│   - update()                         │
│   - delete()                         │
│                                      │
│   OTHER: Calculate time/location     │
└──────────────────────────────────────┘
              ↓
┌──────────────────────────────────────┐
│      DATA ACCESS LAYER               │
│   (Models, Queries, ORM)             │
├──────────────────────────────────────┤
│   Attendance Model & Relations       │
│   Schedule Model & Relations         │
│   User, Project, Assignment, Post    │
│   Database Operations (Eloquent)     │
└──────────────────────────────────────┘
              ↓
┌──────────────────────────────────────┐
│      DATABASE LAYER                  │
│  (PostgreSQL/MySQL, Tables)          │
└──────────────────────────────────────┘
```

---

## **REQUEST/RESPONSE FLOW**

### **Check-In Flow**

```
CLIENT REQUEST
│
├─ Method: POST
├─ URL: /api/attendances/check-in
├─ Headers: Authorization: Bearer TOKEN
└─ Body: 
   ├─ latitude: -6.200050
   ├─ longitude: 106.816700
   ├─ current_time: "2026-02-12 09:10:30"
   └─ selfie_photo: [image file]
        │
        ↓
   CONTROLLER (AttendanceController::checkIn)
   │
   ├─ Authenticate: Sanctum middleware
   ├─ Validate input: latitude, longitude, current_time, selfie_photo
   ├─ Fetch schedule: Today's date + authenticated user
   ├─ Extract data: project, assignment, post
   ├─ Validate time: current_time vs assignment time
   ├─ Validate location: Haversine distance
   ├─ Calculate status: HADIR or HADIR TELAT
   ├─ Create attendance record
   └─ Format response
        │
        ↓
   SERVER RESPONSE
   │
   ├─ Status: 201 Created (success)
   │          or 400 Bad Request (validation)
   │          or 403 Forbidden (time/location)
   │          or 401 Unauthorized (auth)
   └─ Body:
      ├─ message: "Absen masuk berhasil."
      └─ data:
         ├─ id: 42
         ├─ timing: { check_in_at, late_minutes, ... }
         ├─ status: { computed_status, ... }
         └─ schedule: { assignment, post, ... }
```

### **Check-Out Flow**

```
CLIENT REQUEST
│
├─ Method: POST
├─ URL: /api/attendances/check-out
├─ Headers: Authorization: Bearer TOKEN
└─ Body:
   ├─ attendance_id: 42
   ├─ latitude: -6.200050
   ├─ longitude: 106.816700
   └─ current_time: "2026-02-12 17:30:00"
        │
        ↓
   CONTROLLER (AttendanceController::checkOut)
   │
   ├─ Authenticate: Sanctum middleware
   ├─ Validate input: attendance_id, location, time
   ├─ Find attendance record
   ├─ Check authorization: User owns this attendance?
   ├─ Validate location: Haversine distance
   ├─ Calculate overtime: checkout_time vs assignment.end_time
   ├─ Update attendance record
   ├─ Calculate final status: HADIR or HADIR LEMBUR
   └─ Format response
        │
        ↓
   SERVER RESPONSE
   │
   ├─ Status: 200 OK (success)
   │          or 400 Bad Request (validation)
   │          or 403 Forbidden (location or auth)
   │          or 404 Not Found (invalid attendance_id)
   └─ Body:
      ├─ message: "Absen pulang berhasil."
      └─ data:
         ├─ id: 42
         ├─ timing: { check_in_at, check_out_at, overtime_minutes, ... }
         ├─ status: { computed_status, ... }
         └─ schedule: { assignment, post, ... }
```

---

## **AUTHORIZATION MATRIX**

```
┌─────────────────────────────────────────────────────────────┐
│                    ROLE-BASED PERMISSIONS                  │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│ DEV (System Administrator)                                │
│ ├─ /api/attendances             → view all ✓             │
│ ├─ /api/attendances/{id}        → view any ✓             │
│ └─ /api/attendances/*           → manage all ✓            │
│                                                             │
│ HO (Human Resources)                                       │
│ ├─ /api/attendances             → view own org ✓         │
│ ├─ /api/attendances/{id}        → view own org ✓         │
│ └─ /api/attendances/check-*     → ✗ (Forbidden)         │
│                                                             │
│ ANGGOTA (Employee)                                         │
│ ├─ POST /check-in               → own device ✓           │
│ ├─ POST /check-out              → own device ✓           │
│ ├─ GET /attendances             → own records ✓          │
│ ├─ GET /attendances/{id}        → own record ✓           │
│ └─ Other operations             → ✗ (Forbidden)          │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

---

## **VALIDATION RULES**

### **Check-In Validation**

```
latitude
  ├─ Required: Yes
  ├─ Type: Numeric
  ├─ Range: -90 to 90
  └─ Example: -6.200050

longitude
  ├─ Required: Yes
  ├─ Type: Numeric
  ├─ Range: -180 to 180
  └─ Example: 106.816700

current_time
  ├─ Required: Yes
  ├─ Format: YYYY-MM-DD HH:MM:SS
  └─ Example: 2026-02-12 09:10:30

selfie_photo
  ├─ Required: Yes
  ├─ Type: File (image)
  ├─ Size: < 1MB
  └─ Formats: jpg, png, jpeg, gif
```

### **Check-Out Validation**

```
attendance_id
  ├─ Required: Yes
  ├─ Type: Integer
  ├─ Must exist: In attendances table
  └─ Must own: Authenticated user must own this record

latitude
  ├─ Required: Yes
  ├─ Type: Numeric
  ├─ Range: -90 to 90
  └─ Example: -6.200050

longitude
  ├─ Required: Yes
  ├─ Type: Numeric
  ├─ Range: -180 to 180
  └─ Example: 106.816700

current_time
  ├─ Required: Yes
  ├─ Format: YYYY-MM-DD HH:MM:SS
  └─ Must be: After check-in time
```

---

## **ERROR HANDLING**

```
┌────────────────────────────────────────┐
│         HTTP STATUS CODES              │
├────────────────────────────────────────┤
│ 200 OK                                 │
│ ├─ Check-out successful                │
│ ├─ View attendance successful          │
│ └─ List attendances successful         │
│                                        │
│ 201 Created                            │
│ └─ Check-in successful                 │
│                                        │
│ 400 Bad Request                        │
│ ├─ Invalid input format                │
│ ├─ Missing required fields             │
│ ├─ Validation failed                   │
│ └─ Response: { "message": "...",       │
│               "errors": { ... } }      │
│                                        │
│ 401 Unauthorized                       │
│ ├─ No authentication token             │
│ ├─ Invalid/expired token               │
│ └─ Response: { "message":              │
│    "Unauthenticated" }                 │
│                                        │
│ 403 Forbidden                          │
│ ├─ Time validation failed              │
│ ├─ Location validation failed          │
│ ├─ Authorization policy failed         │
│ └─ Response: { "message": "...",       │
│               "reason": "..." }        │
│                                        │
│ 404 Not Found                          │
│ ├─ Attendance not found                │
│ ├─ Schedule not found                  │
│ └─ Resource not found                  │
│                                        │
│ 422 Unprocessable Entity               │
│ ├─ Database constraint failed          │
│ ├─ Unique violation                    │
│ └─ Response: { "message": "...",       │
│               "errors": { ... } }      │
│                                        │
│ 500 Server Error                       │
│ ├─ Unexpected exception                │
│ ├─ Database error                      │
│ └─ Response: { "message":              │
│    "Server error" }                    │
│                                        │
└────────────────────────────────────────┘
```

---

## **DATABASE SCHEMA**

```
attendances
├─ id (PK)
├─ user_id (FK) → users.id
├─ project_id (FK) → projects.id
├─ post_id (FK) → posts.id
├─ date (DATE)
├─ checkin_lat (DECIMAL)
├─ checkin_lng (DECIMAL)
├─ checkin_time (TIME)
├─ checkout_lat (DECIMAL)
├─ checkout_lng (DECIMAL)
├─ checkout_time (TIME)
├─ selfie_photo (VARCHAR)
├─ status (VARCHAR) → HADIR, HADIR TELAT, HADIR LEMBUR, etc.
├─ created_at (TIMESTAMP)
└─ updated_at (TIMESTAMP)

schedules
├─ id (PK)
├─ user_id (FK) → users.id
├─ project_id (FK) → projects.id
├─ assignment_id (FK) → assignments.id
├─ post_id (FK) → posts.id
├─ date (DATE)
├─ created_at (TIMESTAMP)
└─ updated_at (TIMESTAMP)

projects
├─ id (PK)
├─ organization_id (FK) → organizations.id
├─ name (VARCHAR)
├─ location_latitude (DECIMAL)
├─ location_longitude (DECIMAL)
├─ radius (INT) → in meters
├─ created_at (TIMESTAMP)
└─ updated_at (TIMESTAMP)

assignments
├─ id (PK)
├─ code (VARCHAR) → P, S, M, etc.
├─ name (VARCHAR) → Pagi, Siang, Malam
├─ start_time (TIME)
├─ end_time (TIME)
├─ grace_period (INT) → in minutes
├─ is_off (BOOLEAN)
├─ created_at (TIMESTAMP)
└─ updated_at (TIMESTAMP)

posts
├─ id (PK)
├─ name (VARCHAR)
├─ type (VARCHAR) → static, mobile, etc.
├─ created_at (TIMESTAMP)
└─ updated_at (TIMESTAMP)

users
├─ id (PK)
├─ name (VARCHAR)
├─ email (VARCHAR)
├─ password (VARCHAR)
├─ role (VARCHAR) → dev, ho, anggota
├─ project_id (FK) → projects.id
├─ created_at (TIMESTAMP)
└─ updated_at (TIMESTAMP)
```

---

## **FILE STRUCTURE**

```
app/
├─ Http/
│  └─ Controllers/
│     └─ Api/
│        ├─ AttendanceController.php      ← Main logic
│        ├─ ScheduleController.php        ← Schedule queries
│        ├─ AuthController.php            ← Login/auth
│        └─ ...OtherControllers.php
├─ Models/
│  ├─ Attendance.php                      ← Attendances table
│  ├─ Schedule.php                        ← Schedules table
│  ├─ Project.php                         ← Projects table
│  ├─ Assignment.php                      ← Assignments table
│  ├─ Post.php                            ← Posts table
│  ├─ User.php                            ← Users table
│  └─ ...OtherModels.php
├─ Policies/
│  ├─ AttendancePolicy.php                ← Authorization
│  └─ ...OtherPolicies.php
└─ Providers/
   └─ AppServiceProvider.php              ← Policy registration

config/
├─ app.php
├─ database.php
├─ sanctum.php                            ← Token config
└─ ...

database/
├─ migrations/
│  ├─ *_create_attendances_table.php
│  ├─ *_create_schedules_table.php
│  ├─ *_create_projects_table.php
│  ├─ *_add_location_to_organizations_table.php
│  └─ ...
└─ seeders/
   ├─ DatabaseSeeder.php
   └─ ...Seeders.php

routes/
├─ api.php                                ← API endpoints
├─ web.php
└─ console.php

storage/
└─ logs/
   └─ laravel.log                         ← Debug logs

tests/
├─ Feature/
│  ├─ AttendanceTest.php
│  └─ ...
└─ Unit/
   └─ ...
```

---

## **DEVELOPMENT WORKFLOW**

```
┌─────────────────────────────────────────────────────────────┐
│              LOCAL DEVELOPMENT WORKFLOW                      │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│  1. SETUP                                                   │
│     $ git clone ...                                         │
│     $ composer install                                      │
│     $ cp .env.example .env                                  │
│     $ php artisan key:generate                              │
│     $ php artisan migrate:fresh --seed                      │
│                                                              │
│  2. DEVELOPMENT                                             │
│     $ php artisan serve                                     │
│     → Edit controllers/models/migrations                    │
│     → Test with Postman                                     │
│     → Check logs: tail -f storage/logs/laravel.log         │
│                                                              │
│  3. TESTING                                                 │
│     $ php artisan test                                      │
│     → Or use Postman collection (see TESTING_COMPLETE_GUIDE.md)
│                                                              │
│  4. DATABASE RESET (if needed)                             │
│     $ php artisan migrate:fresh --seed                      │
│     → Resets all data, re-runs seeds                        │
│                                                              │
│  5. COMMIT                                                  │
│     $ git add .                                             │
│     $ git commit -m "Feature: ..."                          │
│     $ git push origin feature/...                           │
│                                                              │
│  6. PRODUCTION DEPLOY                                       │
│     $ git pull                                              │
│     $ composer install --no-dev                             │
│     $ php artisan migrate --force                           │
│     $ php artisan cache:clear                               │
│     → Monitor: tail -f storage/logs/laravel.log            │
│                                                              │
└─────────────────────────────────────────────────────────────┘
```

---

## **PERFORMANCE CONSIDERATIONS**

```
QUERY OPTIMIZATION:
├─ Eager load relations: with('project', 'assignment')
├─ Use select() for specific columns
├─ Index frequently filtered columns (date, user_id, project_id)
└─ Paginate large result sets (->paginate(15))

CACHING:
├─ Cache project locations (rarely change)
├─ Cache assignment time rules
└─ Use Redis for session/token storage

VALIDATION:
├─ Validate on client-side for UX
├─ Validate on server-side for security
└─ Use Laravel form request validation

LOGGING:
├─ Log failed validations
├─ Log authorization denials
├─ Monitor slow queries (> 1s)
└─ Alert on repeated errors
```

---

## **SECURITY CONSIDERATIONS**

```
AUTHENTICATION:
├─ Use Laravel Sanctum for tokens
├─ Token expiration: Set reasonable timeout
├─ Refresh tokens: Implement if needed
└─ Invalidate tokens on logout

AUTHORIZATION:
├─ Use policies for resource-level access
├─ Check ownership before operations
├─ Role-based access control (RBAC)
└─ Audit sensitive operations

DATA PROTECTION:
├─ Hash password on login
├─ Encrypt sensitive files (photos)
├─ Validate input (no SQL injection)
├─ Escape output (no XSS)
└─ Use HTTPS in production

GPS SPOOFING PREVENTION:
├─ Validate reasonable coordinates
├─ Reject impossible locations
├─ Check distance speed (0→1km in 1sec = false)
├─ Implement device fingerprinting
└─ Log suspicious activity
```

---

**System ready for production use! 🚀**
