# ⚡ TESTING TIPS & BEST PRACTICES

## **1. BEFORE TESTING**

### **Verification Checklist**
```bash
# 1. Check database is ready
php artisan tinker
# Di tinker, run:
# Schedule::today()->count()          # Should > 0
# Project::where('id', 1)->first()   # Should have location_latitude/longitude
# Assignment::first()                # Should have start_time, grace_period

# 2. Check server is running
php artisan serve
# Should show: Application running on [http://127.0.0.1:8000]

# 3. Check Laravel logs
tail -f storage/logs/laravel.log
```

### **Data Reset**
```bash
# If you messed up data, reset everything:
php artisan migrate:fresh
php artisan db:seed

# Or just reset one table:
php artisan db:seed --class=ActivityAssignmentTimeSeeder
```

---

## **2. POSTMAN TIPS**

### **Auto-Set Variables (Pre-request Script Template)**
```javascript
// In Pre-request Scripts tab
if (!pm.environment.get("TOKEN")) {
    console.log("⚠ TOKEN not set, run login first!");
}

// Set current date/time based on device time
let now = new Date();
pm.environment.set("CURRENT_TIME", now.toISOString().split('.')[0].replace('T', ' '));
```

### **Error Capture (Test Script Template)**
```javascript
// In Test Scripts tab
if (pm.response.code >= 400) {
    let response = pm.response.json();
    console.log("❌ ERROR: " + response.message);
    console.log("Details: " + JSON.stringify(response, null, 2));
} else {
    console.log("✓ Success!");
}
```

### **Export Environment**
```
1. Klik Environments
2. Pilih "Attendance Testing"
3. Klik menu (⋯)
4. Download Environment
5. Share dengan team
```

### **Create Collection dari Requests**
```
1. Pilih requests yang sudah dibuat
2. Klik menu (⋯)
3. "Save as Collection"
4. Nama: "Attendance API Tests"
5. Share di Postman Cloud
```

---

## **3. TIME TESTING SCENARIOS**

### **Scenario 1: Morning Check-in (Normal)**
```
Assignment: 09:00 - 17:00 (Grace: 15 min)
Test times:
├─ 08:55 → HADIR (early)
├─ 09:00 → HADIR (on time)
├─ 09:10 → HADIR (late but within grace)
├─ 09:15 → HADIR (at grace deadline)
├─ 09:20 → HADIR TELAT (after grace)  
├─ 09:30 → HADIR TELAT (at absolute deadline)
├─ 09:35 → ❌ REJECTED (after absolute deadline)
└─ 10:00 → ❌ REJECTED (way too late)
```

### **Scenario 2: Overtime Check-out**
```
Assignment END time: 17:00
Test checkout times:
├─ 16:55 → Early checkout (shouldn't happen)
├─ 17:00 → On time checkout
├─ 17:30 → LEMBUR 30 menit
├─ 18:00 → LEMBUR 60 menit
└─ 00:00 (next day) → LEMBUR overnight
```

### **Scenario 3: Day Off Assignment**
```
If assignment is_off = 1:
├─ User shouldn't see in schedule (??)
├─ Or show as "OFF" status
├─ Check-in should be rejected (?)
```

---

## **4. LOCATION TESTING SCENARIOS**

### **Location Setup in Postman**
```
OFFICE LOCATION (Project):
- Latitude:  -6.200000
- Longitude: 106.816667
- Radius:    100 meters

DEVICE LOCATIONS:
- At Office:      -6.200050, 106.816700     (≈ 10 meters away)
- Nearby:         -6.200500, 106.817000     (≈ 60 meters away)
- Out of Range:   -6.210000, 106.825000     (≈ 1.2+ km away)
- Very Far:       0.000000, 0.000000        (≈ 18,000 km away!)
```

### **Haversine Distance Formula**
```
Distance = 2 * R * arcsin(sqrt(sin²((lat2-lat1)/2) + cos(lat1) * cos(lat2) * sin²((lng2-lng1)/2)))

Where:
- R = Earth radius (6,371 km)
- lat, lng in radians
- Result in kilometers × 1000 for meters
```

### **Test Cases:**
```javascript
// Test 1: At office boundary
lat: -6.200050, lng: 106.816700
→ Distance ≈ 10m < 100m → ✓ ACCEPTED

// Test 2: Near boundary  
lat: -6.200500, lng: 106.817000
→ Distance ≈ 60m < 100m → ✓ ACCEPTED

// Test 3: Just outside radius
lat: -6.201000, lng: 106.817500
→ Distance ≈ 110m > 100m → ✗ REJECTED

// Test 4: GPS spoofing attempt
lat: 0, lng: 0
→ Distance ≈ 18,000km → ✗ REJECTED
```

---

## **5. AUTHORIZATION TESTING**

### **User Roles Hierarchy**
```
DEV (id: 1)
├─ Can: View all, Create, Update, Delete
├─ Routes: All endpoints
└─ Filter: None (see all organizations)

HO (id: 2)
├─ Can: View, Approve
├─ Routes: List, view, approve
└─ Filter: All data

ANGGOTA (id: 5)
├─ Can: Check-in/check-out, view own
├─ Routes: check-in, check-out, view own
└─ Filter: Own data only
```

### **Test Authorization Denial**
```
1. Login as ANGGOTA (id: 5)
2. Try to view other user's attendance:
   GET /api/attendances?user_id=6
   → Should return ✗ 403 FORBIDDEN

3. Try to delete attendance:
   DELETE /api/attendances/42
   → Should return ✗ 403 FORBIDDEN

4. Try to create manual attendance:
   POST /api/attendances
   → Should return ✗ 403 FORBIDDEN (only check-in allowed)
```

