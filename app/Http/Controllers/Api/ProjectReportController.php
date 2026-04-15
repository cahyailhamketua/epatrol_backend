<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\BuildsProjectReportData;
use App\Http\Controllers\Controller;
use App\Models\PatrolScan;
use App\Models\Project;
use App\Models\Schedule;
use App\Models\User;
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
 */
class ProjectReportController extends Controller
{
    use BuildsProjectReportData;

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

        $cacheKey = $this->getCacheKey('attendance', $project->id, $v);
        
        return Cache::remember($cacheKey, 60, function () use ($project, $v) {
            $tz = $this->projectTimezone($project);
            $base = $this->attendanceBaseQuery($project, $v);
            $summary = $this->attendanceSummary(clone $base);

            $listQuery = clone $base;
            $this->applyAttendanceStatusFilter($listQuery, $v['status'] ?? null);

            $paginator = $listQuery
                ->with(['user', 'assignment', 'team', 'absence', 'attendance.post'])
                ->orderByDesc('date')
                ->orderBy('user_id')
                ->paginate($v['per_page'], ['*'], 'page', $v['page']);

            $rows = collect($paginator->items())->map(function (Schedule $schedule) use ($tz) {
                return $this->mapAttendanceRow($schedule, $tz);
            })->values();

            return response()->json([
                'success' => true,
                'report' => 'laporan_kehadiran',
                'project' => ['id' => $project->id, 'name' => $project->name],
                'filters_applied' => $this->publicFilters($v),
                'summary' => $summary,
                'data' => $rows,
                'pagination' => [
                    'total' => $paginator->total(),
                    'per_page' => $paginator->perPage(),
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                ],
                'field_descriptions' => [
                    'tanggal' => 'Tanggal jadwal (shift).',
                    'shift' => 'Assignment (nama, kode, jam).',
                    'plotting' => 'Pos setelah check-in.',
                    'absen_masuk' => 'Jam check-in (timezone project).',
                    'absen_keluar' => 'Jam check-out (timezone project).',
                    'status' => 'tepat_waktu | terlambat | absen | cuti | sakit | izin | alfa.',
                    'late_minutes' => 'Menit terlambat jika status telat; null jika tidak telat.',
                    'photo_attendance' => 'Foto absen kehadiran (selfie) beserta url publiknya.',
                ],
            ]);
        });
    }

    public function patrolDanruReport(Request $request, Project $project)
    {
        $this->authorize('view', $project);

        $v = $this->validatedFilters($request, $project, false);
        $this->validatePatrolDanruUserFilter($project, $v['user_id'] ?? null);

        $cacheKey = $this->getCacheKey('danru', $project->id, $v);
        
        return Cache::remember($cacheKey, 60, function () use ($project, $v) {
            $tz = $this->projectTimezone($project);

            $paginator = $this->patrolDanruFilteredQuery($project, $v)
                ->with(['attendance.user', 'qrCode.patrolPoint.post', 'photos'])
                ->orderByDesc('scan_time')
                ->paginate($v['per_page'], ['*'], 'page', $v['page']);

            $rows = collect($paginator->items())->map(fn (PatrolScan $scan) => $this->mapPatrolDanruRow($scan, $tz))->values();

            return response()->json([
                'success' => true,
                'report' => 'laporan_patrol_danru',
                'project' => ['id' => $project->id, 'name' => $project->name],
                'filters_applied' => $this->publicFilters($v),
                'summary' => [
                    'total_scan_rows' => $paginator->total(),
                ],
                'data' => $rows,
                'pagination' => [
                    'total' => $paginator->total(),
                    'per_page' => $paginator->perPage(),
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                ],
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

            $paginator = $this->patrolPosFilteredQuery($project, $v)
                ->with(['attendance.user', 'attendance.post', 'qrCode.patrolPoint.post', 'photos'])
                ->orderByDesc('scan_time')
                ->paginate($v['per_page'], ['*'], 'page', $v['page']);

            $rows = collect($paginator->items())->map(fn (PatrolScan $scan) => $this->mapPatrolPosRow($scan, $tz))->values();

            return response()->json([
                'success' => true,
                'report' => 'laporan_patrol_pos',
                'project' => ['id' => $project->id, 'name' => $project->name],
                'filters_applied' => $this->publicFilters($v),
                'summary' => [
                    'total_scan_rows' => $paginator->total(),
                ],
                'data' => $rows,
                'pagination' => [
                    'total' => $paginator->total(),
                    'per_page' => $paginator->perPage(),
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                ],
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

            $targetUser = ($v['user_id'] ?? null) ? User::find($v['user_id']) : null;
            $vDanru = $v;
            $vPos = $v;
            if ($targetUser) {
                $vDanru['user_id'] = $targetUser->role === 'komandan_regu' ? $targetUser->id : null;
                $vPos['user_id'] = $targetUser->role === 'anggota' ? $targetUser->id : null;
            }

            $base = $this->attendanceBaseQuery($project, $v);
            $summary = $this->attendanceSummary(clone $base);

            $listQuery = clone $base;
            $this->applyAttendanceStatusFilter($listQuery, $v['status'] ?? null);
            $attPaginator = $listQuery
                ->with(['user', 'assignment', 'team', 'absence', 'attendance.post'])
                ->orderByDesc('date')
                ->orderBy('user_id')
                ->paginate($v['per_page'], ['*'], 'page', $v['page']);

            $attRows = collect($attPaginator->items())->map(fn (Schedule $s) => $this->mapAttendanceRow($s, $tz))->values();

            $danruPaginator = $this->patrolDanruFilteredQuery($project, $vDanru)
                ->with(['attendance.user', 'qrCode.patrolPoint.post', 'photos'])
                ->orderByDesc('scan_time')
                ->paginate($v['per_page'], ['*'], 'page', $v['page']);
            $danruRows = collect($danruPaginator->items())->map(fn (PatrolScan $s) => $this->mapPatrolDanruRow($s, $tz))->values();

            $posPaginator = $this->patrolPosFilteredQuery($project, $vPos)
                ->with(['attendance.user', 'attendance.post', 'qrCode.patrolPoint.post', 'photos'])
                ->orderByDesc('scan_time')
                ->paginate($v['per_page'], ['*'], 'page', $v['page']);
            $posRows = collect($posPaginator->items())->map(fn (PatrolScan $s) => $this->mapPatrolPosRow($s, $tz))->values();

            return response()->json([
                'success' => true,
                'report' => 'semua',
                'project' => ['id' => $project->id, 'name' => $project->name],
                'filters_applied' => $this->publicFilters($v),
                'laporan_kehadiran' => [
                    'summary' => $summary,
                    'data' => $attRows,
                    'pagination' => [
                        'total' => $attPaginator->total(),
                        'per_page' => $attPaginator->perPage(),
                        'current_page' => $attPaginator->currentPage(),
                        'last_page' => $attPaginator->lastPage(),
                    ],
                ],
                'laporan_patrol_danru' => [
                    'summary' => ['total_scan_rows' => $danruPaginator->total()],
                    'data' => $danruRows,
                    'pagination' => [
                        'total' => $danruPaginator->total(),
                        'per_page' => $danruPaginator->perPage(),
                        'current_page' => $danruPaginator->currentPage(),
                        'last_page' => $danruPaginator->lastPage(),
                    ],
                ],
                'laporan_patrol_pos' => [
                    'summary' => ['total_scan_rows' => $posPaginator->total()],
                    'data' => $posRows,
                    'pagination' => [
                        'total' => $posPaginator->total(),
                        'per_page' => $posPaginator->perPage(),
                        'current_page' => $posPaginator->currentPage(),
                        'last_page' => $posPaginator->lastPage(),
                    ],
                ],
            ]);
        });
    }
}
