# Mobile Patrol Scan API Documentation

## 📱 Endpoints untuk Mobile Application

---

## 1️⃣ CHECK QR ENDPOINT

### Endpoint Details
```
Method: POST
URL: {{BASE_URL}}/api/patrol-scan/check-qr
Content-Type: application/json
Authorization: Bearer {TOKEN}
```

### Purpose
Validasi QR Code sebelum melakukan scan. Mengecek:
- ✅ QR Code valid & aktif
- ✅ Lokasi dalam radius patrol point
- ✅ Attendance masih aktif (check-in ada, check-out belum)
- ✅ Attendance sudah melewati end_time assignment atau belum

---

### Request Body

```json
{
  "attendance_id": 15,
  "qr_code": "QR001",
  "scan_latitude": -6.123456,
  "scan_longitude": 106.789012,
  "scan_altitude": 50
}
```

### Request Parameters Explanation

| Parameter | Type | Required | Description | Example |
|-----------|------|----------|-------------|---------|
| attendance_id | integer | ✅ Yes | ID attendance dari checkin | `15` |
| qr_code | string | ✅ Yes | QR code string dari scanner | `"QR001"` |
| scan_latitude | numeric | ✅ Yes | Latitude lokasi scan (-90 to 90) | `-6.123456` |
| scan_longitude | numeric | ✅ Yes | Longitude lokasi scan (-180 to 180) | `106.789012` |
| scan_altitude | numeric | ❌ Optional | Ketinggian lokasi scan (meter) | `50` |

---

### ✅ Success Response (200 OK)

```json
{
  "success": true,
  "errors": [],
  "data": {
    "attendance_id": 15,
    "qr_code": "QR001",
    "is_valid": true,
    "already_scanned": false,
    "is_in_radius": true,
    "distance": 12.5,
    "radius": 50,
    "nearby_unscanned": [
      {
        "id": 2,
        "name": "Checkpoint 2",
        "sequence_order": 2
      }
    ],
    "remaining_patrol_points": [
      {
        "id": 3,
        "name": "Checkpoint 3",
        "sequence_order": 3
      },
      {
        "id": 4,
        "name": "Checkpoint 4",
        "sequence_order": 4
      }
    ],
    "scan_progress": {
      "total": 10,
      "scanned": 2,
      "percentage": 20
    },
    "post": {
      "id": 1,
      "name": "Post A - Mobile",
      "type": "mobile"
    }
  }
}
```

### Response Fields Explanation

| Field | Type | Description |
|-------|------|-------------|
| success | boolean | Status validasi QR (true = semua validasi pass) |
| errors | array | Array error messages jika ada |
| data.attendance_id | integer | ID attendance yang di-check |
| data.is_valid | boolean | QR code valid & sesuai post |
| data.already_scanned | boolean | Apakah checkpoint ini sudah di-scan sebelumnya |
| data.is_in_radius | boolean | Lokasi dalam radius patrol point |
| data.distance | float | Jarak lokasi scan ke patrol point (meter) |
| data.radius | float | Radius patrol point (meter) |
| data.nearby_unscanned | array | Checkpoint terdekat yang belum di-scan |
| data.remaining_patrol_points | array | Daftar checkpoint yang belum di-scan |
| data.scan_progress | object | Progress scan (total, scanned, percentage) |
| data.post | object | Info post (id, name, type) |

---

### ❌ Error Response - QR Code Invalid (422)

```json
{
  "success": false,
  "errors": [
    "QR Code tidak ditemukan"
  ],
  "data": {
    "attendance_id": 15,
    "qr_code": "INVALID_QR",
    "is_valid": false,
    "already_scanned": false,
    "is_in_radius": false,
    "distance": null,
    "radius": null,
    "remaining_patrol_points": [],
    "scan_progress": {
      "total": 0,
      "scanned": 0,
      "percentage": 0
    }
  }
}
```

### ❌ Error Response - Melampaui Radius (422)

```json
{
  "success": false,
  "errors": [
    "Lokasi scan terlalu jauh. Jarak: 125.50 m, Radius: 50.00 m"
  ],
  "data": {
    "attendance_id": 15,
    "qr_code": "QR001",
    "is_valid": true,
    "already_scanned": false,
    "is_in_radius": false,
    "distance": 125.50,
    "radius": 50.00,
    "remaining_patrol_points": [...]
  }
}
```

### ❌ Error Response - Sudah Di-scan (422)

```json
{
  "success": false,
  "errors": [
    "Titik ini sudah pernah di-scan pada attendance ini"
  ],
  "data": {
    "attendance_id": 15,
    "qr_code": "QR001",
    "is_valid": true,
    "already_scanned": true,
    "is_in_radius": true,
    "distance": 12.5,
    "radius": 50,
    "nearby_unscanned": [...]
  }
}
```

