<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\BuildsProjectReportData;
use App\Http\Controllers\Controller;
use App\Models\PatrolScan;
use App\Models\Project;
use App\Models\Schedule;
use App\Models\User;
use App\Services\ScheduleCacheService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Laporan per project: kehadiran, patrol danru, patrol pos, dan gabungan (semua).
 *
 * HO: boleh akses semua project di organization-nya ({project} di URL). Admin lapang (admin_project): hanya project sendiri.
 *
 * Tanggal: jika `dari_tanggal` & `sampai_tanggal` kosong → otomatis bulan berjalan menurut `current_time` (Y-m-d H:i:s, timezone project) atau waktu sekarang.
 *
 * Filter: kehadiran — shift (assignment_id), karyawan (user_id); patrol danru — regu, post; patrol pos — post, karyawan.
 *
 * Paginasi: default page & per_page. Tambahkan `tanpa_paginasi=true` untuk mengembalikan seluruh baris (tanpa page/per_page).
 */
class ProjectReportController extends Controller
{
    use BuildsProjectReportData;

    private const ATTENDANCE_REPORT_CACHE_TTL_SECONDS = 300;

    public function __construct(
        protected ScheduleCacheService $scheduleCacheService,
    ) {}

    protected function getCacheKey(string $type, int $projectId, array $filters): string
    {
        $version = Cache::get('project_reports_'.$projectId.'_v', 1);

        return 'report:'.$type.':'.$projectId.':v'.$version.':'.md5(serialize($filters));
    }

    public function attendanceReport(Request $request, Project $project)
    {
        $this->authorize('view', $project);

        $v = $this->validatedFilters($request, $project, true);
        $this->validateAttendanceReportUserFilter($request, $project, $v['user_id'] ?? null);

        $cacheVersion = $this->scheduleCacheService->getScheduleSheetCacheVersion($project->id);
        $cacheKey = $this->scheduleCacheService->attendanceReportCacheKey($project->id, $v, $cacheVersion);

        return Cache::remember(
            $cacheKey,
            now()->addSeconds(self::ATTENDANCE_REPORT_CACHE_TTL_SECONDS),
            function () use ($project, $v) {
                $tz = $this->projectTimezone($project);
                $today = \Carbon\Carbon::now($tz)->toDateString();
                $base = $this->attendanceBaseQuery($project, $v);
                $summary = $this->attendanceSummary(clone $base, $today);

                $listQuery = clone $base;
                $this->applyAttendanceStatusFilter($listQuery, $v['status'] ?? null, $today);

                $result = $this->fetchReportList(
                    $listQuery
                        ->with(['user', 'assignment', 'team', 'absence', 'attendance.post'])
                        ->orderBy('date')
                        ->orderBy('user_id'),
                    $v
                );

                $rows = $result['items']->map(function (Schedule $schedule) use ($tz, $today) {
                    return $this->mapAttendanceRow($schedule, $tz, $today);
                })->values();

                return response()->json([
                    'success' => true,
                    'report' => 'laporan_kehadiran',
                    'project' => ['id' => $project->id, 'name' => $project->name],
                    'filters_applied' => $this->publicFilters($v),
                    'summary' => $summary,
                    'data' => $rows,
                    'pagination' => $result['pagination'],
                    'field_descriptions' => [
                        'tanggal' => 'Tanggal jadwal (shift).',
                        'shift' => 'Assignment (nama, kode, jam).',
                        'plotting' => 'Pos dari attendance.post; komandan_regu / admin project jika role sesuai; null jika belum ada data.',
                        'absen_masuk' => 'Jam check-in (timezone project).',
                        'absen_keluar' => 'Jam check-out (timezone project).',
                        'status' => 'tepat_waktu | terlambat | absen | cuti | sakit | izin | alfa | null (jadwal mendatang tanpa attendance).',
                        'absence' => 'Data absence dari schedule sheet; hanya ada jika status absen.',
                        'late_minutes' => 'Menit terlambat jika status telat; null jika tidak telat.',
                        'photo_attendance' => 'Foto absen kehadiran (selfie) beserta url publiknya.',
                    ],
                ]);
            }
        );
    }

    public function patrolDanruReport(Request $request, Project $project)
    {
        $this->authorize('view', $project);

        $v = $this->validatedFilters($request, $project, false);
        $this->validatePatrolDanruUserFilter($project, $v['user_id'] ?? null);

        $cacheKey = $this->getCacheKey('danru', $project->id, $v);
        
        return Cache::remember($cacheKey, 60, function () use ($project, $v) {
            $tz = $this->projectTimezone($project);

            $result = $this->fetchReportList(
                $this->patrolDanruFilteredQuery($project, $v)
                    ->with(['attendance.user', 'qrCode.patrolPoint.post', 'photos'])
                    ->orderBy('scan_time'),
                $v
            );

            $rows = $result['items']->map(fn (PatrolScan $scan) => $this->mapPatrolDanruRow($scan, $tz))->values();

            return response()->json([
                'success' => true,
                'report' => 'laporan_patrol_danru',
                'project' => ['id' => $project->id, 'name' => $project->name],
                'filters_applied' => $this->publicFilters($v),
                'summary' => [
                    'total_scan_rows' => $result['pagination']['total'],
                ],
                'data' => $rows,
                'pagination' => $result['pagination'],
                'field_descriptions' => [
                    'tanggal' => 'Tanggal attendance (shift).',
                    'nama_danru' => 'Komandan regu.',
                    'titik_patroli' => 'Patrol point dari QR.',
                    'waktu_scan' => 'Waktu scan (timezone project).',
                    'notes' => 'Catatan scan.',
                    'photo_attendance' => 'Foto absen kehadiran (selfie) beserta url publiknya.',
                    'photo_scan' => 'Array foto; url = link publik /storage (butuh storage:link).',
                ],
            ]);
        });
    }

