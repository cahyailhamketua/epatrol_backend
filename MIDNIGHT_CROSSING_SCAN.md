# Fitur Patrol Scan Lintas Malam (Midnight Crossing)

## Deskripsi

Fitur ini memungkinkan pekerja untuk melakukan patrol scan pada shift malam yang melintasi tengah malam (midnight crossing). 

### Contoh Kasus:
- **Shift Malam Lintas Tengah Malam**: Start time 20:00, End time 04:00
  - Check-in terjadi pada hari 1, jam 20:00
  - Attendance record di-buat dengan `date` = hari 1
  - Pekerja dapat melakukan scan sepanjang hari 1 dan hari 2 hingga jam 04:00

## Implementasi

### 1. Model: Assignment
Di model `Assignment`, ada method `getDurationInMinutes()` yang sudah mendeteksi shift lintas malam:
```php
if ($end->lessThanOrEqualTo($start)) {
    $end->addDay();
}
```

Logika: Jika `end_time <= start_time`, maka dianggap lintas malam.

### 2. Service: PatrolScanService
Di method `canUserScan()`, validasi tanggal scan sudah di-update untuk mendukung lintas malam:

**Logika Validasi:**
1. Jika scan pada hari yang sama dengan `attendance->date` → **Valid**
2. Jika scan pada hari berbeda:
   - Cek apakah assignment adalah lintas malam (`end_time <= start_time`)
   - Jika YA → Allow scan pada next day, **TAPI** hanya sampai jam `end_time`
   - Jika TIDAK → Return error

**Error Message untuk Lintas Malam:**
```
Scan hanya bisa dilakukan hingga jam 04:00 pada hari berikutnya
```

### 3. Controller: PatrolScanController
Method `performScan()` sudah menerima `current_time` dari device dan mengirimnya ke service untuk validasi yang akurat.

```php
$validated = $request->validate([
    'current_time' => 'required|date_format:Y-m-d H:i:s',
    // ... fields lainnya
]);
```

## Contoh Request

### Shift Malam (Lintas Tengah Malam)
**Scenario:** Assignment dengan start_time: 20:00, end_time: 04:00

**Check-in pada hari 1 jam 21:00:**
```bash
POST /api/attendance/check-in
{
    "check_in_lat": -6.1234,
    "check_in_lng": 106.7890
}
```
→ Attendance dibuat dengan `date: 2024-04-01`, `check_in_at: 2024-04-01 21:00:00`

**Scan pada hari 1 jam 23:30 (VALID):**
```bash
POST /api/patrol-scan
{
    "attendance_id": 1,
    "qr_code": "PATROL-POINT-001",
    "scan_latitude": -6.1234,
    "scan_longitude": 106.7890,
    "current_time": "2024-04-01 23:30:00"
}
```
✅ **Success** - Scan pada hari yang sama

**Scan pada hari 2 jam 02:30 (VALID):**
```bash
POST /api/patrol-scan
{
    "attendance_id": 1,
    "qr_code": "PATROL-POINT-002",
    "scan_latitude": -6.1234,
    "scan_longitude": 106.7890,
    "current_time": "2024-04-02 02:30:00"
}
```
✅ **Success** - Scan pada hari berikutnya, tapi masih sebelum jam 04:00

**Scan pada hari 2 jam 05:00 (INVALID):**
```bash
POST /api/patrol-scan
{
    "attendance_id": 1,
    "qr_code": "PATROL-POINT-003",
    "scan_latitude": -6.1234,
    "scan_longitude": 106.7890,
    "current_time": "2024-04-02 05:00:00"
}
```
❌ **Error** - "Scan hanya bisa dilakukan hingga jam 04:00 pada hari berikutnya"

## Syarat dan Ketentuan

1. **Assignment harus terkait dengan Attendance**
   - Jika `attendance->assignment` tidak ada, fitur lintas malam tidak aktif
   - Hanya menggunakan validasi tanggal standar (hari yang sama)

2. **Timezone**
   - Menggunakan timezone dari project / organization
   - Default: `Asia/Jakarta` jika tidak didefinisikan

3. **Definisi Lintas Malam**
   - `assignment->end_time <= assignment->start_time`
   - Contoh: 20:00 - 04:00 (end 04:00 <= start 20:00 ✓)
   - Counter-example: 08:00 - 17:00 (end 17:00 > start 08:00 ✗)

## Troubleshooting

### Error: "Scan hanya bisa dilakukan hingga jam XX:XX pada hari berikutnya"

**Penyebab:**
- Anda mencoba scan di luar window lintas malam
- Atau assignment tidak dikonfigurasi sebagai lintas malam

**Solusi:**
1. Verifikasi `assignment->start_time` dan `assignment->end_time` sudah benar
2. Pastikan `end_time < start_time` untuk shift malam
3. Pastikan `current_time` yang dikirim dalam format yang benar dan zona waktu yang sesuai

### Attendance tidak ditemukan

**Penyebab:**
- `attendance_id` salah
- Attendance sudah di-check-out

**Solusi:**
- Gunakan ID attendance yang benar
- Pastikan dapat melakukan check-in terlebih dahulu sebelum scan

## Testing

Untuk testing feature ini, pastikan:
1. Buat Assignment dengan `start_time: 20:00, end_time: 04:00`
2. Buat Attendance dengan assignment tsb
3. Check-in pada hari 1
4. Test scan pada hari 1 (should pass)
5. Test scan pada hari 2 sebelum 04:00 (should pass)
6. Test scan pada hari 2 setelah 04:00 (should fail)
