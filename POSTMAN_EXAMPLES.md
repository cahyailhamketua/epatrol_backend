# 📧 POSTMAN - ATTENDANCE FLOW EXAMPLES

## **SETUP AWAL**

### **1. Import Collection ke Postman**
Copas JSON collection di bawah ke: File → Import → Paste Raw Text

### **2. Set Variable di Postman**
```
BASE_URL: http://localhost:8000
TOKEN: [dari login response]
USER_ID: 5
PROJECT_ID: 1
SCHEDULE_ID: 12
ATTENDANCE_ID: 42
```

---

## **FLOW DIAGRAM**

```
1. Login           → Get TOKEN
    ↓
2. Get Schedule    → Get semua data (assignment, post, project)
    ↓
3. Check-in        → Buat attendance dengan device location
    ↓
4. Patrol Scan     → (Optional) Scan titik patroli jika mobile post
    ↓
5. Check-out       → Selesai shift
```

---

## **POSTMAN JSON COLLECTION**

```json
{
  "info": {
    "name": "Attendance System",
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
      },
      "response": [
        {
          "name": "Login Success",
          "originalRequest": {
            "method": "POST",
            "header": [],
            "body": {
              "mode": "raw",
              "raw": "{\"email\": \"user@example.com\", \"password\": \"password\"}"
            }
          },
          "status": "OK",
          "code": 200,
          "body": "{\n  \"access_token\": \"eyJhbGc...\",\n  \"user\": {\n    \"id\": 5,\n    \"name\": \"John Doe\",\n    \"email\": \"user@example.com\",\n    \"role\": \"anggota\",\n    \"project_id\": 1\n  }\n}\n"
        }
      ]
    },
    {
      "name": "2a. Get Today Schedule (GET)",
      "request": {
        "method": "GET",
        "header": [
          {
            "key": "Authorization",
            "value": "Bearer {{TOKEN}}"
          }
        ],
        "url": {
          "raw": "{{BASE_URL}}/api/users/{{USER_ID}}/schedules?from_date=2026-02-10&to_date=2026-02-10",
          "host": ["{{BASE_URL}}"],
          "path": ["api", "users", "{{USER_ID}}", "schedules"],
          "query": [
            {
              "key": "from_date",
              "value": "2026-02-10"
            },
            {
              "key": "to_date",
              "value": "2026-02-10"
            }
          ]
        }
      },
      "response": [
        {
          "name": "Schedule Found",
          "status": "OK",
          "code": 200,
          "body": "{\n  \"data\": [\n    {\n      \"id\": 12,\n      \"project_id\": 1,\n      \"user_id\": 5,\n      \"assignment_id\": 2,\n      \"post_id\": 3,\n      \"date\": \"2026-02-10\",\n      \"created_at\": \"2026-02-10T08:00:00.000000Z\",\n      \"updated_at\": \"2026-02-10T12:00:00.000000Z\",\n      \"project\": {\n        \"id\": 1,\n        \"name\": \"PT Maju Jaya\",\n        \"location_latitude\": -6.200000,\n        \"location_longitude\": 106.816667,\n        \"radius\": 100,\n        \"organization_id\": 1\n      },\n      \"assignment\": {\n        \"id\": 2,\n        \"project_id\": 1,\n        \"name\": \"Pagi\",\n        \"code\": \"P\",\n        \"start_time\": \"09:00:00\",\n        \"end_time\": \"17:00:00\",\n        \"grace_period\": 15,\n        \"is_off\": 0\n      },\n      \"post\": {\n        \"id\": 3,\n        \"project_id\": 1,\n        \"name\": \"Pos Gate\",\n        \"type\": \"static\",\n        \"created_at\": \"2026-02-09T10:00:00.000000Z\"\n      },\n      \"user\": {\n        \"id\": 5,\n        \"name\": \"John Doe\",\n        \"email\": \"john@example.com\",\n        \"role\": \"anggota\"\n      }\n    }\n  ],\n  \"pagination\": {\n    \"total\": 1,\n    \"per_page\": 50,\n    \"current_page\": 1,\n    \"last_page\": 1\n  }\n}\n"
        }
      ]
    },
    {
      "name": "2b. Get Schedule by ID (GET)",
      "request": {
        "method": "GET",
        "header": [
          {
            "key": "Authorization",
            "value": "Bearer {{TOKEN}}"
          }
        ],
        "url": {
          "raw": "{{BASE_URL}}/api/schedules/12",
          "host": ["{{BASE_URL}}"],
          "path": ["api", "schedules", "12"]
        }
      },
      "response": [
        {
          "name": "Schedule Detail",
          "status": "OK",
          "code": 200,
          "body": "{\n  \"data\": {\n    \"id\": 12,\n    \"project_id\": 1,\n    \"user_id\": 5,\n    \"assignment_id\": 2,\n    \"post_id\": 3,\n    \"date\": \"2026-02-10\",\n    \"project\": {\n      \"id\": 1,\n      \"name\": \"PT Maju Jaya\",\n      \"location_latitude\": -6.200000,\n      \"location_longitude\": 106.816667,\n      \"radius\": 100\n    },\n    \"assignment\": {\n      \"id\": 2,\n      \"code\": \"P\",\n      \"name\": \"Pagi\",\n      \"start_time\": \"09:00:00\",\n      \"end_time\": \"17:00:00\",\n      \"grace_period\": 15,\n      \"is_off\": 0\n    },\n    \"post\": {\n      \"id\": 3,\n      \"name\": \"Pos Gate\",\n      \"type\": \"static\"\n    },\n    \"user\": {\n      \"id\": 5,\n      \"name\": \"John Doe\"\n    }\n  }\n}\n"
        }
      ]
    },
    {
      "name": "3a. Check-In (ON TIME)",
      "request": {
        "method": "POST",
        "header": [
          {
            "key": "Authorization",
            "value": "Bearer {{TOKEN}}"
          },
          {
            "key": "Content-Type",
            "value": "multipart/form-data"
          }
        ],
        "body": {
          "mode": "formdata",
          "formdata": [
            {
              "key": "project_id",
              "value": "1",
              "type": "text"
            },
            {
              "key": "latitude",
              "value": "-6.200050",
              "type": "text",
              "description": "Device latitude (user current location)"
            },
            {
              "key": "longitude",
              "value": "106.816700",
              "type": "text",
              "description": "Device longitude (user current location)"
            },
            {
              "key": "current_time",
              "value": "2026-02-10 09:10:30",
              "type": "text",
              "description": "Device time (NOT server time)"
            },
            {
              "key": "selfie_photo",
              "type": "file",
              "src": "/path/to/selfie.jpg",
              "description": "Selfie proof of presence"
            }
          ]
        },
        "url": {
          "raw": "{{BASE_URL}}/api/attendances/check-in",
          "host": ["{{BASE_URL}}"],
          "path": ["api", "attendances", "check-in"]
        }
      },
      "response": [
        {
          "name": "On Time Success (201)",
          "status": "Created",
          "code": 201,
          "body": "{\n  \"message\": \"Absen masuk berhasil.\",\n  \"data\": {\n    \"id\": 42,\n    \"date\": \"2026-02-10\",\n    \"schedule\": {\n      \"assignment\": {\n        \"code\": \"P\",\n        \"name\": \"Pagi\",\n        \"start_time\": \"09:00:00\",\n        \"end_time\": \"17:00:00\",\n        \"grace_period\": \"15 minutes\",\n        \"is_off_duty\": false\n      },\n      \"post\": {\n        \"id\": 3,\n        \"name\": \"Pos Gate\",\n        \"type\": \"static\"\n      }\n    },\n    \"timing\": {\n      \"check_in_at\": \"09:10:30\",\n      \"check_out_at\": null,\n      \"late_minutes\": 0,\n      \"overtime_minutes\": 0\n    },\n    \"status\": {\n      \"attendance_status\": \"HADIR\",\n      \"computed_status\": \"HADIR\",\n      \"overtime_status\": \"NONE\"\n    },\n    \"can_attend\": true\n  }\n}\n"
        }
      ]
    },
    {
      "name": "3b. Check-In (LATE)",
      "request": {
        "method": "POST",
        "header": [
          {
            "key": "Authorization",
            "value": "Bearer {{TOKEN}}"
          },
          {
            "key": "Content-Type",
            "value": "multipart/form-data"
          }
        ],
        "body": {
          "mode": "formdata",
          "formdata": [
            {
              "key": "project_id",
              "value": "1",
              "type": "text"
            },
            {
              "key": "latitude",
              "value": "-6.200050",
              "type": "text"
            },
            {
              "key": "longitude",\n              \"value\": \"106.816700\",\n              \"type\": \"text\"\n            },\n            {\n              \"key\": \"current_time\",\n              \"value\": \"2026-02-10 09:25:30\",\n              \"type\": \"text\",\n              \"description\": \"Between grace_deadline (09:15) and absolute_deadline (09:30)\"\n            },\n            {\n              \"key\": \"selfie_photo\",\n              \"type\": \"file\",\n              \"src\": \"/path/to/selfie.jpg\"\n            }\n          ]\n        },\n        \"url\": {\n          \"raw\": \"{{BASE_URL}}/api/attendances/check-in\",\n          \"host\": [\"{{BASE_URL}}\"],\n          \"path\": [\"api\", \"attendances\", \"check-in\"]\n        }\n      },\n      \"response\": [\n        {\n          \"name\": \"Late Success (201)\",\n          \"status\": \"Created\",\n          \"code\": 201,\n          \"body\": \"{\n  \\\"message\\\": \\\"Absen masuk berhasil.\\\",\n  \\\"data\\\": {\n    \\\"id\\\": 42,\n    \\\"date\\\": \\\"2026-02-10\\\",\n    \\\"status\\\": {\n      \\\"attendance_status\\\": \\\"HADIR TELAT\\\",\n      \\\"computed_status\\\": \\\"HADIR TELAT\\\"\n    },\n    \\\"timing\\\": {\n      \\\"check_in_at\\\": \\\"09:25:30\\\",\n      \\\"late_minutes\\\": 25\n    }\n  }\n}\n\"\n        }\n      ]\n    },\n    {\n      \"name\": \"3c. Check-In (TOO LATE - 403)\",\n      \"request\": {\n        \"method\": \"POST\",\n        \"header\": [\n          {\n            \"key\": \"Authorization\",\n            \"value\": \"Bearer {{TOKEN}}\"\n          },\n          {\n            \"key\": \"Content-Type\",\n            \"value\": \"multipart/form-data\"\n          }\n        ],\n        \"body\": {\n          \"mode\": \"formdata\",\n          \"formdata\": [\n            {\n              \"key\": \"project_id\",\n              \"value\": \"1\",\n              \"type\": \"text\"\n            },\n            {\n              \"key\": \"latitude\",\n              \"value\": \"-6.200050\",\n              \"type\": \"text\"\n            },\n            {\n              \"key\": \"longitude\",\n              \"value\": \"106.816700\",\n              \"type\": \"text\"\n            },\n            {\n              \"key\": \"current_time\",\n              \"value\": \"2026-02-10 09:35:00\",\n              \"type\": \"text\",\n              \"description\": \"After absolute_deadline (09:30)\"\n            },\n            {\n              \"key\": \"selfie_photo\",\n              \"type\": \"file\",\n              \"src\": \"/path/to/selfie.jpg\"\n            }\n          ]\n        },\n        \"url\": {\n          \"raw\": \"{{BASE_URL}}/api/attendances/check-in\",\n          \"host\": [\"{{BASE_URL}}\"],\n          \"path\": [\"api\", \"attendances\", \"check-in\"]\n        }\n      },\n      \"response\": [\n        {\n          \"name\": \"Too Late Rejected (403)\",\n          \"status\": \"Forbidden\",\n          \"code\": 403,\n          \"body\": \"{\n  \\\"message\\\": \\\"Waktu absen masuk telah berakhir.\\\",\n  \\\"assignment\\\": {\n    \\\"code\\\": \\\"P\\\",\n    \\\"start_time\\\": \\\"09:00:00\\\"\n  },\n  \\\"allowed_deadline\\\": \\\"09:30:00\\\",\n  \\\"your_time\\\": \\\"09:35:00\\\"\n}\n\"\n        }\n      ]\n    },\n    {\n      \"name\": \"3d. Check-In (OUT OF LOCATION - 403)\",\n      \"request\": {\n        \"method\": \"POST\",\n        \"header\": [\n          {\n            \"key\": \"Authorization\",\n            \"value\": \"Bearer {{TOKEN}}\"\n          },\n          {\n            \"key\": \"Content-Type\",\n            \"value\": \"multipart/form-data\"\n          }\n        ],\n        \"body\": {\n          \"mode\": \"formdata\",\n          \"formdata\": [\n            {\n              \"key\": \"project_id\",\n              \"value\": \"1\",\n              \"type\": \"text\"\n            },\n            {\n              \"key\": \"latitude\",\n              \"value\": \"-6.210000\",\n              \"type\": \"text\",\n              \"description\": \"Far away location (not within radius)\"\n            },\n            {\n              \"key\": \"longitude\",\n              \"value\": \"106.825000\",\n              \"type\": \"text\"\n            },\n            {\n              \"key\": \"current_time\",\n              \"value\": \"2026-02-10 09:10:30\",\n              \"type\": \"text\"\n            },\n            {\n              \"key\": \"selfie_photo\",\n              \"type\": \"file\",\n              \"src\": \"/path/to/selfie.jpg\"\n            }\n          ]\n        },\n        \"url\": {\n          \"raw\": \"{{BASE_URL}}/api/attendances/check-in\",\n          \"host\": [\"{{BASE_URL}}\"],\n          \"path\": [\"api\", \"attendances\", \"check-in\"]\n        }\n      },\n      \"response\": [\n        {\n          \"name\": \"Out of Range Rejected (403)\",\n          \"status\": \"Forbidden\",\n          \"code\": 403,\n          \"body\": \"{\n  \\\"message\\\": \\\"Anda berada di luar radius absen masuk.\\\",\n  \\\"your_location\\\": {\n    \\\"latitude\\\": -6.210000,\n    \\\"longitude\\\": 106.825000\n  },\n  \\\"reference_location\\\": {\n    \\\"type\\\": \\\"project\\\",\n    \\\"latitude\\\": -6.200000,\n    \\\"longitude\\\": 106.816667\n  },\n  \\\"distance\\\": \\\"1234.56 meters\\\",\n  \\\"allowed_radius\\\": \\\"100 meters\\\"\n}\n\"\n        }\n      ]\n    },\n    {\n      \"name\": \"4. Check-Out (after duty)\",\n      \"request\": {\n        \"method\": \"POST\",\n        \"header\": [\n          {\n            \"key\": \"Authorization\",\n            \"value\": \"Bearer {{TOKEN}}\"\n          },\n          {\n            \"key\": \"Content-Type\",\n            \"value\": \"application/json\"\n          }\n        ],\n        \"body\": {\n          \"mode\": \"raw\",\n          \"raw\": \"{\n  \\\"attendance_id\\\": 42,\n  \\\"latitude\\\": -6.200050,\n  \\\"longitude\\\": 106.816700,\n  \\\"current_time\\\": \\\"2026-02-10 17:30:00\\\"\n}\"\n        },\n        \"url\": {\n          \"raw\": \"{{BASE_URL}}/api/attendances/check-out\",\n          \"host\": [\"{{BASE_URL}}\"],\n          \"path\": [\"api\", \"attendances\", \"check-out\"]\n        }\n      },\n      \"response\": [\n        {\n          \"name\": \"Overtime Check-Out (200)\",\n          \"status\": \"OK\",\n          \"code\": 200,\n          \"body\": \"{\n  \\\"message\\\": \\\"Absen pulang berhasil.\\\",\n  \\\"data\\\": {\n    \\\"id\\\": 42,\n    \\\"status\\\": {\n      \\\"computed_status\\\": \\\"HADIR LEMBUR\\\"\n    },\n    \\\"timing\\\": {\n      \\\"check_in_at\\\": \\\"09:10:30\\\",\n      \\\"check_out_at\\\": \\\"17:30:00\\\",\n      \\\"overtime_minutes\\\": 30\n    }\n  }\n}\n\"\n        }\n      ]\n    },\n    {\n      \"name\": \"5a. Patrol Scan (Mobile Post) - Point 1\",\n      \"request\": {\n        \"method\": \"POST\",\n        \"header\": [\n          {\n            \"key\": \"Authorization\",\n            \"value\": \"Bearer {{TOKEN}}\"\n          },\n          {\n            \"key\": \"Content-Type\",\n            \"value\": \"multipart/form-data\"\n          }\n        ],\n        \"body\": {\n          \"mode\": \"formdata\",\n          \"formdata\": [\n            {\n              \"key\": \"attendance_id\",\n              \"value\": \"42\",\n              \"type\": \"text\"\n            },\n            {\n              \"key\": \"patrol_point_id\",\n              \"value\": \"1\",\n              \"type\": \"text\"\n            },\n            {\n              \"key\": \"latitude\",\n              \"value\": \"-6.195000\",\n              \"type\": \"text\"\n            },\n            {\n              \"key\": \"longitude\",\n              \"value\": \"106.810000\",\n              \"type\": \"text\"\n            },\n            {\n              \"key\": \"current_time\",\n              \"value\": \"2026-02-10 10:00:00\",\n              \"type\": \"text\"\n            },\n            {\n              \"key\": \"description_option\",\n              \"value\": \"aman\",\n              \"type\": \"text\",\n              \"description\": \"aman or ada kendala\"\n            },\n            {\n              \"key\": \"notes\",\n              \"value\": \"Area aman, tidak ada masalah\",\n              \"type\": \"text\"\n            },\n            {\n              \"key\": \"photos\",\n              \"type\": \"file\",\n              \"src\": [\"/path/to/photo1.jpg\", \"/path/to/photo2.jpg\", \"/path/to/photo3.jpg\", \"/path/to/photo4.jpg\"]\n            }\n          ]\n        },\n        \"url\": {\n          \"raw\": \"{{BASE_URL}}/api/attendances/patrol-scan\",\n          \"host\": [\"{{BASE_URL}}\"],\n          \"path\": [\"api\", \"attendances\", \"patrol-scan\"]\n        }\n      },\n      \"response\": [\n        {\n          \"name\": \"Patrol Scan Success (201)\",\n          \"status\": \"Created\",\n          \"code\": 201,\n          \"body\": \"{\n  \\\"message\\\": \\\"Scan titik patroli berhasil.\\\",\n  \\\"patrol_scan\\\": {\n    \\\"id\\\": 1,\n    \\\"attendance_id\\\": 42,\n    \\\"patrol_point_id\\\": 1,\n    \\\"sequence_order\\\": 1,\n    \\\"scan_time\\\": \\\"2026-02-10 10:00:00\\\",\n    \\\"description_option\\\": \\\"aman\\\",\n    \\\"notes\\\": \\\"Area aman, tidak ada masalah\\\"\n  }\n}\n\"\n        }\n      ]\n    },\n    {\n      \"name\": \"6. List Attendances (GET)\",\n      \"request\": {\n        \"method\": \"GET\",\n        \"header\": [\n          {\n            \"key\": \"Authorization\",\n            \"value\": \"Bearer {{TOKEN}}\"\n          }\n        ],\n        \"url\": {\n          \"raw\": \"{{BASE_URL}}/api/attendances?date=2026-02-10\",\n          \"host\": [\"{{BASE_URL}}\"],\n          \"path\": [\"api\", \"attendances\"],\n          \"query\": [\n            {\n              \"key\": \"date\",\n              \"value\": \"2026-02-10\"\n            }\n          ]\n        }\n      }\n    },\n    {\n      \"name\": \"7. View Attendance Detail (GET)\",\n      \"request\": {\n        \"method\": \"GET\",\n        \"header\": [\n          {\n            \"key\": \"Authorization\",\n            \"value\": \"Bearer {{TOKEN}}\"\n          }\n        ],\n        \"url\": {\n          \"raw\": \"{{BASE_URL}}/api/attendances/42\",\n          \"host\": [\"{{BASE_URL}}\"],\n          \"path\": [\"api\", \"attendances\", \"42\"]\n        }\n      }\n    }\n  ]\n}\n```