    public function patrolPosReport(Request $request, Project $project)
    {
        $this->authorize('view', $project);

        $v = $this->validatedFilters($request, $project, false);
        $this->validatePatrolPosUserFilter($project, $v['user_id'] ?? null);

        $cacheKey = $this->getCacheKey('pos', $project->id, $v);
        
        return Cache::remember($cacheKey, 60, function () use ($project, $v) {
            $tz = $this->projectTimezone($project);

            $result = $this->fetchReportList(
                $this->patrolPosFilteredQuery($project, $v)
                    ->with(['attendance.user', 'attendance.post', 'qrCode.patrolPoint.post', 'photos'])
                    ->orderBy('scan_time'),
                $v
            );

            $rows = $result['items']->map(fn (PatrolScan $scan) => $this->mapPatrolPosRow($scan, $tz))->values();

            return response()->json([
                'success' => true,
                'report' => 'laporan_patrol_pos',
                'project' => ['id' => $project->id, 'name' => $project->name],
                'filters_applied' => $this->publicFilters($v),
                'summary' => [
                    'total_scan_rows' => $result['pagination']['total'],
                ],
                'data' => $rows,
                'pagination' => $result['pagination'],
                'field_descriptions' => [
                    'tanggal' => 'Tanggal attendance.',
                    'nama_anggota' => 'Anggota yang scan.',
                    'pos' => 'Pos (plotting).',
                    'titik_patroli' => 'Patrol point.',
                    'waktu_scan' => 'Waktu scan (timezone project).',
                    'notes' => 'Catatan.',
                    'photo_attendance' => 'Foto absen kehadiran (selfie) beserta url publiknya.',
                    'photo_scan' => 'Array foto patrol; url publik /storage.',
                ],
            ]);
        });
    }

    /**
     * Kategori "semua": ketiga laporan dalam satu response (masing-masing paginasi sendiri).
     */
    public function allReports(Request $request, Project $project)
    {
        $this->authorize('view', $project);

        $v = $this->validatedFilters($request, $project, true);
        if ($v['user_id'] ?? null) {
            $this->validateAttendanceReportUserFilter($request, $project, $v['user_id']);
        }

        $cacheKey = $this->getCacheKey('all', $project->id, $v);
        
        return Cache::remember($cacheKey, 60, function () use ($project, $v) {
            $tz = $this->projectTimezone($project);
            $today = \Carbon\Carbon::now($tz)->toDateString();

            $targetUser = ($v['user_id'] ?? null) ? User::find($v['user_id']) : null;
            $vDanru = $v;
            $vPos = $v;
            if ($targetUser) {
                $vDanru['user_id'] = $targetUser->role === 'komandan_regu' ? $targetUser->id : null;
                $vPos['user_id'] = $targetUser->role === 'anggota' ? $targetUser->id : null;
            }

            $base = $this->attendanceBaseQuery($project, $v);
            $summary = $this->attendanceSummary(clone $base, $today);

            $listQuery = clone $base;
            $this->applyAttendanceStatusFilter($listQuery, $v['status'] ?? null, $today);
            $attResult = $this->fetchReportList(
                $listQuery
                    ->with(['user', 'assignment', 'team', 'absence', 'attendance.post'])
                    ->orderBy('date')
                    ->orderBy('user_id'),
                $v
            );

            $attRows = $attResult['items']->map(fn (Schedule $s) => $this->mapAttendanceRow($s, $tz, $today))->values();

            $danruResult = $this->fetchReportList(
                $this->patrolDanruFilteredQuery($project, $vDanru)
                    ->with(['attendance.user', 'qrCode.patrolPoint.post', 'photos'])
                    ->orderBy('scan_time'),
                $v
            );
            $danruRows = $danruResult['items']->map(fn (PatrolScan $s) => $this->mapPatrolDanruRow($s, $tz))->values();

            $posResult = $this->fetchReportList(
                $this->patrolPosFilteredQuery($project, $vPos)
                    ->with(['attendance.user', 'attendance.post', 'qrCode.patrolPoint.post', 'photos'])
                    ->orderBy('scan_time'),
                $v
            );
            $posRows = $posResult['items']->map(fn (PatrolScan $s) => $this->mapPatrolPosRow($s, $tz))->values();

            return response()->json([
                'success' => true,
                'report' => 'semua',
                'project' => ['id' => $project->id, 'name' => $project->name],
                'filters_applied' => $this->publicFilters($v),
                'laporan_kehadiran' => [
                    'summary' => $summary,
                    'data' => $attRows,
                    'pagination' => $attResult['pagination'],
                ],
                'laporan_patrol_danru' => [
                    'summary' => ['total_scan_rows' => $danruResult['pagination']['total']],
                    'data' => $danruRows,
                    'pagination' => $danruResult['pagination'],
                ],
                'laporan_patrol_pos' => [
                    'summary' => ['total_scan_rows' => $posResult['pagination']['total']],
                    'data' => $posRows,
                    'pagination' => $posResult['pagination'],
                ],
            ]);
        });
    }
}
