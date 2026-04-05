-- ============================================
-- DATABASE SETUP FOR ATTENDANCE TESTING
-- ============================================

-- 1. Ensure Project has complete location data
-- ============================================
UPDATE projects 
SET latitude = -6.200000, 
    longitude = 106.816667, 
    radius = 100
WHERE id = 1;

-- 2. Create or Update Assignment P (Pagi/Morning Shift)
-- ============================================
-- If exists, update. Otherwise, insert.
INSERT INTO assignments (project_id, name, code, is_off, start_time, end_time, grace_period, created_at, updated_at)
VALUES (1, 'Pagi', 'P', 0, '09:00:00', '17:00:00', 15, NOW(), NOW())
ON DUPLICATE KEY UPDATE 
    is_off = 0, 
    start_time = '09:00:00', 
    end_time = '17:00:00', 
    grace_period = 15;

-- 3. Create Assignment M (Malam/Evening Shift)
-- ============================================
INSERT INTO assignments (project_id, name, code, is_off, start_time, end_time, grace_period, created_at, updated_at)
VALUES (1, 'Malam', 'M', 0, '17:00:00', '23:00:00', 15, NOW(), NOW())
ON DUPLICATE KEY UPDATE 
    is_off = 0, 
    start_time = '17:00:00', 
    end_time = '23:00:00', 
    grace_period = 15;

-- 4. Create Assignment O (OFF / Hari Libur)
-- ============================================
INSERT INTO assignments (project_id, name, code, is_off, start_time, end_time, grace_period, created_at, updated_at)
VALUES (1, 'OFF', 'O', 1, '00:00:00', '00:00:00', 0, NOW(), NOW())
ON DUPLICATE KEY UPDATE 
    is_off = 1, 
    start_time = '00:00:00', 
    end_time = '00:00:00', 
    grace_period = 0;

-- 5. Create Schedules for Testing (for all users today)
-- ============================================
-- First, delete old test schedules for today
DELETE FROM schedules WHERE date = CURDATE();

-- Insert schedule for all users (P shift - morning)
INSERT INTO schedules (project_id, user_id, assignment_id, post_id, date, created_at, updated_at)
SELECT p.id, u.id, a.id, po.id, CURDATE(), NOW(), NOW()
FROM projects p
CROSS JOIN users u
CROSS JOIN assignments a
CROSS JOIN posts po
WHERE p.id = 1 
  AND a.code = 'P'
  AND po.id = 1
  AND u.organization_id = p.organization_id
ON DUPLICATE KEY UPDATE 
    date = CURDATE();

-- 6. Create a Static Post (for testing)
-- ============================================
INSERT INTO posts (project_id, name, type, created_at, updated_at)
VALUES (1, 'Pos Gate Utama', 'static', NOW(), NOW())
ON DUPLICATE KEY UPDATE 
    name = 'Pos Gate Utama', 
    type = 'static';

-- 7. Optional: Clear old attendances for clean testing
-- ============================================
-- Uncomment if you want to reset
-- DELETE FROM attendances WHERE date = CURDATE();

-- ============================================
-- VERIFICATION QUERIES
-- ============================================

-- Check Project location
SELECT id, name, latitude, longitude, radius FROM projects WHERE id = 1;

-- Check Assignments
SELECT id, code, name, is_off, start_time, end_time, grace_period FROM assignments WHERE project_id = 1;

-- Check Schedules for today
SELECT s.id, u.id, u.email, a.code, p.name, s.date 
FROM schedules s
JOIN users u ON s.user_id = u.id
JOIN assignments a ON s.assignment_id = a.id
JOIN posts p ON s.post_id = p.id
WHERE s.date = CURDATE()
LIMIT 5;

-- Check Posts
SELECT id, name, type FROM posts WHERE project_id = 1;

-- ============================================
-- NOTES
-- ============================================
-- 1. Replace 1 with your actual project_id if different
-- 2. Replace -6.200000, 106.816667 with your actual office coordinates
-- 3. Radius is in meters (100m = default office radius)
-- 4. Grace period is in minutes (15min = default)
-- 5. Assignment codes: P=Pagi, M=Malam, SOC=Sore, O=OFF
-- 6. Post type: 'static' = fixed location, 'mobile' = patrol route

