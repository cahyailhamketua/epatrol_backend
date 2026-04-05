# 🔐 ATTENDANCE AUTHORIZATION & POLICIES

## **OVERVIEW**

Sistem **Role-Based Authorization** menggunakan Laravel Policies untuk mengontrol akses ke resources Attendance.

---

## **ROLES & PERMISSIONS**

### **1. DEV (Developer)**
```
✓ Lihat SEMUA attendance di system
✓ Check-in/Check-out apa saja
✓ Scan patrol mana saja
✓ Lihat detail attendance user manapun
```

### **2. HO (Head of Organization)**
```
✓ Lihat attendance dalam ORGANIZATION miliknya
✓ Check-in/Check-out di project organization
✓ Kelola staff organization
✗ Tidak bisa akses organization lain
```

### **3. ADMIN_PROJECT (Project Administrator)**
```
✓ Lihat attendance dalam PROJECT miliknya
✓ Check-in/Check-out di project
✓ Kelola schedule project
✗ Tidak bisa akses project lain
```

### **4. KOMANDAN_REGU (Team Leader)**
```
✓ Lihat attendance anggota dalam PROJECT
✓ Check-in/Check-out sendiri
✓ Scan patrol sendiri
✗ Tidak bisa lihat detail user lain kecuali team member
```

### **5. ANGGOTA (Staff/Member)**
```
✓ Check-in/Check-out diri sendiri
✓ Scan patrol diri sendiri
✓ Lihat attendance diri sendiri
✗ Tidak bisa lihat atau akses user lain
```

---

## **ATTENDANCE POLICY METHODS**

### **viewAny() - LIST ATTENDANCE**

**Method:** `GET /api/attendances`

**Authorization:**
```php
public function viewAny(User $user, ?Project $project = null): bool
```

**Rules:**
| Role | Akses | Keterangan |
|------|-------|-----------|
| dev | ✓ ALL | Lihat semua attendance |
| ho | ✓ ORG | Lihat dalam organization |
| admin_project | ✓ PROJECT | Lihat dalam project |
| komandan_regu | ✓ PROJECT | Lihat dalam project |
| anggota | ✓ SELF | Lihat milik sendiri |

**Example Request:**
```bash
GET /api/attendances?date=2026-02-10&user_id=5
Authorization: Bearer TOKEN
```

**Response (Dev - Semua Attendance):**
```json
{
  "data": [
    {
      "id": 1,
      "user_id": 1,
      "assignment_code": "P",
      "check_in_at": "09:10:30",
      "computed_status": "HADIR"
    },
    {
      "id": 2,
      "user_id": 2,
      "assignment_code": "M",
      "check_in_at": "17:05:30",
      "computed_status": "HADIR TELAT"
    }
  ],
  "pagination": {...}
}
```

---

### **view() - VIEW SPECIFIC ATTENDANCE**

**Method:** `GET /api/attendances/{id}`

**Authorization:**
```php
public function view(User $user, Attendance $attendance): bool
```

**Rules:**
| Role | Bisa Akses | Kondisi |
|------|-----------|---------|
| dev | ✓ SEMUA | Tidak ada batasan |
| ho | ✓ ORG | Attendance dalam organization miliknya |
| admin_project | ✓ PROJECT | Attendance dalam project miliknya |
| komandan_regu | ✓ PROJECT | Attendance dalam project |
| anggota | ✓ SELF | Hanya attendance diri sendiri |

**Example:**
```bash
# Developer bisa lihat attendance siapapun
GET /api/attendances/42
Authorization: Bearer TOKEN_DEV
Response: 200 OK ✓

# Anggota bisa lihat attendance milik sendiri
GET /api/attendances/42  (milik user_id=5)
Authorization: Bearer TOKEN_ANGGOTA_5
Response: 200 OK ✓

# Anggota TIDAK bisa lihat attendance orang lain
GET /api/attendances/42  (milik user_id=6)
Authorization: Bearer TOKEN_ANGGOTA_5
Response: 403 Forbidden ✗
```

---

### **create() - CHECK-IN**

**Method:** `POST /api/attendances/check-in`

**Authorization:**
```php
public function create(User $user): bool
```

**Rules:**
```
✓ KOMANDAN_REGU bisa check-in
✓ ANGGOTA bisa check-in
✗ DEV tidak bisa check-in (hanya test)
✗ HO tidak bisa check-in
✗ ADMIN_PROJECT tidak bisa check-in
```