---

## **6. RESPONSE VALIDATION**

### **Standard Response Format**
```json
{
  "message": "Success message",
  "data": {
    "id": 1,
    "field": "value"
  },
  "pagination": {
    "total": 100,
    "per_page": 15,
    "current_page": 1,
    "last_page": 7
  }
}
```

### **Validation Checklist**
- [ ] message field present for all responses
- [ ] data field contains main payload
- [ ] pagination present in list endpoints
- [ ] Date format: YYYY-MM-DD HH:MM:SS
- [ ] Status codes correct (200, 201, 400, 403, 404, 500)

---

## **7. DEBUGGING TIPS**

### **Check Request Body**
```bash
# In terminal while server running:
tail -f storage/logs/laravel.log | grep "Request:"
```

### **Log SQL Queries**
```bash
# In terminal:
tail -f storage/logs/laravel.log | grep "SQL:"
```

### **Use Laravel Tinker**
```bash
$ php artisan tinker

# Check schedule
>>> Schedule::where('user_id', 5)->with('assignment', 'project', 'post')->first()

# Check attendance
>>> Attendance::latest()->with('schedule')->first()

# Check location calculation
>>> $att = Attendance::find(42);
>>> $project = $att->schedule->project;
>>> distance_calc($att->checkin_lat, $att->checkin_lng, $project->location_latitude, $project->location_longitude);
```

### **Enable Query Logging**
```php
// In app/Providers/AppServiceProvider.php
use Illuminate\Support\Facades\DB;

public function boot() {
    if (true) { // Set to true for debugging
        DB::listen(function ($query) {
            \Log::info($query->sql, $query->bindings);
        });
    }
}
```

---

## **8. COMMON ISSUES & FIXES**

### **Issue: "No schedule found for today"**
```
Fix:
1. Check database: SELECT * FROM schedules WHERE date = CURDATE() AND user_id = 5;
2. If empty, create manually: 
   Schedule::create(['user_id' => 5, 'date' => now(), ...])
3. Or use different date in request
```

### **Issue: "Token expired"**
```
Fix:
1. Run login again to get new token
2. Update TOKEN in Postman environment
3. Check Sanctum config: config/sanctum.php
   - expiration should be reasonable (default: null = never expire)
```

### **Issue: "Latitude/longitude invalid"**
```
Fix:
1. Check format: Must be decimal (e.g., -6.200000, not -622')
2. Check range: 
   - Latitude: -90 to 90
   - Longitude: -180 to 180
3. Check precision: Use at least 6 decimal places
```

### **Issue: "Distance calculation wrong"**
```
Fix:
1. Verify project location is set: SELECT location_latitude, location_longitude FROM projects WHERE id = 1;
2. Check radius: SELECT radius FROM projects WHERE id = 1;
3. Test with Postman: Use known coordinates and verify distance
4. Check units: Our calculation returns METERS
```

### **Issue: "Attendance already checked in"**
```
Fix:
1. User can only check-in once per day
2. Delete previous attendance if testing:
   Attendance::where('user_id', 5)->where('date', now())->delete()
3. Or create new schedule for different date
```

---

## **9. PERFORMANCE TESTING**

### **Load Test Single Check-in**
```bash
# Using Apache Bench
ab -n 100 -c 10 -H "Authorization: Bearer TOKEN" \
   -H "Content-Type: application/json" \
   -d '{"lat":-6.2,"lng":106.816,"time":"2026-02-12 09:10:00"}' \
   http://localhost:8000/api/attendances/check-in
```

### **Monitor Performance**
```bash
# In real terminal:
watch -n 1 'ps aux | grep "php artisan serve"'
```

### **Database Query Performance**
```bash
# Slow queries
tail -f storage/logs/laravel.log | grep "Slow query"

# All queries (if enabled)
tail -f storage/logs/laravel.log | grep "SELECT"
```

---

## **10. TEAM TESTING WORKFLOW**

### **Parallel Testing Setup**
```
Team member 1: Test Check-in scenarios (STEP 3)
Team member 2: Test Check-out scenarios (STEP 5)
Team member 3: Test Authorization (roles)
Team member 4: Test Location edge cases

→ Share Postman collection in Postman Cloud
→ Merge results in spreadsheet
→ Report bugs if found
```

### **Automated Testing (CI/CD)**
```bash
# Run PHPUnit tests
php artisan test --env=testing

# Run specific test
php artisan test tests/Feature/AttendanceTest.php

# With coverage
php artisan test --coverage
```

---

## **QUICK COMMAND REFERENCE**

```bash
# Reset everything
php artisan migrate:fresh && php artisan db:seed

# Check server
curl http://localhost:8000/api/user

# Login
curl -X POST http://localhost:8000/api/login \
  -H "Content-Type: application/json" \
  -d '{"email":"user@example.com","password":"password"}'

# Check schedule
curl -X GET "http://localhost:8000/api/users/5/schedules?date=2026-02-12" \
  -H "Authorization: Bearer TOKEN"

# Check-in
curl -X POST http://localhost:8000/api/attendances/check-in \
  -H "Authorization: Bearer TOKEN" \
  -F "latitude=-6.200050" \
  -F "longitude=106.816700" \
  -F "current_time=2026-02-12 09:10:30" \
  -F "selfie_photo=@image.jpg"

# View logs
tail -f storage/logs/laravel.log
```

---

**Happy Testing! 🧪**
