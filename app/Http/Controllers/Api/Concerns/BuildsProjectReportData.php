<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Absence;
use App\Models\Assignment;
use App\Models\Attendance;
use App\Models\PatrolScan;
use App\Models\Post;
use App\Models\Project;
use App\Models\Schedule;
use App\Models\Team;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

trait BuildsProjectReportData
{
    private const MAX_PER_PAGE = 100;

    private const MAX_EXPORT_ROWS = 5000;

    protected function validatedFilters(Request $request, Project $project, bool $allowStatus): array
    {
        $rules = [
            'dari_tanggal' => 'nullable|date_format:Y-m-d',
            'sampai_tanggal' => 'nullable|date_format:Y-m-d|after_or_equal:dari_tanggal',
            'current_time' => 'nullable|date_format:Y-m-d H:i:s',
            'team_id' => 'nullable|integer|exists:teams,id',
            'team_name' => 'nullable|string|max:100', // Filter for Danru/Team
            'post_id' => 'nullable|integer|exists:posts,id',
            'post_name' => 'nullable|string|max:100', // Filter for Post
            'assignment_id' => 'nullable|integer|exists:assignments,id',
            'shift_name' => 'nullable|string|max:100', // Filter for Shift
            'user_id' => 'nullable|integer|exists:users,id',
            'employee_name' => 'nullable|string|max:100', // Filter for Employee Search
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:'.self::MAX_PER_PAGE,
            'tanpa_paginasi' => 'nullable|boolean',
        ];
        if ($allowStatus) {
            $rules['status'] = 'nullable|string|in:tepat_waktu,terlambat,absen,hadir,hadir_telat';
        }

        $data = $request->validate($rules);

        if (! empty($data['team_id'])) {
            Team::where('id', $data['team_id'])->where('project_id', $project->id)->firstOrFail();
        }
        if (! empty($data['post_id'])) {
            Post::where('id', $data['post_id'])->where('project_id', $project->id)->firstOrFail();
        }
        if (! empty($data['assignment_id'])) {
            Assignment::where('id', $data['assignment_id'])->where('project_id', $project->id)->firstOrFail();
        }
        if (! empty($data['user_id'])) {
            User::where('id', $data['user_id'])->where('project_id', $project->id)->firstOrFail();
        }

        $range = $this->resolveReportDateRange(
            $project,
            $data['dari_tanggal'] ?? null,
            $data['sampai_tanggal'] ?? null,
            $data['current_time'] ?? null
        );

        return [
            'dari_tanggal' => $range['dari_tanggal'],
            'sampai_tanggal' => $range['sampai_tanggal'],
            'date_range_is_current_month_default' => $range['is_default_month'],
            'current_time_anchor' => $range['anchor_time_in_project_tz'],
            'team_id' => $data['team_id'] ?? null,
            'team_name' => $data['team_name'] ?? null,
            'post_id' => $data['post_id'] ?? null,
            'post_name' => $data['post_name'] ?? null,
            'assignment_id' => $data['assignment_id'] ?? null,
            'shift_name' => $data['shift_name'] ?? null,
            'user_id' => $data['user_id'] ?? null,
            'employee_name' => $data['employee_name'] ?? null,
            'status' => $allowStatus ? ($data['status'] ?? null) : null,
            'page' => (int) ($data['page'] ?? 1),
            'per_page' => (int) ($data['per_page'] ?? 15),
            'tanpa_paginasi' => filter_var($data['tanpa_paginasi'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /**
     * Ambil baris laporan: paginasi default, atau seluruh baris jika tanpa_paginasi=true.
     *
     * @return array{items: \Illuminate\Support\Collection, pagination: array<string, mixed>}
     */
    protected function fetchReportList($query, array $v): array
    {
        if ($v['tanpa_paginasi'] ?? false) {
            $items = $query->get();
            $total = $items->count();

            return [
                'items' => $items,
                'pagination' => [
                    'total' => $total,
                    'per_page' => $total,
                    'current_page' => 1,
                    'last_page' => 1,
                    'tanpa_paginasi' => true,
                ],
            ];
        }

        $paginator = $query->paginate($v['per_page'], ['*'], 'page', $v['page']);

        return [
            'items' => collect($paginator->items()),
            'pagination' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'tanpa_paginasi' => false,
            ],
        ];
    }

    /**
     * Sama seperti filter JSON, tanpa paginasi; ada limit baris untuk export (default 5000).
     */
    protected function validatedExportFilters(Request $request, Project $project, bool $allowStatus): array
    {
        $rules = [
            'dari_tanggal' => 'nullable|date_format:Y-m-d',
            'sampai_tanggal' => 'nullable|date_format:Y-m-d|after_or_equal:dari_tanggal',
            'current_time' => 'nullable|date_format:Y-m-d H:i:s',
            'team_id' => 'nullable|integer|exists:teams,id',
            'post_id' => 'nullable|integer|exists:posts,id',
            'assignment_id' => 'nullable|integer|exists:assignments,id',
            'user_id' => 'nullable|integer|exists:users,id',
            'limit' => 'nullable|integer|min:1|max:'.self::MAX_EXPORT_ROWS,
        ];
        if ($allowStatus) {
            $rules['status'] = 'nullable|string|in:tepat_waktu,terlambat,absen';
        }

        $data = $request->validate($rules);

        if (! empty($data['team_id'])) {
            Team::where('id', $data['team_id'])->where('project_id', $project->id)->firstOrFail();
        }
        if (! empty($data['post_id'])) {
            Post::where('id', $data['post_id'])->where('project_id', $project->id)->firstOrFail();
        }
        if (! empty($data['assignment_id'])) {
            Assignment::where('id', $data['assignment_id'])->where('project_id', $project->id)->firstOrFail();
        }
        if (! empty($data['user_id'])) {
            User::where('id', $data['user_id'])->where('project_id', $project->id)->firstOrFail();
        }

        $range = $this->resolveReportDateRange(
            $project,
            $data['dari_tanggal'] ?? null,
            $data['sampai_tanggal'] ?? null,
            $data['current_time'] ?? null
        );

        return [
            'dari_tanggal' => $range['dari_tanggal'],
            'sampai_tanggal' => $range['sampai_tanggal'],
            'date_range_is_current_month_default' => $range['is_default_month'],
            'current_time_anchor' => $range['anchor_time_in_project_tz'],
            'team_id' => $data['team_id'] ?? null,
            'post_id' => $data['post_id'] ?? null,
            'assignment_id' => $data['assignment_id'] ?? null,
            'user_id' => $data['user_id'] ?? null,
            'status' => $allowStatus ? ($data['status'] ?? null) : null,
            'limit' => (int) ($data['limit'] ?? self::MAX_EXPORT_ROWS),
        ];
    }

    /**
     * Jika dari & sampai tidak diisi: seluruh tanggal di bulan yang sama dengan anchor (current_time atau sekarang, timezone project).
     * Jika hanya salah satu diisi: error validasi.
     */
    protected function resolveReportDateRange(
        Project $project,
        ?string $dari,
        ?string $sampai,
        ?string $currentTime
    ): array {
        $tz = $this->projectTimezone($project);

        if ($dari && $sampai) {
            $anchor = Carbon::parse($sampai.' 12:00:00', $tz);

            return [
                'dari_tanggal' => $dari,
                'sampai_tanggal' => $sampai,
                'is_default_month' => false,
                'anchor_time_in_project_tz' => $anchor->format('Y-m-d H:i:s'),
            ];
        }

        if ($dari && ! $sampai) {

            $anchor = $currentTime
                ? Carbon::createFromFormat('Y-m-d H:i:s', $currentTime, $tz)
                : Carbon::now($tz);
        
            return [
                'dari_tanggal' => $dari,
                'sampai_tanggal' => $anchor->toDateString(),
                'is_default_month' => false,
                'anchor_time_in_project_tz' => $anchor->format('Y-m-d H:i:s'),
            ];
        }

        if (! $dari && $sampai) {
            throw ValidationException::withMessages([
                'dari_tanggal' => [
                    'dari_tanggal wajib diisi jika menggunakan sampai_tanggal.'
                ],
            ]);
        }

        if ($currentTime) {
            $anchor = Carbon::createFromFormat('Y-m-d H:i:s', $currentTime, $tz);
        } else {
            $anchor = Carbon::now($tz);
        }

        return [
            'dari_tanggal' => $anchor->copy()->startOfMonth()->toDateString(),
            'sampai_tanggal' => $anchor->copy()->endOfMonth()->toDateString(),
            'is_default_month' => true,
            'anchor_time_in_project_tz' => $anchor->format('Y-m-d H:i:s'),
        ];
    }

    /** URL file publik langsung (/storage/...) — perlu `php artisan storage:link` di server. */
    protected function directPublicStorageUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return url(Storage::disk('public')->url($path));
    }

    protected function publicFilters(array $v): array
    {
        return array_filter([
            'dari_tanggal' => $v['dari_tanggal'],
            'sampai_tanggal' => $v['sampai_tanggal'],
            'date_range_is_current_month_default' => $v['date_range_is_current_month_default'] ?? null,
            'current_time_anchor' => $v['current_time_anchor'] ?? null,
            'team_id' => $v['team_id'],
            'post_id' => $v['post_id'],
            'assignment_id' => $v['assignment_id'],
            'user_id' => $v['user_id'],
            'status' => $v['status'] ?? null,
            'tanpa_paginasi' => ($v['tanpa_paginasi'] ?? false) ? true : null,
        ], fn ($x) => $x !== null && $x !== '');
    }

    /** Filter karyawan di laporan kehadiran: HO vs admin project (admin lapang). */
    protected function validateAttendanceReportUserFilter(Request $request, Project $project, ?int $userId): void
    {
        if (! $userId) {
            return;
        }
        $target = User::where('id', $userId)->where('project_id', $project->id)->firstOrFail();
        $viewer = $request->user();

        if ($viewer->role === 'admin_project') {
            if (! in_array($target->role, ['komandan_regu', 'anggota'], true)) {
                throw ValidationException::withMessages([
                    'user_id' => ['Untuk admin lapang (admin project), filter karyawan hanya komandan regu atau anggota.'],
                ]);
            }
        } elseif ($viewer->role === 'ho') {
            if (! in_array($target->role, ['komandan_regu', 'anggota', 'admin_project'], true)) {
                throw ValidationException::withMessages([
                    'user_id' => ['Untuk HO, filter karyawan: anggota, komandan regu, atau admin project pada project ini.'],
                ]);
            }
        }
    }

    protected function validatePatrolDanruUserFilter(Project $project, ?int $userId): void
    {
        if (! $userId) {
            return;
        }
        $target = User::where('id', $userId)->where('project_id', $project->id)->firstOrFail();
        if ($target->role !== 'komandan_regu') {
            throw ValidationException::withMessages([
                'user_id' => ['Patrol danru: filter user hanya untuk komandan regu.'],
            ]);
        }
    }

    protected function validatePatrolPosUserFilter(Project $project, ?int $userId): void
    {
        if (! $userId) {
            return;
        }
        $target = User::where('id', $userId)->where('project_id', $project->id)->firstOrFail();
        if ($target->role !== 'anggota') {
            throw ValidationException::withMessages([
                'user_id' => ['Patrol pos: filter karyawan hanya untuk anggota.'],
            ]);
        }
    }

    protected function projectTimezone(Project $project): string
    {
        $project->loadMissing('organization');

        return $project->timezone ?? $project->organization?->timezone ?? 'Asia/Jakarta';
    }

    protected function attendanceBaseQuery(Project $project, array $v)
    {
        $q = Schedule::query()
            ->select(['schedules.id', 'schedules.project_id', 'schedules.date', 'schedules.user_id', 'schedules.team_id', 'schedules.assignment_id'])
            ->where('schedules.project_id', $project->id)
            ->whereBetween('schedules.date', [$v['dari_tanggal'], $v['sampai_tanggal']]);

        // Existing Filters
        // Note: When filtering by team_id, include schedules with team_id = NULL (removed from team)
        // to show full attendance history. When no team_id filter, include all schedules.
        if ($v['team_id']) {
            $q->where(function ($query) use ($v) {
                $query->where('schedules.team_id', $v['team_id'])
                      ->orWhereNull('schedules.team_id'); // Include removed team members' schedules for that team
            });
        }
        if ($v['assignment_id']) {
            $q->where('schedules.assignment_id', $v['assignment_id']);
        }
        if ($v['user_id']) {
            $q->where('schedules.user_id', $v['user_id']);
        }
        if ($v['post_id']) {
            $q->whereHas('attendance', fn ($a) => $a->where('post_id', $v['post_id']));
        }

        // New Filters
        if ($v['employee_name']) {
            $q->whereHas('user', fn ($u) => $u->where('full_name', 'like', '%'.$v['employee_name'].'%'));
        }
        if ($v['shift_name']) {
            $q->whereHas('assignment', fn ($as) => $as->where('name', 'like', '%'.$v['shift_name'].'%'));
        }

        return $q;
    }

    protected function applyAttendanceStatusFilter($query, ?string $status, ?string $today = null): void
    {
        if (! $status) {
            return;
        }
        if ($status === 'tepat_waktu' || $status === 'hadir') {
            $query->whereHas('attendance', function ($a) {
                $a->whereNotNull('check_in_at')
                    ->where('attendance_status', 'not like', '%TELAT%');
            });
        } elseif ($status === 'terlambat' || $status === 'hadir_telat') {
            $query->whereHas('attendance', function ($a) {
                $a->whereNotNull('check_in_at')
                    ->where('attendance_status', 'like', '%TELAT%');
            });
        } elseif ($status === 'absen') {
            $query->where(function ($q) {
                $q->whereDoesntHave('attendance')
                    ->orWhereHas('attendance', fn ($a) => $a->whereNull('check_in_at'));
            });
            if ($today) {
                $query->where('schedules.date', '<=', $today);
            }
        }
    }

    protected function attendanceSummary($baseQuery, ?string $today = null): array
    {
        $totalJadwal = (clone $baseQuery)->count();
        $totalKaryawan = (clone $baseQuery)->pluck('user_id')->unique()->count();

        $hadirTepat = (clone $baseQuery)->whereHas('attendance', function ($a) {
            $a->whereNotNull('check_in_at')
                ->where('attendance_status', 'not like', '%TELAT%');
        })->count();

        $hadirTelat = (clone $baseQuery)->whereHas('attendance', function ($a) {
            $a->whereNotNull('check_in_at')
                ->where('attendance_status', 'like', '%TELAT%');
        })->count();

        $absenQuery = (clone $baseQuery)->where(function ($q) {
            $q->whereDoesntHave('attendance')
                ->orWhereHas('attendance', fn ($a) => $a->whereNull('check_in_at'));
        });
        if ($today) {
            $absenQuery->where('schedules.date', '<=', $today);
        }
        $absen = $absenQuery->count();

        return [
            'total_hadir_tepat_waktu' => $hadirTepat,
            'total_telat' => $hadirTelat,
            'total_absen_tidak_masuk' => $absen,
            'total_karyawan_unik' => $totalKaryawan,
            'total_baris_jadwal' => $totalJadwal,
        ];
    }

    protected function mapAttendanceRow(Schedule $schedule, string $tz, ?string $today = null): array
    {
        $assignment = $schedule->assignment;
        $user = $schedule->user;
        $attendance = $schedule->attendance;
        $absence = $schedule->absence;
        $scheduleDate = $schedule->date instanceof Carbon
            ? $schedule->date->format('Y-m-d')
            : (string) $schedule->date;
        $today = $today ?? Carbon::now($tz)->toDateString();

        $shiftLabel = $assignment
            ? $assignment->name.' ('.substr((string) $assignment->start_time, 0, 5).'-'.substr((string) $assignment->end_time, 0, 5).')'
            : null;

        $plotting = $this->resolveAttendancePlotting($attendance, $user);

        $checkIn = $attendance?->check_in_at?->copy()->setTimezone($tz)->format('H:i');
        $checkOut = $attendance?->check_out_at?->copy()->setTimezone($tz)->format('H:i');

        $status = $this->resolveAttendanceRowStatus($attendance, $absence, $scheduleDate, $today);

        $lateMinutes = null;
        if ($attendance && $attendance->check_in_at && str_contains((string) $attendance->attendance_status, 'TELAT')) {
            $lateMinutes = $attendance->late_minutes;
        }

        $photoAttendance = null;
        if ($attendance && $attendance->selfie_photo_path) {
            $photoAttendance = [
                'path' => $attendance->selfie_photo_path,
                'url' => $this->directPublicStorageUrl($attendance->selfie_photo_path),
                'api_inline_url' => url('/api/attendances/'.$attendance->id.'/selfie-inline'),
            ];
        }

        $row = [
            'schedule_id' => $schedule->id,
            'tanggal' => $scheduleDate,
            'nama_karyawan' => $user?->full_name,
            'user' => $user ? [
                'id' => $user->id,
                'full_name' => $user->full_name,
                'role' => $user->role,
            ] : null,
            'team_id' => $schedule->team_id,
            'shift' => [
                'assignment_id' => $assignment?->id,
                'name' => $assignment?->name,
                'label' => $shiftLabel,
                'code' => $assignment?->code,
                'start_time' => $assignment?->start_time,
                'end_time' => $assignment?->end_time,
            ],
            'plotting' => $plotting,
            'absen_masuk' => $checkIn,
            'absen_keluar' => $checkOut,
            'status' => $status['key'],
            'status_label' => $status['label'],
            'late_minutes' => $lateMinutes,
            'photo_attendance' => $photoAttendance,
            'timezone' => $tz,
        ];

        $row['absence'] = null;

        if ($status['key'] === 'absen') {
            $row['absence'] = $this->mapScheduleAbsence($absence);
        }

        return $row;
    }

    /**
     * @return string|array{id: int, name: string, type: string|null}|null
     */
    protected function resolveAttendancePlotting(?Attendance $attendance, ?User $user): string|array|null
    {
        if (! $attendance) {
            return null;
        }

        if ($user?->role === 'komandan_regu') {
            return 'komandan_regu';
        }

        if ($user?->role === 'admin_project') {
            return 'admin project';
        }

        if ($attendance->post) {
            return [
                'id' => $attendance->post->id,
                'name' => $attendance->post->name,
                'type' => $attendance->post->type,
            ];
        }

        return null;
    }

    protected function mapScheduleAbsence(?Absence $absence): ?array
    {
        if (! $absence) {
            return null;
        }

        return [
            'id' => $absence->id,
            'type' => $absence->absence_type,
            'label' => $absence->label,
            'summary_key' => Absence::TYPE_TO_SUMMARY_KEY[$absence->absence_type] ?? null,
        ];
    }

    protected function resolveAttendanceRowStatus(
        ?Attendance $attendance,
        $absence,
        string $scheduleDate,
        string $today
    ): array
    {
        if (! $attendance || ! $attendance->check_in_at) {
    
            if ($scheduleDate > $today) {
                return [
                    'key' => null,
                    'label' => null,
                ];
            }
    
            return [
                'key' => 'absen',
                'label' => 'Absen',
            ];
        }
    
        if (str_contains((string) $attendance->attendance_status, 'TELAT')) {
            return [
                'key' => 'terlambat',
                'label' => 'Terlambat',
            ];
        }
    
        return [
            'key' => 'tepat_waktu',
            'label' => 'Tepat Waktu',
        ];
    }

    protected function patrolScanBaseQuery(Project $project, array $v)
    {
        return PatrolScan::query()
            ->whereHas('attendance', function ($a) use ($project, $v) {
                $a->where('project_id', $project->id)
                    ->whereBetween('date', [$v['dari_tanggal'], $v['sampai_tanggal']]);
                if ($v['assignment_id']) {
                    $a->where('assignment_id', $v['assignment_id']);
                }
            });
    }

    /** Query patrol scan — danru (sama filter seperti endpoint JSON). */
    protected function patrolDanruFilteredQuery(Project $project, array $v)
    {
        $q = $this->patrolScanBaseQuery($project, $v)
            ->select(['patrol_scans.id', 'patrol_scans.attendance_id', 'patrol_scans.qr_code_id', 'patrol_scans.scan_time', 'patrol_scans.note'])
            ->whereHas('attendance.user', fn ($u) => $u->where('role', 'komandan_regu'));

        if ($v['team_id']) {
            $q->whereHas('attendance.schedule', fn ($s) => $s->where('team_id', $v['team_id']));
        }
        if ($v['team_name']) {
            $q->whereHas('attendance.schedule.team', fn ($t) => $t->where('name', 'like', '%'.$v['team_name'].'%'));
        }
        if ($v['post_id']) {
            $q->whereHas('qrCode.patrolPoint', fn ($p) => $p->where('post_id', $v['post_id']));
        }
        if ($v['post_name']) {
            $q->whereHas('qrCode.patrolPoint.post', fn ($p) => $p->where('name', 'like', '%'.$v['post_name'].'%'));
        }
        if ($v['user_id']) {
            $q->whereHas('attendance', fn ($a) => $a->where('user_id', $v['user_id']));
        }
        if ($v['employee_name']) {
            $q->whereHas('attendance.user', fn ($u) => $u->where('full_name', 'like', '%'.$v['employee_name'].'%'));
        }

        return $q;
    }

    /** Query patrol scan — pos / anggota. */
    protected function patrolPosFilteredQuery(Project $project, array $v)
    {
        $q = $this->patrolScanBaseQuery($project, $v)
            ->select(['patrol_scans.id', 'patrol_scans.attendance_id', 'patrol_scans.qr_code_id', 'patrol_scans.scan_time', 'patrol_scans.note'])
            ->whereHas('attendance.user', fn ($u) => $u->where('role', 'anggota'));

        if ($v['post_id']) {
            $q->whereHas('attendance', fn ($a) => $a->where('post_id', $v['post_id']));
        }
        if ($v['post_name']) {
            $q->whereHas('attendance.post', fn ($p) => $p->where('name', 'like', '%'.$v['post_name'].'%'));
        }
        if ($v['user_id']) {
            $q->whereHas('attendance', fn ($a) => $a->where('user_id', $v['user_id']));
        }
        if ($v['employee_name']) {
            $q->whereHas('attendance.user', fn ($u) => $u->where('full_name', 'like', '%'.$v['employee_name'].'%'));
        }
        if ($v['team_id']) {
            $q->whereHas('attendance.schedule', fn ($s) => $s->where('team_id', $v['team_id']));
        }

        return $q;
    }

    protected function mapPatrolPhotos(PatrolScan $scan): array
    {
        return $scan->photos->map(function ($photo) {
            return [
                'id' => $photo->id,
                'path' => $photo->photo,
                'url' => $this->directPublicStorageUrl($photo->photo),
                'api_inline_url' => url('/api/patrol-scan-photo/'.$photo->id.'/inline'),
            ];
        })->values()->all();
    }

    protected function mapPatrolDanruRow(PatrolScan $scan, string $tz): array
    {
        $point = $scan->qrCode?->patrolPoint;
        $post = $point?->post;
        $att = $scan->attendance;
        $tanggal = $att?->date instanceof Carbon
            ? $att->date->format('Y-m-d')
            : ($att?->date ? (string) $att->date : null);

        $attendancePhoto = null;
        if ($att && $att->selfie_photo_path) {
            $attendancePhoto = [
                'path' => $att->selfie_photo_path,
                'url' => $this->directPublicStorageUrl($att->selfie_photo_path),
                'api_inline_url' => url('/api/attendances/'.$att->id.'/selfie-inline'),
            ];
        }

        return [
            'scan_id' => $scan->id,
            'tanggal' => $tanggal,
            'nama_danru' => $att?->user?->full_name,
            'user_id' => $att?->user_id,
            'titik_patroli' => $point?->name,
            'patrol_point_id' => $point?->id,
            'pos' => $post ? ['id' => $post->id, 'name' => $post->name] : null,
            'waktu_scan' => $scan->scan_time?->copy()->setTimezone($tz)->format('H:i'),
            'waktu_scan_iso' => $scan->scan_time?->toIso8601String(),
            'notes' => $scan->note,
            'photo_attendance' => $attendancePhoto,
            'photo_scan' => $this->mapPatrolPhotos($scan),
            'timezone' => $tz,
        ];
    }

    protected function mapPatrolPosRow(PatrolScan $scan, string $tz): array
    {
        $point = $scan->qrCode?->patrolPoint;
        $attPost = $scan->attendance?->post;
        $att = $scan->attendance;

        $attendancePhoto = null;
        if ($att && $att->selfie_photo_path) {
            $attendancePhoto = [
                'path' => $att->selfie_photo_path,
                'url' => $this->directPublicStorageUrl($att->selfie_photo_path),
                'api_inline_url' => url('/api/attendances/'.$att->id.'/selfie-inline'),
            ];
        }

        return [
            'scan_id' => $scan->id,
            'tanggal' => $scan->attendance?->date instanceof Carbon
                ? $scan->attendance->date->format('Y-m-d')
                : ($scan->attendance?->date ? (string) $scan->attendance->date : null),
            'nama_anggota' => $scan->attendance?->user?->full_name,
            'user_id' => $scan->attendance?->user_id,
            'pos' => $attPost ? ['id' => $attPost->id, 'name' => $attPost->name, 'type' => $attPost->type] : null,
            'titik_patroli' => $point?->name,
            'patrol_point_id' => $point?->id,
            'waktu_scan' => $scan->scan_time?->copy()->setTimezone($tz)->format('H:i'),
            'waktu_scan_iso' => $scan->scan_time?->toIso8601String(),
            'notes' => $scan->note,
            'photo_attendance' => $attendancePhoto,
            'photo_scan' => $this->mapPatrolPhotos($scan),
            'timezone' => $tz,
        ];
    }
}
