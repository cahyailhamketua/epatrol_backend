# 🚀 ATTENDANCE API TESTING GUIDE

## **STEP 1: Setup Database**

```bash
# 1a. Login ke MySQL
mysql -u root -p

# 1b. Select database
USE epatrol;

# 1c. Run setup script
source /path/to/DATABASE_TEST_SETUP.sql;

# Verify data
SELECT * FROM projects WHERE id = 1;
SELECT * FROM assignments WHERE project_id = 1;
SELECT * FROM schedules WHERE date = CURDATE();
```

---

## **STEP 2: Login & Get Token**

### **Option A: Using cURL**

```bash
curl -X POST http://localhost:8000/api/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "user1@example.com",
    "password": "password"
  }'
```

### **Option B: Using Postman**

1. Open Postman
2. Create new request
3. Method: `POST`
4. URL: `http://localhost:8000/api/login`
5. Body (JSON):
```json
{
  "email": "user1@example.com",
  "password": "password"
}
```
6. Send → Copy `access_token` from response

**Response:**
```json
{
  "user": {
    "id": 1,
    "email": "user1@example.com",
    "name": "User One"
  },
  "access_token": "YOUR_TOKEN_HERE",
  "token_type": "Bearer"
}
```

---

## **STEP 3: Test Check-In API**

### **Requirements:**

1. **Get Device Time** (from your system)
   ```javascript
   new Date().toISOString().slice(0, 19).replace('T', ' ')
   // Example: "2026-02-10 09:30:45"
   ```

2. **Get Device Location** (if you have GPS/geolocation)
   ```javascript
   navigator.geolocation.getCurrentPosition(pos => {
       console.log(pos.coords.latitude);
       console.log(pos.coords.longitude);
   });
   ```

3. **Or Use Test Coordinates**
   ```
   Latitude: -6.200050   (near project location)
   Longitude: 106.816700 (near project location)
   ```

4. **Prepare Base64 Image** (selfie photo)
   - Use any JPEG/PNG image
   - Convert to base64
   - Or use form-data in Postman

---

### **Postman: Test Scenario 1 (ON TIME)**

#### **Setup in Postman:**

**Tab: Authorization**
```
Type: Bearer Token
Token: [Paste your access_token here]
```

**Tab: Body → form-data**
```
Key: project_id      | Value: 1
Key: latitude        | Value: -6.200050
Key: longitude       | Value: 106.816700
Key: current_time    | Value: 2026-02-10 09:10:30
Key: selfie_photo    | Value: [Select image file]
```

**OR Tab: Body → raw (JSON)**
```json
{
  "project_id": 1,
  "latitude": -6.200050,
  "longitude": 106.816700,
  "current_time": "2026-02-10 09:10:30",
  "selfie_photo": "base64_string_here"
}
```

**Request:**
```
POST http://localhost:8000/api/attendances/check-in
```

**Send!**

---

### **cURL: Test Scenario 1 (ON TIME)**

```bash
TOKEN="your_access_token_here"

curl -X POST http://localhost:8000/api/attendances/check-in \
  -H "Authorization: Bearer $TOKEN" \
  -F "project_id=1" \
  -F "latitude=-6.200050" \
  -F "longitude=106.816700" \
  -F "current_time=2026-02-10 09:10:30" \
  -F "selfie_photo=@/path/to/image.jpg"
```

**Expected Response (200 OK):**
```json
{
  "message": "Absen masuk berhasil.",
  "data": {
    "id": 1,
    "user_id": 1,
    "date": "2026-02-10",
    "assignment": {
      "code": "P",
      "name": "Pagi",
      "start_time": "09:00:00",
      "end_time": "17:00:00",
      "grace_period": 15,
      "is_off_duty": false
    },
    "post": {
      "id": 1,
      "name": "Pos Gate Utama",
      "type": "static"
    },
    "check_in_at": "09:10:30",
    "check_out_at": null,
    "attendance_status": "HADIR",
    "computed_status": "HADIR",
    "late_minutes": 0,
    "overtime_minutes": 0,
    "overtime_status": "NONE",
    "can_attend": false
  }
}
```

---

### **Postman: Test Scenario 2 (LATE - 25 minutes)**

**Change only `current_time`:**
```
current_time: 2026-02-10 09:25:30
```

**Expected Response (200 OK):**
```json
{
  "message": "Absen masuk berhasil.",
  "data": {
    "check_in_at": "09:25:30",
    "attendance_status": "HADIR TELAT",
    "computed_status": "HADIR TELAT",
    "late_minutes": 25
  }
}
```

---

### **Postman: Test Scenario 3 (TOO LATE - 35 minutes)**