**Why?** 
- Check-in adalah aksi untuk pekerja di lapangan
- DEV, HO, Admin_Project adalah non-operational roles

**Example:**
```bash
# Anggota bisa check-in
POST /api/attendances/check-in
Authorization: Bearer TOKEN_ANGGOTA
Body: {project_id, latitude, longitude, current_time, selfie_photo}
Response: 201 Created ✓

# HO TIDAK bisa check-in
POST /api/attendances/check-in
Authorization: Bearer TOKEN_HO
Response: 403 Forbidden ✗
```

---

### **checkout() - CHECK-OUT**

**Method:** `POST /api/attendances/check-out`

**Authorization:**
```php
public function checkout(User $user, Attendance $attendance): bool
```

**Rules:**
```
1. User harus PEMILIK attendance (user_id === attendance.user_id)
2. Attendance harus sudah check-in (check_in_at != null)
3. Tidak boleh double check-out
```

**Example:**
```bash
# User bisa check-out attendance milik sendiri
POST /api/attendances/check-out
{
  "attendance_id": 42,
  "latitude": -6.200050,
  "longitude": 106.816700,
  "current_time": "2026-02-10 17:05:30"
}
Authorization: Bearer TOKEN_USER_ID_5 (pemilik attendance 42)
Response: 200 OK ✓

# User TIDAK bisa check-out attendance orang lain
POST /api/attendances/check-out
Authorization: Bearer TOKEN_USER_ID_6
Response: 403 Forbidden ✗

# Tidak bisa check-out jika belum check-in
POST /api/attendances/check-out (attendance.check_in_at = null)
Response: 403 Forbidden ✗
```

---

### **patrolScan() - PATROL POINT SCAN**

**Method:** `POST /api/attendances/patrol-scan`

**Authorization:**
```php
public function patrolScan(User $user, Attendance $attendance): bool
```

**Rules:**
```
1. User harus PEMILIK attendance
2. Attendance harus sudah check-in
3. Post harus bertipe 'mobile' (patrol route)
```

**Example:**
```bash
# User bisa scan patrol milik sendiri
POST /api/attendances/patrol-scan
{
  "attendance_id": 42,
  "patrol_point_id": 1,
  "latitude": -6.200100,
  "longitude": 106.816800,
  "photos": [file1, file2, file3, file4],
  "description_option": "aman",
  "current_time": "2026-02-10 12:30:45"
}
Authorization: Bearer TOKEN_USER_5 (pemilik attendance 42)
Response: 201 Created ✓

# User TIDAK bisa scan patrol orang lain
Authorization: Bearer TOKEN_USER_6
Response: 403 Forbidden ✗
```

---

## **POLICY CHECK IN CONTROLLER**

### **Contoh Implementasi:**

```php
class AttendanceController extends Controller
{
    public function index(Request $request)
    {
        // ✓ Check authorization
        $this->authorize('viewAny', Attendance::class);
        
        // Build query based on role
        $attendances = Attendance::where(...)->paginate();
        
        return response()->json(['data' => $attendances]);
    }

    public function show(Attendance $attendance)
    {
        // ✓ Check authorization untuk specific attendance
        $this->authorize('view', $attendance);
        
        return response()->json(['data' => $this->formatAttendanceResponse($attendance)]);
    }

    public function checkIn(Request $request)
    {
        // ✓ Check authorization class-level
        $this->authorize('create', Attendance::class);
        
        // ... create attendance logic
    }

    public function checkOut(Request $request)
    {
        $attendance = Attendance::find($request->attendance_id);
        
        // ✓ Check authorization untuk specific attendance
        $this->authorize('checkout', $attendance);
        
        // ... update attendance logic
    }

    public function patrolScan(Request $request)
    {
        $attendance = Attendance::find($request->attendance_id);
        
        // ✓ Check authorization
        $this->authorize('patrolScan', $attendance);
        
        // ... create patrol scan logic
    }
}
```

---

## **ERROR RESPONSES**

### **403 Forbidden - Authorization Failed**
```json
{
  "message": "This action is unauthorized."
}
```

**Reasons:**
- User tidak punya role yang sesuai
- HO mencoba akses attendance di organization lain
- Anggota mencoba akses attendance user lain
- Non-operational role mencoba check-in

