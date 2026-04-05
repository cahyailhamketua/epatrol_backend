# PatrolPointController Updates

**Status:** ✅ Updated with Dual-Model Logic  
**Last Updated:** 2024  
**Namespace:** `App\Http\Controllers\Api`

---

## Overview

PatrolPointController telah diupdate untuk menerapkan logic dual-model (Static vs Mobile posts) yang sesuai dengan requirement sistem patrol scanning Anda.

---

## Key Business Logic Implementation

### 1️⃣ Static Post Constraint
```
✓ Hanya 1 patrol point per static post (untuk komandan regu)
✓ Sequence order HARUS = 1 untuk static post
✓ Automatic validation di store() method
```

**Response jika violation:**
```json
{
  "success": false,
  "message": "Static post hanya boleh punya 1 patrol point (untuk komandan regu)",
  "post_type": "static",
  "current_count": 0,
  "status_code": 422
}
```

### 2️⃣ Mobile Post Sequence Order
```
✓ Multiple patrol points allowed per mobile post
✓ Sequence order HARUS unique per post [post_id, sequence_order]
✓ Sequence order = urutan scanning yang harus dilakukan oleh anggota
✓ Validation di store() method before create
```

**Response jika violation:**
```json
{
  "success": false,
  "message": "Sequence order sudah ada untuk post ini",
  "post_id": 1,
  "post_type": "mobile",
  "sequence_order": 2,
  "suggestion": "Gunakan sequence order yang berbeda atau update patrol point yang ada",
  "status_code": 422
}
```

### 3️⃣ Altitude Validation Support
```
✓ Altitude field tersedia di PatrolPoint
✓ Digunakan untuk ±50m tolerance validation saat scanning
✓ Optional saat create, tapi recommended untuk accuracy
✓ Dapat di-update jika perlu recalibration
```

---

## API Endpoints & Logic

### ✨ CREATE PATROL POINT
**POST** `/api/posts/{post}/patrol-points`

**Request Body:**
```json
{
  "name": "Pos Utama Gedung A",
  "sequence_order": 1,
  "latitude": -6.2088,
  "longitude": 106.8456,
  "altitude": 1250,
  "radius": 5
}
```

**Validations:**
| Rule | Type | Constraint | Description |
|------|------|-----------|-------------|
| name | required | string, max:100 | Nama patrol point |
| sequence_order | required | integer, min:1 | Urutan scanning |
| latitude | required | numeric, -90 to 90 | Koordinat latitude |
| longitude | required | numeric, -180 to 180 | Koordinat longitude |
| altitude | optional | numeric | Ketinggian untuk distance validation |
| radius | optional | integer, min:1 | Default 5 km jika tidak diisi |

**Static Post Additional Validations:**
- ❌ Menolak jika static post sudah punya patrol point
- ❌ Menolak jika sequence_order ≠ 1

**Success Response (201):**
```json
{
  "success": true,
  "message": "Patrol point created dengan QR code",
  "data": {
    "id": 1,
    "post": {
      "id": 1,
      "name": "Gedung A",
      "type": "static"
    },
    "patrol_point": {
      "id": 1,
      "post_id": 1,
      "name": "Pos Utama Gedung A",
      "sequence_order": 1,
      "latitude": -6.2088,
      "longitude": 106.8456,
      "altitude": 1250,
      "radius": 5,
      "qr_code": {
        "id": 1,
        "code": "550E8400-E29B-41D4-A716-446655440000",
        "active": true
      }
    },
    "info": {
      "type": "Static Point (Komandan Regu only)",
      "total_points_in_post": 1
    }
  }
}
```

---

### 👁️ SHOW PATROL POINT
**GET** `/api/patrol-points/{patrolPoint}`