### ❌ Error Response - Attendance Tidak Ditemukan (404)

```json
{
  "success": false,
  "message": "Attendance tidak ditemukan"
}
```

---

## 2️⃣ PERFORM SCAN ENDPOINT

### Endpoint Details
```
Method: POST
URL: {{BASE_URL}}/api/patrol-scan
Content-Type: multipart/form-data (dengan foto)
Authorization: Bearer {TOKEN}
```

### Purpose
Melakukan patrol scan dengan upload foto. Mengecek:
- ✅ QR Code valid & lokasi dalam radius
- ✅ Minimal 4 foto harus di-upload
- ✅ Attendance masih aktif
- ✅ Tidak ada duplicate scan untuk QR yang sama

---

### Request Body (Multipart Form Data)

```
attendance_id: 15
qr_code: QR001
scan_latitude: -6.123456
scan_longitude: 106.789012
scan_altitude: 50
note: Scan dari checkpoint 1, kondisi normal
current_time: 2026-05-11 13:15:00
photos[0]: <file binary>
photos[1]: <file binary>
photos[2]: <file binary>
photos[3]: <file binary>
```

### Request Parameters Explanation

| Parameter | Type | Required | Description | Example |
|-----------|------|----------|-------------|---------|
| attendance_id | integer | ✅ Yes | ID attendance dari checkin | `15` |
| qr_code | string | ✅ Yes | QR code string dari scanner | `"QR001"` |
| scan_latitude | numeric | ✅ Yes | Latitude lokasi scan (-90 to 90) | `-6.123456` |
| scan_longitude | numeric | ✅ Yes | Longitude lokasi scan (-180 to 180) | `106.789012` |
| scan_altitude | numeric | ❌ Optional | Ketinggian lokasi scan (meter) | `50` |
| note | string | ❌ Optional | Catatan scan (max 500 char) | `"Kondisi normal"` |
| current_time | string | ✅ Yes | Waktu scan (format: Y-m-d H:i:s) | `"2026-05-11 13:15:00"` |
| photos | file | ✅ Yes | Foto scan (minimal 4, max 5MB each) | multipart file |

---

### ✅ Success Response (201 Created)

```json
{
  "success": true,
  "message": "Scan 'Checkpoint 1' berhasil dicatat",
  "data": {
    "scan_id": 123,
    "progress": {
      "total": 10,
      "scanned": 3,
      "percentage": 30
    }
  }
}
```

### Response Fields Explanation

| Field | Type | Description |
|-------|------|-------------|
| success | boolean | Scan berhasil disimpan (true/false) |
| message | string | Pesan sukses dengan nama checkpoint |
| data.scan_id | integer | ID scan yang baru dibuat |
| data.progress.total | integer | Total checkpoint yang harus discan |
| data.progress.scanned | integer | Jumlah checkpoint yang sudah discan |
| data.progress.percentage | float | Persentase progress (0-100) |

---

### ❌ Error Response - Foto Kurang (422)

```json
{
  "success": false,
  "errors": {
    "photos": "Minimal 4 foto wajib diupload"
  },
  "status_code": 422
}
```

### ❌ Error Response - Melampaui Radius (422)

```json
{
  "success": false,
  "errors": [
    "Lokasi scan terlalu jauh. Jarak: 125.50 m, Radius: 50.00 m"
  ],
  "distance": 125.50,
  "radius": 50.00,
  "status_code": 422
}
```

### ❌ Error Response - Duplicate Scan (409)

```json
{
  "success": false,
  "errors": [
    "Titik ini sudah pernah di-scan."
  ],
  "already_scanned": true,
  "status_code": 409
}
```

### ❌ Error Response - Attendance Tidak Aktif (400)

```json
{
  "success": false,
  "message": "Attendance tidak aktif"
}
```

### ❌ Error Response - Scan Sedang Diproses (423)

```json
{
  "message": "Scan sedang diproses. Mohon tunggu sejenak."
}
```

---

## 📱 Mobile Implementation Examples

### JavaScript/React Native

```javascript
// 1. CHECK QR FIRST
async function checkQRCode(attendanceId, qrCode, latitude, longitude, altitude) {
  const response = await fetch(`${BASE_URL}/api/patrol-scan/check-qr`, {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${token}`,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({
      attendance_id: attendanceId,
      qr_code: qrCode,
      scan_latitude: latitude,
      scan_longitude: longitude,
      scan_altitude: altitude,
    }),
  });
  
  const result = await response.json();
  
  if (result.success) {
    console.log('QR valid dan dalam radius');
    console.log('Jarak:', result.data.distance, 'm');
    console.log('Radius:', result.data.radius, 'm');
    return true;
  } else {
    console.error('Error:', result.errors);
    if (result.data?.distance > result.data?.radius) {
      console.error('Lokasi terlalu jauh dari patrol point');
    }
    return false;
  }
}