---

## **POSTMAN ENVIRONMENT VARIABLES**

Copy-paste ke Postman Environment:

```json
{\n  \"values\": [\n    {\n      \"key\": \"BASE_URL\",\n      \"value\": \"http://localhost:8000\",\n      \"type\": \"string\",\n      \"enabled\": true\n    },\n    {\n      \"key\": \"TOKEN\",\n      \"value\": \"\",\n      \"type\": \"string\",\n      \"enabled\": true\n    },\n    {\n      \"key\": \"USER_ID\",\n      \"value\": \"5\",\n      \"type\": \"string\",\n      \"enabled\": true\n    },\n    {\n      \"key\": \"PROJECT_ID\",\n      \"value\": \"1\",\n      \"type\": \"string\",\n      \"enabled\": true\n    },\n    {\n      \"key\": \"SCHEDULE_ID\",\n      \"value\": \"12\",\n      \"type\": \"string\",\n      \"enabled\": true\n    },\n    {\n      \"key\": \"ATTENDANCE_ID\",\n      \"value\": \"42\",\n      \"type\": \"string\",\n      \"enabled\": true\n    }\n  ]\n}\n```

---

## **KEY POINTS - SCHEDULE RESPONSE**

Schedule response sudah include SEMUA data untuk attendance:

```json
{\n  \"data\": {\n    \"id\": 12,\n    \"project\": {\n      \"location_latitude\": -6.200000,  // ← Reference point\n      \"location_longitude\": 106.816667,\n      \"radius\": 100                     // ← Geofence radius\n    },\n    \"assignment\": {\n      \"code\": \"P\",                     // ← Shift code\n      \"start_time\": \"09:00:00\",        // ← Duty start\n      \"end_time\": \"17:00:00\",          // ← Duty end\n      \"grace_period\": 15                // ← Grace minutes\n    },\n    \"post\": {\n      \"name\": \"Pos Gate\",               // ← User knows where\n      \"type\": \"static\"                  // ← System validation\n    }\n  }\n}\n```

---

## **TESTING CHECKLIST**

- [ ] Login → Get TOKEN\n- [ ] Get Schedule for today → Copy SCHEDULE_ID\n- [ ] Check-In (ON TIME):  09:10 → HADIR ✓\n- [ ] Check-In (LATE): 09:25 → HADIR TELAT ✓\n- [ ] Check-In (TOO LATE): 09:35 → 403 Forbidden ✓\n- [ ] Check-In (OUT OF RANGE): Different location → 403 ✓\n- [ ] Check-Out (OVERTIME): 17:30 → HADIR LEMBUR ✓\n- [ ] Check-Out (WITHOUT CHECK-IN): → 403 ✓\n- [ ] Patrol Scan (Mobile Post): Scan 4 points sequentially ✓\n