**Response (200):**
```json
{
  "success": true,
  "data": {
    "patrol_point": {
      "id": 1,
      "post_id": 1,
      "name": "Pos Utama Gedung A",
      "sequence_order": 1,
      "latitude": -6.2088,
      "longitude": 106.8456,
      "altitude": 1250,
      "radius": 5,
      "qr_code": {
        "id": 1,
        "code": "550E8400-E29B-41D4-A716-446655440000",
        "active": true
      }
    },
    "post_context": {
      "id": 1,
      "name": "Gedung A",
      "type": "static",
      "type_description": "Static Point - Untuk Komandan Regu (max 1 point per post)"
    },
    "scanning_info": {
      "sequence_order": 1,
      "total_points_in_post": 1,
      "coordinates": {
        "latitude": -6.2088,
        "longitude": 106.8456,
        "altitude": 1250
      },
      "validation_distance_radius": "5 km",
      "altitude_tolerance": "±50 meters (from patrol point altitude)"
    },
    "qr_code": {
      "id": 1,
      "code": "550E8400-E29B-41D4-A716-446655440000",
      "active": true,
      "scannable": true
    }
  }
}
```

---

### ✏️ UPDATE PATROL POINT
**PATCH** `/api/patrol-points/{patrolPoint}`

**Request Body:**
```json
{
  "name": "Pos Utama Gedung A (Updated)",
  "latitude": -6.2088,
  "longitude": 106.8456,
  "altitude": 1250,
  "radius": 7,
  "regenerate_qr": false
}
```

**Key Constraints:**
- ❌ **TIDAK BOLEH** mengubah `sequence_order` → harus delete & create ulang
- ✅ Bisa update: name, coordinates, altitude, radius
- ✅ Bisa regenerate QR code dengan `regenerate_qr: true`

**Error jika mengubah sequence_order:**
```json
{
  "success": false,
  "message": "Tidak boleh mengubah sequence_order. Hapus dan buat yang baru jika perlu",
  "current_sequence": 1,
  "status_code": 422
}
```

**Success Response (200):**
```json
{
  "success": true,
  "message": "Patrol point updated successfully",
  "data": {
    "patrol_point": { ... },
    "post_info": {
      "id": 1,
      "name": "Gedung A",
      "type": "static"
    },
    "qr_regenerated": false
  }
}
```

---

### 🗑️ DELETE PATROL POINT
**DELETE** `/api/patrol-points/{patrolPoint}`

**Pre-deletion Validation:**
- ❌ Menolak jika ada patrol scans yang menggunakan QR code ini
- ✅ Hanya bisa delete jika patrol point belum pernah di-scan

**Error jika ada linked scans:**
```json
{
  "success": false,
  "message": "Tidak bisa menghapus patrol point, sudah ada 5 scan yang terhubung",
  "error_code": "PATROL_POINT_IN_USE",
  "linked_scans_count": 5,
  "recommendation": "Deactivate patrol point atau update scans terlebih dahulu",
  "status_code": 422
}
```

**Success Response (200):**
```json
{
  "success": true,
  "message": "Patrol point deleted successfully",
  "data": {
    "deleted_point": {
      "id": 1,
      "name": "Pos Utama Gedung A",
      "post_id": 1
    }
  }
}
```

---

## Workflow Examples

### 📍 Scenario 1: Static Post (Komandan Regu)
```
1. Create Post: type = 'static'
   ✓ Can only create 1 patrol point
   ✓ sequence_order MUST = 1
   ✓ Komandan will scan this single point

2. Create PatrolPoint:
   POST /api/posts/1/patrol-points
   {
     "name": "Kantor Pusat",
     "sequence_order": 1,
     "latitude": -6.2088,
     "longitude": 106.8456,
     "altitude": 1250
   }
   
3. Result:
   ✓ PatrolPoint created
   ✓ QR code auto-generated
   ✓ Ready for Komandan scanning
   
4. Attempt 2nd point → REJECTED with 422 error
```

### 📍 Scenario 2: Mobile Post (Anggota)
```
1. Create Post: type = 'mobile'
   ✓ Can create multiple patrol points
   ✓ Different sequence_order for each

2. Create PatrolPoints:
   
   Point 1 - sequence_order = 1:
   POST /api/posts/2/patrol-points
   {
     "name": "Pos Depan Gedung",
     "sequence_order": 1,
     "latitude": -6.2085,
     "longitude": 106.8450,
     "altitude": 1245,
     "radius": 5
   }
   
   Point 2 - sequence_order = 2:
   POST /api/posts/2/patrol-points
   {
     "name": "Pos Belakang Gedung",
     "sequence_order": 2,
     "latitude": -6.2092,
     "longitude": 106.8462,
     "altitude": 1255,
     "radius": 5
   }
   
3. Result:
   ✓ Both points created
   ✓ Anggota HARUS scan sequence 1 dulu
   ✓ Scan sequence 2 hanya setelah sequence 1 complete
   ✓ Altitude ±50m validation applied
```