// 2. PERFORM SCAN WITH PHOTOS
async function performScan(attendanceId, qrCode, latitude, longitude, photos, note) {
  const formData = new FormData();
  formData.append('attendance_id', attendanceId);
  formData.append('qr_code', qrCode);
  formData.append('scan_latitude', latitude);
  formData.append('scan_longitude', longitude);
  formData.append('note', note);
  formData.append('current_time', getCurrentTimeString());
  
  // Append 4+ photos
  photos.forEach((photo, index) => {
    formData.append(`photos[${index}]`, photo);
  });
  
  const response = await fetch(`${BASE_URL}/api/patrol-scan`, {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${token}`,
      // Don't set Content-Type, browser will set it automatically
    },
    body: formData,
  });
  
  const result = await response.json();
  
  if (result.success) {
    console.log('Scan berhasil!');
    console.log('Scan ID:', result.data.scan_id);
    console.log('Progress:', result.data.progress.percentage + '%');
    return result;
  } else {
    console.error('Error:', result.errors);
    throw new Error(result.errors[0] || 'Scan failed');
  }
}

// Helper: Get current time in Y-m-d H:i:s format
function getCurrentTimeString() {
  const now = new Date();
  const year = now.getFullYear();
  const month = String(now.getMonth() + 1).padStart(2, '0');
  const day = String(now.getDate()).padStart(2, '0');
  const hours = String(now.getHours()).padStart(2, '0');
  const minutes = String(now.getMinutes()).padStart(2, '0');
  const seconds = String(now.getSeconds()).padStart(2, '0');
  
  return `${year}-${month}-${day} ${hours}:${minutes}:${seconds}`;
}
```

### Flutter

```dart
import 'package:http/http.dart' as http;
import 'package:image_picker/image_picker.dart';
import 'dart:convert';

// 1. CHECK QR FIRST
Future<bool> checkQRCode(
  int attendanceId,
  String qrCode,
  double latitude,
  double longitude,
  double? altitude,
) async {
  final response = await http.post(
    Uri.parse('$BASE_URL/api/patrol-scan/check-qr'),
    headers: {
      'Authorization': 'Bearer $token',
      'Content-Type': 'application/json',
    },
    body: jsonEncode({
      'attendance_id': attendanceId,
      'qr_code': qrCode,
      'scan_latitude': latitude,
      'scan_longitude': longitude,
      'scan_altitude': altitude,
    }),
  );
  
  if (response.statusCode == 200) {
    final data = jsonDecode(response.body);
    if (data['success']) {
      print('QR valid!');
      print('Distance: ${data['data']['distance']}m');
      print('Radius: ${data['data']['radius']}m');
      return true;
    } else {
      print('Error: ${data['errors']}');
      return false;
    }
  } else {
    throw Exception('Failed to check QR');
  }
}

// 2. PERFORM SCAN WITH PHOTOS
Future<Map<String, dynamic>> performScan(
  int attendanceId,
  String qrCode,
  double latitude,
  double longitude,
  List<XFile> photos,
  String? note,
) async {
  final request = http.MultipartRequest(
    'POST',
    Uri.parse('$BASE_URL/api/patrol-scan'),
  );
  
  request.headers['Authorization'] = 'Bearer $token';
  request.fields['attendance_id'] = attendanceId.toString();
  request.fields['qr_code'] = qrCode;
  request.fields['scan_latitude'] = latitude.toString();
  request.fields['scan_longitude'] = longitude.toString();
  request.fields['note'] = note ?? '';
  request.fields['current_time'] = _getCurrentTimeString();
  
  // Add photos
  for (int i = 0; i < photos.length; i++) {
    request.files.add(
      await http.MultipartFile.fromPath(
        'photos[$i]',
        photos[i].path,
      ),
    );
  }
  
  final response = await request.send();
  final responseData = jsonDecode(await response.stream.bytesToString());
  
  if (response.statusCode == 201 && responseData['success']) {
    print('Scan berhasil!');
    print('Scan ID: ${responseData['data']['scan_id']}');
    return responseData['data'];
  } else {
    throw Exception(responseData['errors']?.first ?? 'Scan failed');
  }
}

// Helper: Get current time
String _getCurrentTimeString() {
  final now = DateTime.now();
  return now.toString().split('.')[0]; // Format: 2026-05-11 13:15:00
}
```

---

## 🔄 Mobile Workflow

### Step-by-Step Flow

```
1. User Scan QR Code
   └─> Get: attendance_id, qr_code

2. Get User Location
   └─> Get: latitude, longitude, altitude

3. Call CHECK QR Endpoint
   └─> Validate QR, check radius, get remaining points
   └─> If error: Show error & prevent scan
   └─> If success: Continue to step 4

4. Capture Minimum 4 Photos
   └─> User take 4+ photos of checkpoint

5. Call PERFORM SCAN Endpoint
   └─> Upload: qr_code, location, photos, note
   └─> Check: radius, duplicate, photos count
   └─> If error: Show error, allow retry
   └─> If success: Update progress & show next checkpoint

6. Display Progress
   └─> Show: Scanned X of Y checkpoints
   └─> Show: Percentage complete
   └─> Show: Next checkpoint to scan
```

### Error Handling Strategy

```javascript
async function handleScan() {
  try {
    // Step 1: Check QR
    const isValid = await checkQRCode(...);
    if (!isValid) {
      showAlert('QR Invalid', 'Please scan correct QR code');
      return;
    }
    
    // Step 2: Capture Photos
    const photos = await capturePhotos(4);
    if (!photos || photos.length < 4) {
      showAlert('Foto Kurang', 'Minimal 4 foto diperlukan');
      return;
    }
    
    // Step 3: Perform Scan
    const result = await performScan(...);
    showSuccess('Scan berhasil!');
    updateProgress(result.progress);
    
  } catch (error) {
    if (error.message.includes('jauh')) {
      showAlert('Lokasi Jauh', 'Anda terlalu jauh dari checkpoint');
    } else if (error.message.includes('Minimal 4')) {
      showAlert('Foto Kurang', 'Minimal 4 foto per checkpoint');
    } else if (error.message.includes('sudah')) {
      showAlert('Sudah Discan', 'Checkpoint ini sudah pernah discan');
    } else {
      showAlert('Error', error.message);
    }
  }
}
```

---

## 🧪 Testing dengan Postman

### Setup Variables di Postman

```
{{BASE_URL}} = http://localhost:8000
{{token}} = your_Bearer_token_here
{{attendance_id}} = 15
{{qr_code}} = QR001
```

### Test Case 1: Check QR Valid

```
POST {{BASE_URL}}/api/patrol-scan/check-qr
Authorization: Bearer {{token}}
Content-Type: application/json

{
  "attendance_id": {{attendance_id}},
  "qr_code": "{{qr_code}}",
  "scan_latitude": -6.123456,
  "scan_longitude": 106.789012,
  "scan_altitude": 50
}
```

### Test Case 2: Check QR - Radius Error

```
POST {{BASE_URL}}/api/patrol-scan/check-qr
Authorization: Bearer {{token}}
Content-Type: application/json

{
  "attendance_id": {{attendance_id}},
  "qr_code": "{{qr_code}}",
  "scan_latitude": -6.999999,
  "scan_longitude": 106.999999,
  "scan_altitude": 50
}
```

### Test Case 3: Perform Scan

```
POST {{BASE_URL}}/api/patrol-scan
Authorization: Bearer {{token}}
Content-Type: multipart/form-data

Form Data:
- attendance_id: {{attendance_id}}
- qr_code: {{qr_code}}
- scan_latitude: -6.123456
- scan_longitude: 106.789012
- scan_altitude: 50
- note: Test scan dari mobile
- current_time: 2026-05-11 13:15:00
- photos[0]: <select photo 1>
- photos[1]: <select photo 2>
- photos[2]: <select photo 3>
- photos[3]: <select photo 4>
```

---

## 📌 Important Notes

### Timezone Handling
- **current_time** harus dalam timezone **project** (biasanya Asia/Jakarta)
- Server akan auto-convert ke UTC untuk storage
- Mobile harus send local time sesuai device timezone

### Location Validation
- **scan_latitude**: -90 to 90 (negative = South)
- **scan_longitude**: -180 to 180 (negative = West)
- **scan_altitude**: optional, dalam meter
- Haversine formula digunakan untuk hitung jarak

### Photo Requirements
- **Minimum**: 4 foto per scan
- **Maximum**: 5MB per foto
- **Format**: JPG, PNG, WebP
- Akan otomatis convert ke WebP di server

### Offline Mode
- Check QR & Perform Scan memerlukan connection internet
- Untuk offline mode, gunakan endpoint: `POST /api/patrol-scan/offline`

---

## 🚀 Production Checklist

- [ ] Set correct `BASE_URL` (bukan localhost)
- [ ] Verify token authorization headers
- [ ] Test dengan actual GPS coordinates
- [ ] Test dengan actual photo uploads
- [ ] Handle network timeout (>30 detik)
- [ ] Handle error responses gracefully
- [ ] Store offline queue jika koneksi lost
- [ ] Implement retry mechanism untuk failed scans
- [ ] Log semua scan attempts untuk debugging
- [ ] Monitor battery usage saat location tracking

---