**Change `current_time`:**
```
current_time: 2026-02-10 09:35:00
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

### **Postman: Test Scenario 4 (OUT OF RANGE)**

**Change location (far away):**
```
latitude: -6.250000
longitude: 106.900000
```

**Expected Response (403 Forbidden):**
```json
{
  "message": "Anda berada di luar radius absen masuk.",
  "your_location": {
    "latitude": -6.25,
    "longitude": 106.9
  },
  "project_location": {
    "latitude": -6.2,
    "longitude": 106.816667
  },
  "distance": "7840.52 meters",
  "allowed_radius": "100 meters"
}
```

---

## **STEP 4: Test Check-Out API**

### **Prerequisites:**
1. User must be checked in first
2. Must be after `end_time` of assignment
3. If mobile post: all patrol points must be scanned

### **Postman Setup:**

**Tab: Authorization**
```
Type: Bearer Token
Token: [Your access token]
```

**Tab: Body → form-data**
```
Key: attendance_id | Value: 1  (from check-in response)
Key: latitude      | Value: -6.200050
Key: longitude     | Value: 106.816700
Key: current_time  | Value: 2026-02-10 17:05:30
```

**Request:**
```
POST http://localhost:8000/api/attendances/check-out
```

**Expected Response (200 OK):**
```json
{
  "message": "Absen pulang berhasil.",
  "data": {
    "id": 1,
    "check_in_at": "09:10:30",
    "check_out_at": "17:05:30",
    "attendance_status": "HADIR",
    "computed_status": "HADIR LEMBUR",
    "late_minutes": 0,
    "overtime_minutes": 5,
    "overtime_status": "NONE"
  }
}
```

---

## **STEP 5: Test GET Attendance**

### **Get specific attendance record:**

```bash
curl -X GET http://localhost:8000/api/attendances/1 \
  -H "Authorization: Bearer $TOKEN"
```

**Expected Response:**
```json
{
  "data": {
    "id": 1,
    "user_id": 1,
    "date": "2026-02-10",
    "assignment": {
      "code": "P",
      "name": "Pagi",
      "start_time": "09:00:00",
      "end_time": "17:00:00",
      "grace_period": 15,
      "is_off_duty": false
    },
    "post": {
      "id": 1,
      "name": "Pos Gate Utama",
      "type": "static"
    },
    "check_in_at": "09:10:30",
    "check_out_at": "17:05:30",
    "attendance_status": "HADIR",
    "computed_status": "HADIR LEMBUR",
    "late_minutes": 0,
    "overtime_minutes": 5,
    "overtime_status": "NONE",
    "can_attend": false
  }
}
```

---

## **COMMON ERRORS & SOLUTIONS**

### **Error 422: Validation Failed**
```json
{
  "latitude": ["The latitude field is required."],
  "current_time": ["The current_time must be a valid date in format Y-m-d H:i:s."]
}
```

**Solution:**
- Ensure `current_time` format is exactly: `YYYY-MM-DD HH:MM:SS`
- All required fields are included
- Check spelling of field names

---

### **Error 403: Out of Radius**
```json
{
  "message": "Anda berada di luar radius absen masuk.",
  "distance": "2500.45 meters",
  "allowed_radius": "100 meters"
}
```

**Solution:**
- Use coordinates closer to project location
- For testing, use: `-6.200050, 106.816700`
- Check that project has `latitude`, `longitude`, `radius` set

---

### **Error 403: Time Already Ended**
```json
{
  "message": "Waktu absen masuk telah berakhir.",
  "allowed_deadline": "09:30:00",
  "your_time": "09:35:00"
}
```

**Solution:**
- Use a `current_time` before the deadline
- Calculate: `start_time + (grace_period * 2)`
- For P shift (09:00 + 15*2 = 09:30): must check-in by 09:30

---

### **Error 409: Already Checked In**
```json
{
  "message": "Anda sudah absen masuk hari ini."
}
```

**Solution:**
- Only one check-in per day
- Check-out with different endpoint if you want to test both

---

### **Error 401: Token Expired**
```json
{
  "message": "Unauthenticated."
}
```

**Solution:**
- Login again to get new token
- Paste new token in Authorization header

---

## **POSTMAN COLLECTION QUICK EXPORT**

### **Export Current Setup:**
1. Right-click on request folder
2. "Export" → "Export with Data"
3. Choose format: "Postman 2.1"

### **Import Collection:**
1. Postman → "Import"
2. Choose file
3. Collections will appear in sidebar

---

## **TROUBLESHOOTING SCRIPT**

```bash
#!/bin/bash

TOKEN="your_token_here"
PROJECT_ID=1

echo "=== Testing Check-In ==="

curl -X POST http://localhost:8000/api/attendances/check-in \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d "{
    \"project_id\": $PROJECT_ID,
    \"latitude\": -6.200050,
    \"longitude\": 106.816700,
    \"current_time\": \"$(date +'%Y-%m-%d %H:%M:%S')\",
    \"selfie_photo\": \"base64_placeholder\"
  }" | jq .

echo ""
echo "=== Check Database ==="
mysql -u root -p epatrol -e "SELECT * FROM attendances ORDER BY id DESC LIMIT 1;"
```

---

## **SUMMARY**

| Step | Command | Expected |
|------|---------|----------|
| 1 | Setup DB | No errors |
| 2 | Login | get `access_token` |
| 3 | Check-in (On Time) | 200 OK, HADIR |
| 4 | Check-in (Late) | 200 OK, HADIR TELAT |
| 5 | Check-in (Too Late) | 403 Forbidden |
| 6 | Check-in (Out of Range) | 403 Forbidden |
| 7 | Check-out | 200 OK, HADIR LEMBUR |
| 8 | GET Attendance | 200 OK, full details |

---

Happy Testing! 🚀