---

## Authorization & Policies

**Endpoint Authorization:**
- `create` (store) → Require `manage` permission untuk Project
- `view` (show) → Require `view` permission
- `update` → Require `manage` permission
- `delete` → Require `manage` permission

**Policy Used:** `App\Policies\PatrolPointPolicy`

---

## Database Constraints

**Table: `patrol_points`**
```sql
-- Unique combination of post_id + sequence_order
UNIQUE KEY 'unique_post_sequence' (`post_id`, `sequence_order`)

-- Foreign key untuk posts
FOREIGN KEY (`post_id`) REFERENCES `posts`(`id`) ON DELETE CASCADE

-- Foreign key untuk qr_codes (1:1)
FOREIGN KEY (`qr_code_id`) REFERENCES `qr_codes`(`id`) ON DELETE CASCADE
```

---

## Error Codes & Status Codes

| Status | Code | Meaning | Solution |
|--------|------|---------|----------|
| 422 | `STATIC_POST_LIMIT` | Static post sudah punya 1 patrol point | Delete existing point terlebih dahulu |
| 422 | `INVALID_SEQUENCE_STATIC` | Static post sequence_order ≠ 1 | Gunakan sequence_order = 1 untuk static posts |
| 422 | `SEQUENCE_ORDER_EXISTS` | Sequence order sudah ada untuk post ini | Gunakan sequence order unik atau update yang ada |
| 422 | `PATROL_POINT_IN_USE` | Patrol point sudah ada scans terhubung | Deactivate point atau update scans dulu |
| 403 | - | Unauthorized (insufficient permissions) | Check user role & authorization policy |
| 404 | - | Patrol point atau Post tidak ditemukan | Verify post_id dan patrol_point_id |

---

## Key Features & Improvements

✅ **Dual-Model Support**
- Static posts: Single point for commanders
- Mobile posts: Multiple ordered points for members

✅ **Altitude Integration**
- Stored & accessible untuk validation logic
- Supports ±50m tolerance checking di PatrolScanService

✅ **Sequence Order Enforcement**
- Unique per post constraint
- Prevents sequence duplication
- Can't be modified (delete & recreate instead)

✅ **QR Code Management**
- Auto-generated on create
- Can be regenerated on update
- Active/inactive status tracking

✅ **Data Integrity**
- Transaction-wrapped operations
- Cascade delete dengan QR codes
- Pre-deletion scan link validation

✅ **Rich Response Format**
- Detailed error messages
- Post context information
- Scanning workflow information
- Clear type descriptions

---

## Related Components

**Connected Controllers:**
- [PatrolScanController](./app/Http/Controllers/Api/PatrolScanController.php) - Uses patrol points for scanning
- [PostController](./app/Http/Controllers/Api/PostController.php) - Parent resource

**Related Services:**
- [PatrolScanService](./app/Services/PatrolScanService.php) - Validates patrol points during scanning

**Related Models:**
- `Post` - Parent model (type: static/mobile)
- `PatrolPoint` - Main model
- `QrCode` - Associated QR code
- `PatrolScan` - Scans referencing patrol points

**Related Policies:**
- `PatrolPointPolicy` - Authorization rules

---

## Migration Notes

**Existing Deployments:**
Jika sudah punya static posts dengan > 1 patrol point:
1. Verify existing data
2. Update data sesuai rule (1 point per static post)
3. Update controllers ke version terbaru
4. Test dengan Postman collection

**Required Fields:**
- Ensure `altitude` field exists di migration
- Ensure `post_type` enum correctly set

---

## Testing Checklist

- [ ] Create static post → verify max 1 patrol point allowed
- [ ] Create mobile post → verify multiple points allowed
- [ ] Verify unique [post_id, sequence_order] constraint
- [ ] Test update without changing sequence_order
- [ ] Test delete with cascading to scans
- [ ] Verify QR code auto-generation
- [ ] Test authorization policies
- [ ] Verify error messages for business rule violations
- [ ] Test with mobile (GPS) and static post scenarios