### **401 Unauthorized - No Token**
```json
{
  "message": "Unauthenticated."
}
```

**Solution:** Login terlebih dahulu untuk dapat token

### **404 Not Found**
```json
{
  "message": "Absensi tidak ditemukan."
}
```

**Reasons:**
- Attendance ID tidak valid
- Attendance sudah dihapus

---

## **TESTING AUTHORIZATION**

### **Setup Test Users**
```php
// database/seeders/UserSeeder.php

$users = [
    ['email' => 'dev@example.com', 'role' => 'dev'],
    ['email' => 'ho@example.com', 'role' => 'ho', 'organization_id' => 1],
    ['email' => 'admin@example.com', 'role' => 'admin_project', 'project_id' => 1],
    ['email' => 'leader@example.com', 'role' => 'komandan_regu', 'project_id' => 1],
    ['email' => 'staff@example.com', 'role' => 'anggota', 'project_id' => 1],
];
```

### **Test Case 1: DEV Lihat Semua**
```bash
TOKEN=$(curl -X POST /api/login -d '{"email":"dev@example.com","password":"password"}' | jq -r '.access_token')

# DEV bisa lihat semua attendance
curl -X GET /api/attendances \
  -H "Authorization: Bearer $TOKEN"
# Response: 200 OK ✓ (semua attendance)
```

### **Test Case 2: ANGGOTA Lihat Milik Sendiri**
```bash
TOKEN=$(curl -X POST /api/login -d '{"email":"staff@example.com","password":"password"}' | jq -r '.access_token')

# ANGGOTA lihat milik sendiri
curl -X GET /api/attendances \
  -H "Authorization: Bearer $TOKEN"
# Response: 200 OK ✓ (hanya milik user 5)

# ANGGOTA detail attendance milik sendiri
curl -X GET /api/attendances/42 \
  -H "Authorization: Bearer $TOKEN"
# Response: 200 OK ✓ (jika attendance 42 milik user 5)

# ANGGOTA lihat attendance orang
curl -X GET /api/attendances/43 \
  -H "Authorization: Bearer $TOKEN"
# Response: 403 Forbidden ✗ (attendance 43 milik user lain)
```

### **Test Case 3: HO Lihat Organization**
```bash
TOKEN=$(curl -X POST /api/login -d '{"email":"ho@example.com","password":"password"}' | jq -r '.access_token')

# HO lihat attendance dalam organization
curl -X GET /api/attendances \
  -H "Authorization: Bearer $TOKEN"
# Response: 200 OK ✓ (semua dalam organization 1)

# HO tidak bisa check-in
curl -X POST /api/attendances/check-in \
  -H "Authorization: Bearer $TOKEN" \
  -d '{...}'
# Response: 403 Forbidden ✗ (HO bukan operational role)
```

---

## **FLOW DIAGRAM**

```
User melakukan request ke Attendance API
            ↓
Request diterima Controller
            ↓
Controller call: $this->authorize('action', Attendance/Model)
            ↓
Laravel cek ke Policy
            ↓
Policy method call: public function action(User $user, ...): bool
            ↓
┌─────────────────────────────┐
│  Cek User Role & Ownership  │
│  Return true/false          │
└──────────┬──────────────────┘
           ↓
      ┌────┴────┐
      │          │
   true        false
      │          │
      ↓          ↓
  Lanjut      403 Forbidden
  Process     Error Response
```

---

## **SUMMARY TABLE**

| Action | DEV | HO | Admin | Komandan | Anggota |
|--------|-----|----|----- |----------|---------|
| viewAny | ✓ ALL | ✓ ORG | ✓ PRJ | ✓ PRJ | ✓ SELF |
| view(specific) | ✓ | ✓ ORG | ✓ PRJ | ✓ PRJ | ✓ SELF |
| create/checkIn | ✗ | ✗ | ✗ | ✓ | ✓ |
| checkout | ✗ | ✗ | ✗ | ✓ | ✓ |
| patrolScan | ✗ | ✗ | ✗ | ✓ | ✓ |

**Legend:**
- ✓ = Allowed
- ✗ = Forbidden
- ALL = Semua (seluruh system)
- ORG = Organization level
- PRJ = Project level
- SELF = User milik sendiri

---

Policies sudah aktivasi! 🔐 Setiap request sekarang akan di-authorize sesuai role.
