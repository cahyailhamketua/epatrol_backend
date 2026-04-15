<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\BuildsProjectReportData;
use App\Http\Controllers\Controller;
use App\Models\PatrolScan;
use App\Models\Project;
use App\Models\Schedule;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Unduh laporan (Excel .xlsx & PDF) — filter sama dengan endpoint JSON laporan, tanpa paginasi.
 *
 * Query: dari_tanggal & sampai_tanggal opsional (default: bulan berjalan dari current_time / sekarang). Filter + limit (max 5000).
 */
class ProjectReportExportController extends Controller
{
    use BuildsProjectReportData;

    public function exportAttendanceExcel(Request $request, Project $project): StreamedResponse
    {
        $this->authorize('view', $project);

        $v = $this->validatedExportFilters($request, $project, true);
        if ($v['user_id'] ?? null) {
            $this->validateAttendanceReportUserFilter($request, $project, $v['user_id']);
        }
        $tz = $this->projectTimezone($project);

        $base = $this->attendanceBaseQuery($project, $v);
        $listQuery = clone $base;
        $this->applyAttendanceStatusFilter($listQuery, $v['status'] ?? null);

        $schedules = $listQuery
            ->with(['user', 'assignment', 'team', 'absence', 'attendance.post'])
            ->orderByDesc('date')
            ->orderBy('user_id')
            ->limit($v['limit'])
            ->get();

        $rows = $schedules->map(fn (Schedule $s) => $this->mapAttendanceRow($s, $tz));

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Kehadiran');

        $headers = [
            'Tanggal', 'Nama', 'Role', 'Shift', 'Plotting', 'Absen Masuk', 'Absen Keluar',
            'Status', 'Telat (menit)', 'Link foto (publik)',
        ];
        $sheet->fromArray($headers, null, 'A1');

        $r = 2;
        foreach ($rows as $row) {
            $plot = ($row['plotting'] ?? [])['name'] ?? '-';
            $sheet->setCellValue('A'.$r, $row['tanggal']);
            $sheet->setCellValue('B'.$r, $row['user']['full_name'] ?? '-');
            $sheet->setCellValue('C'.$r, $row['user']['role'] ?? '-');
            $sheet->setCellValue('D'.$r, $row['shift']['label'] ?? '-');
            $sheet->setCellValue('E'.$r, $plot);
            $sheet->setCellValue('F'.$r, $row['absen_masuk'] ?? '-');
            $sheet->setCellValue('G'.$r, $row['absen_keluar'] ?? '-');
            $sheet->setCellValue('H'.$r, $row['status_label'] ?? '-');
            $sheet->setCellValue('I'.$r, $row['late_minutes'] ?? '');
            $sheet->setCellValue('J'.$r, ($row['photo'] ?? [])['url'] ?? '-');
            $r++;
        }

        foreach (range('A', 'J') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $fn = $this->exportBaseFilename($project, 'kehadiran', $v['dari_tanggal'], $v['sampai_tanggal']).'.xlsx';

        return $this->streamSpreadsheet($spreadsheet, $fn);
    }

    public function exportAttendancePdf(Request $request, Project $project)
    {
        $this->authorize('view', $project);

        $v = $this->validatedExportFilters($request, $project, true);
        if ($v['user_id'] ?? null) {
            $this->validateAttendanceReportUserFilter($request, $project, $v['user_id']);
        }
        $tz = $this->projectTimezone($project);

        $base = $this->attendanceBaseQuery($project, $v);
        $listQuery = clone $base;
        $this->applyAttendanceStatusFilter($listQuery, $v['status'] ?? null);

        $schedules = $listQuery
            ->with(['user', 'assignment', 'team', 'absence', 'attendance.post'])
            ->orderByDesc('date')
            ->orderBy('user_id')
            ->limit($v['limit'])
            ->get();

        $rows = $schedules->map(fn (Schedule $s) => $this->mapAttendanceRow($s, $tz));
        $summary = $this->attendanceSummary(clone $this->attendanceBaseQuery($project, $v));

        $html = $this->pdfWrap(
            'Laporan Kehadiran — '.$this->e($project->name),
            $this->pdfFilterLine($v),
            $this->pdfSummaryTable($summary),
            $this->pdfTable(
                ['Tanggal', 'Nama', 'Shift', 'Plotting', 'Masuk', 'Keluar', 'Status', 'Telat'],
                $rows->map(fn ($row) => [
                    $this->e($row['tanggal']),
                    $this->e($row['user']['full_name'] ?? '-'),
                    $this->e($row['shift']['label'] ?? '-'),
                    $this->e(($row['plotting'] ?? [])['name'] ?? '-'),
                    $this->e($row['absen_masuk'] ?? '-'),
                    $this->e($row['absen_keluar'] ?? '-'),
                    $this->e($row['status_label'] ?? '-'),
                    $row['late_minutes'] ?? '-',
                ])->all()
            )
        );

        $fn = $this->exportBaseFilename($project, 'kehadiran', $v['dari_tanggal'], $v['sampai_tanggal']).'.pdf';

        return Pdf::loadHTML($html)->setPaper('a4', 'landscape')->download($fn);
    }

    public function exportPatrolDanruExcel(Request $request, Project $project): StreamedResponse
    {
        $this->authorize('view', $project);

        $v = $this->validatedExportFilters($request, $project, false);
        if ($v['user_id'] ?? null) {
            $this->validatePatrolDanruUserFilter($project, $v['user_id']);
        }
        $tz = $this->projectTimezone($project);

        $scans = $this->patrolDanruFilteredQuery($project, $v)
            ->with(['attendance.user', 'qrCode.patrolPoint.post', 'photos'])
            ->orderByDesc('scan_time')
            ->limit($v['limit'])
            ->get();

        $rows = $scans->map(fn (PatrolScan $s) => $this->mapPatrolDanruRow($s, $tz));

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Patrol Danru');

        $sheet->fromArray(['Tanggal', 'Nama Danru', 'Titik Patroli', 'Pos', 'Waktu Scan', 'Catatan', 'Link foto (pertama)'], null, 'A1');

        $r = 2;
        foreach ($rows as $row) {
            $sheet->setCellValue('A'.$r, $row['tanggal'] ?? '-');
            $sheet->setCellValue('B'.$r, $row['nama_danru'] ?? '-');
            $sheet->setCellValue('C'.$r, $row['titik_patroli'] ?? '-');
            $sheet->setCellValue('D'.$r, ($row['pos'] ?? [])['name'] ?? '-');
            $sheet->setCellValue('E'.$r, $row['waktu_scan'] ?? '-');
            $sheet->setCellValue('F'.$r, $row['notes'] ?? '');
            $sheet->setCellValue('G'.$r, $this->firstPatrolDirectPhotoUrl($row));
            $r++;
        }
        foreach (range('A', 'G') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $fn = $this->exportBaseFilename($project, 'patrol-danru', $v['dari_tanggal'], $v['sampai_tanggal']).'.xlsx';

        return $this->streamSpreadsheet($spreadsheet, $fn);
    }

    public function exportPatrolDanruPdf(Request $request, Project $project)
    {
        $this->authorize('view', $project);

        $v = $this->validatedExportFilters($request, $project, false);
        if ($v['user_id'] ?? null) {
            $this->validatePatrolDanruUserFilter($project, $v['user_id']);
        }
        $tz = $this->projectTimezone($project);

        $scans = $this->patrolDanruFilteredQuery($project, $v)
            ->with(['attendance.user', 'qrCode.patrolPoint.post', 'photos'])
            ->orderByDesc('scan_time')
            ->limit($v['limit'])
            ->get();

        $rows = $scans->map(fn (PatrolScan $s) => $this->mapPatrolDanruRow($s, $tz));

        $html = $this->pdfWrap(
            'Laporan Patrol Danru — '.$this->e($project->name),
            $this->pdfFilterLine($v),
            '',
            $this->pdfTable(
                ['Tanggal', 'Nama Danru', 'Titik Patroli', 'Pos', 'Waktu', 'Catatan'],
                $rows->map(fn ($row) => [
                    $this->e($row['tanggal'] ?? '-'),
                    $this->e($row['nama_danru'] ?? '-'),
                    $this->e($row['titik_patroli'] ?? '-'),
                    $this->e(($row['pos'] ?? [])['name'] ?? '-'),
                    $this->e($row['waktu_scan'] ?? '-'),
                    $this->e($row['notes'] ?? ''),
                ])->all()
            )
        );

        $fn = $this->exportBaseFilename($project, 'patrol-danru', $v['dari_tanggal'], $v['sampai_tanggal']).'.pdf';

        return Pdf::loadHTML($html)->setPaper('a4', 'landscape')->download($fn);
    }

    public function exportPatrolPosExcel(Request $request, Project $project): StreamedResponse
    {
        $this->authorize('view', $project);

        $v = $this->validatedExportFilters($request, $project, false);
        if ($v['user_id'] ?? null) {
            $this->validatePatrolPosUserFilter($project, $v['user_id']);
        }
        $tz = $this->projectTimezone($project);

        $scans = $this->patrolPosFilteredQuery($project, $v)
            ->with(['attendance.user', 'attendance.post', 'qrCode.patrolPoint.post', 'photos'])
            ->orderByDesc('scan_time')
            ->limit($v['limit'])
            ->get();

        $rows = $scans->map(fn (PatrolScan $s) => $this->mapPatrolPosRow($s, $tz));

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Patrol Pos');

        $sheet->fromArray(['Tanggal', 'Nama Anggota', 'Pos', 'Titik Patroli', 'Waktu Scan', 'Catatan', 'Link foto (pertama)'], null, 'A1');

        $r = 2;
        foreach ($rows as $row) {
            $sheet->setCellValue('A'.$r, $row['tanggal'] ?? '-');
            $sheet->setCellValue('B'.$r, $row['nama_anggota'] ?? '-');
            $sheet->setCellValue('C'.$r, ($row['pos'] ?? [])['name'] ?? '-');
            $sheet->setCellValue('D'.$r, $row['titik_patroli'] ?? '-');
            $sheet->setCellValue('E'.$r, $row['waktu_scan'] ?? '-');
            $sheet->setCellValue('F'.$r, $row['notes'] ?? '');
            $sheet->setCellValue('G'.$r, $this->firstPatrolDirectPhotoUrl($row));
            $r++;
        }
        foreach (range('A', 'G') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $fn = $this->exportBaseFilename($project, 'patrol-pos', $v['dari_tanggal'], $v['sampai_tanggal']).'.xlsx';

        return $this->streamSpreadsheet($spreadsheet, $fn);
    }

    public function exportPatrolPosPdf(Request $request, Project $project)
    {
        $this->authorize('view', $project);

        $v = $this->validatedExportFilters($request, $project, false);
        if ($v['user_id'] ?? null) {
            $this->validatePatrolPosUserFilter($project, $v['user_id']);
        }
        $tz = $this->projectTimezone($project);

        $scans = $this->patrolPosFilteredQuery($project, $v)
            ->with(['attendance.user', 'attendance.post', 'qrCode.patrolPoint.post', 'photos'])
            ->orderByDesc('scan_time')
            ->limit($v['limit'])
            ->get();

        $rows = $scans->map(fn (PatrolScan $s) => $this->mapPatrolPosRow($s, $tz));

        $html = $this->pdfWrap(
            'Laporan Patrol Pos — '.$this->e($project->name),
            $this->pdfFilterLine($v),
            '',
            $this->pdfTable(
                ['Tanggal', 'Nama', 'Pos', 'Titik Patroli', 'Waktu', 'Catatan'],
                $rows->map(fn ($row) => [
                    $this->e($row['tanggal'] ?? '-'),
                    $this->e($row['nama_anggota'] ?? '-'),
                    $this->e(($row['pos'] ?? [])['name'] ?? '-'),
                    $this->e($row['titik_patroli'] ?? '-'),
                    $this->e($row['waktu_scan'] ?? '-'),
                    $this->e($row['notes'] ?? ''),
                ])->all()
            )
        );

        $fn = $this->exportBaseFilename($project, 'patrol-pos', $v['dari_tanggal'], $v['sampai_tanggal']).'.pdf';

        return Pdf::loadHTML($html)->setPaper('a4', 'landscape')->download($fn);
    }

    public function exportAllExcel(Request $request, Project $project): StreamedResponse
    {
        $this->authorize('view', $project);

        $vAtt = $this->validatedExportFilters($request, $project, true);
        if ($vAtt['user_id'] ?? null) {
            $this->validateAttendanceReportUserFilter($request, $project, $vAtt['user_id']);
        }
        $tz = $this->projectTimezone($project);

        $targetUser = ($vAtt['user_id'] ?? null) ? User::find($vAtt['user_id']) : null;
        $vDanru = $vAtt;
        $vPos = $vAtt;
        if ($targetUser) {
            $vDanru['user_id'] = $targetUser->role === 'komandan_regu' ? $targetUser->id : null;
            $vPos['user_id'] = $targetUser->role === 'anggota' ? $targetUser->id : null;
        }

        $spreadsheet = new Spreadsheet;

        // Sheet 1: Kehadiran
        $base = $this->attendanceBaseQuery($project, $vAtt);
        $listQuery = clone $base;
        $this->applyAttendanceStatusFilter($listQuery, $vAtt['status'] ?? null);
        $schedules = $listQuery
            ->with(['user', 'assignment', 'team', 'absence', 'attendance.post'])
            ->orderByDesc('date')
            ->orderBy('user_id')
            ->limit($vAtt['limit'])
            ->get();
        $attRows = $schedules->map(fn (Schedule $s) => $this->mapAttendanceRow($s, $tz));

        $sheet1 = $spreadsheet->getActiveSheet();
        $sheet1->setTitle('Kehadiran');
        $sheet1->fromArray(['Tanggal', 'Nama', 'Shift', 'Plotting', 'Masuk', 'Keluar', 'Status', 'Telat'], null, 'A1');
        $r = 2;
        foreach ($attRows as $row) {
            $sheet1->setCellValue('A'.$r, $row['tanggal']);
            $sheet1->setCellValue('B'.$r, $row['user']['full_name'] ?? '-');
            $sheet1->setCellValue('C'.$r, $row['shift']['label'] ?? '-');
            $sheet1->setCellValue('D'.$r, ($row['plotting'] ?? [])['name'] ?? '-');
            $sheet1->setCellValue('E'.$r, $row['absen_masuk'] ?? '-');
            $sheet1->setCellValue('F'.$r, $row['absen_keluar'] ?? '-');
            $sheet1->setCellValue('G'.$r, $row['status_label'] ?? '-');
            $sheet1->setCellValue('H'.$r, $row['late_minutes'] ?? '');
            $r++;
        }

        // Sheet 2: Danru
        $danruScans = $this->patrolDanruFilteredQuery($project, $vDanru)
            ->with(['attendance.user', 'qrCode.patrolPoint.post', 'photos'])
            ->orderByDesc('scan_time')
            ->limit($vAtt['limit'])
            ->get();
        $danruRows = $danruScans->map(fn (PatrolScan $s) => $this->mapPatrolDanruRow($s, $tz));

        $sheet2 = $spreadsheet->createSheet();
        $sheet2->setTitle('Patrol Danru');
        $sheet2->fromArray(['Tanggal', 'Nama Danru', 'Titik', 'Pos', 'Waktu', 'Catatan'], null, 'A1');
        $r = 2;
        foreach ($danruRows as $row) {
            $sheet2->setCellValue('A'.$r, $row['tanggal'] ?? '-');
            $sheet2->setCellValue('B'.$r, $row['nama_danru'] ?? '-');
            $sheet2->setCellValue('C'.$r, $row['titik_patroli'] ?? '-');
            $sheet2->setCellValue('D'.$r, ($row['pos'] ?? [])['name'] ?? '-');
            $sheet2->setCellValue('E'.$r, $row['waktu_scan'] ?? '-');
            $sheet2->setCellValue('F'.$r, $row['notes'] ?? '');
            $r++;
        }

        // Sheet 3: Pos
        $posScans = $this->patrolPosFilteredQuery($project, $vPos)
            ->with(['attendance.user', 'attendance.post', 'qrCode.patrolPoint.post', 'photos'])
            ->orderByDesc('scan_time')
            ->limit($vAtt['limit'])
            ->get();
        $posRows = $posScans->map(fn (PatrolScan $s) => $this->mapPatrolPosRow($s, $tz));

        $sheet3 = $spreadsheet->createSheet();
        $sheet3->setTitle('Patrol Pos');
        $sheet3->fromArray(['Tanggal', 'Nama Anggota', 'Pos', 'Titik', 'Waktu', 'Catatan'], null, 'A1');
        $r = 2;
        foreach ($posRows as $row) {
            $sheet3->setCellValue('A'.$r, $row['tanggal'] ?? '-');
            $sheet3->setCellValue('B'.$r, $row['nama_anggota'] ?? '-');
            $sheet3->setCellValue('C'.$r, ($row['pos'] ?? [])['name'] ?? '-');
            $sheet3->setCellValue('D'.$r, $row['titik_patroli'] ?? '-');
            $sheet3->setCellValue('E'.$r, $row['waktu_scan'] ?? '-');
            $sheet3->setCellValue('F'.$r, $row['notes'] ?? '');
            $r++;
        }

        $spreadsheet->setActiveSheetIndex(0);

        $fn = $this->exportBaseFilename($project, 'semua-laporan', $vAtt['dari_tanggal'], $vAtt['sampai_tanggal']).'.xlsx';

        return $this->streamSpreadsheet($spreadsheet, $fn);
    }

    public function exportAllPdf(Request $request, Project $project)
    {
        $this->authorize('view', $project);

        $vAtt = $this->validatedExportFilters($request, $project, true);
        if ($vAtt['user_id'] ?? null) {
            $this->validateAttendanceReportUserFilter($request, $project, $vAtt['user_id']);
        }
        $tz = $this->projectTimezone($project);

        $targetUser = ($vAtt['user_id'] ?? null) ? User::find($vAtt['user_id']) : null;
        $vDanru = $vAtt;
        $vPos = $vAtt;
        if ($targetUser) {
            $vDanru['user_id'] = $targetUser->role === 'komandan_regu' ? $targetUser->id : null;
            $vPos['user_id'] = $targetUser->role === 'anggota' ? $targetUser->id : null;
        }

        $base = $this->attendanceBaseQuery($project, $vAtt);
        $listQuery = clone $base;
        $this->applyAttendanceStatusFilter($listQuery, $vAtt['status'] ?? null);
        $schedules = $listQuery
            ->with(['user', 'assignment', 'team', 'absence', 'attendance.post'])
            ->orderByDesc('date')
            ->orderBy('user_id')
            ->limit($vAtt['limit'])
            ->get();
        $attRows = $schedules->map(fn (Schedule $s) => $this->mapAttendanceRow($s, $tz));
        $summary = $this->attendanceSummary(clone $this->attendanceBaseQuery($project, $vAtt));

        $danruScans = $this->patrolDanruFilteredQuery($project, $vDanru)
            ->with(['attendance.user', 'qrCode.patrolPoint.post', 'photos'])
            ->orderByDesc('scan_time')
            ->limit($vAtt['limit'])
            ->get();
        $danruRows = $danruScans->map(fn (PatrolScan $s) => $this->mapPatrolDanruRow($s, $tz));

        $posScans = $this->patrolPosFilteredQuery($project, $vPos)
            ->with(['attendance.user', 'attendance.post', 'qrCode.patrolPoint.post', 'photos'])
            ->orderByDesc('scan_time')
            ->limit($vAtt['limit'])
            ->get();
        $posRows = $posScans->map(fn (PatrolScan $s) => $this->mapPatrolPosRow($s, $tz));

        $body = '<h2>Ringkasan kehadiran</h2>'.$this->pdfSummaryTable($summary);
        $body .= '<h2 style="margin-top:16px">Laporan kehadiran</h2>';
        $body .= $this->pdfTable(
            ['Tanggal', 'Nama', 'Shift', 'Plotting', 'Masuk', 'Keluar', 'Status', 'Telat (menit)'],
            $attRows->map(fn ($row) => [
                $this->e($row['tanggal']),
                $this->e($row['user']['full_name'] ?? '-'),
                $this->e($row['shift']['label'] ?? '-'),
                $this->e(($row['plotting'] ?? [])['name'] ?? '-'),
                $this->e($row['absen_masuk'] ?? '-'),
                $this->e($row['absen_keluar'] ?? '-'),
                $this->e($row['status_label'] ?? '-'),
                $row['late_minutes'] ?? '-',
            ])->all()
        );
        $body .= '<h2 style="margin-top:16px">Patrol Danru</h2>';
        $body .= $this->pdfTable(
            ['Tanggal', 'Nama', 'Titik', 'Pos', 'Waktu', 'Catatan'],
            $danruRows->map(fn ($row) => [
                $this->e($row['tanggal'] ?? '-'),
                $this->e($row['nama_danru'] ?? '-'),
                $this->e($row['titik_patroli'] ?? '-'),
                $this->e(($row['pos'] ?? [])['name'] ?? '-'),
                $this->e($row['waktu_scan'] ?? '-'),
                $this->e($row['notes'] ?? ''),
            ])->all()
        );
        $body .= '<h2 style="margin-top:16px">Patrol Pos</h2>';
        $body .= $this->pdfTable(
            ['Tanggal', 'Nama Anggota', 'Pos', 'Titik', 'Waktu', 'Catatan'],
            $posRows->map(fn ($row) => [
                $this->e($row['tanggal'] ?? '-'),
                $this->e($row['nama_anggota'] ?? '-'),
                $this->e(($row['pos'] ?? [])['name'] ?? '-'),
                $this->e($row['titik_patroli'] ?? '-'),
                $this->e($row['waktu_scan'] ?? '-'),
                $this->e($row['notes'] ?? ''),
            ])->all()
        );

        $html = $this->pdfWrap(
            'Semua laporan — '.$this->e($project->name),
            $this->pdfFilterLine($vAtt),
            '',
            $body
        );

        $fn = $this->exportBaseFilename($project, 'semua-laporan', $vAtt['dari_tanggal'], $vAtt['sampai_tanggal']).'.pdf';

        return Pdf::loadHTML($html)->setPaper('a4', 'landscape')->download($fn);
    }

    private function streamSpreadsheet(Spreadsheet $spreadsheet, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function exportBaseFilename(Project $project, string $slug, string $from, string $to): string
    {
        $code = $project->code ? preg_replace('/[^a-zA-Z0-9_-]+/', '-', $project->code) : 'project-'.$project->id;

        return $slug.'_'.$code.'_'.$from.'_'.$to;
    }

    private function firstPatrolDirectPhotoUrl(array $row): string
    {
        $photos = $row['photo_scan'] ?? [];

        return $photos[0]['url'] ?? '-';
    }

    private function e(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function pdfFilterLine(array $v): string
    {
        $parts = ['Periode: '.$this->e($v['dari_tanggal']).' s/d '.$this->e($v['sampai_tanggal'])];
        if (! empty($v['team_id'])) {
            $parts[] = 'Regu ID: '.$this->e((string) $v['team_id']);
        }
        if (! empty($v['post_id'])) {
            $parts[] = 'Pos ID: '.$this->e((string) $v['post_id']);
        }
        if (! empty($v['assignment_id'])) {
            $parts[] = 'Shift ID: '.$this->e((string) $v['assignment_id']);
        }
        if (! empty($v['user_id'])) {
            $parts[] = 'User ID: '.$this->e((string) $v['user_id']);
        }
        if (! empty($v['status'])) {
            $parts[] = 'Status: '.$this->e($v['status']);
        }
        if (! empty($v['limit'])) {
            $parts[] = 'Limit baris: '.$this->e((string) $v['limit']);
        }

        return '<p class="meta">'.implode(' · ', $parts).'</p>';
    }

    private function pdfSummaryTable(array $summary): string
    {
        $html = '<table class="sum"><tr>';
        foreach (['total_hadir_tepat_waktu' => 'Hadir tepat', 'total_telat' => 'Telat', 'total_absen_tidak_masuk' => 'Absen', 'total_karyawan_unik' => 'Karyawan (unik)', 'total_baris_jadwal' => 'Baris jadwal'] as $k => $label) {
            $html .= '<th>'.$this->e($label).'</th>';
        }
        $html .= '</tr><tr>';
        foreach (['total_hadir_tepat_waktu', 'total_telat', 'total_absen_tidak_masuk', 'total_karyawan_unik', 'total_baris_jadwal'] as $k) {
            $html .= '<td>'.(int) ($summary[$k] ?? 0).'</td>';
        }
        $html .= '</tr></table>';

        return $html;
    }

    private function pdfTable(array $headers, array $rows): string
    {
        $html = '<table><thead><tr>';
        foreach ($headers as $h) {
            $html .= '<th>'.$this->e($h).'</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= '<td>'.(is_numeric($cell) ? $cell : $this->e((string) $cell)).'</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';

        return $html;
    }

    private function pdfWrap(string $title, string $metaHtml, string $extraTop, string $bodyHtml): string
    {
        $css = 'body{font-family:DejaVu Sans,sans-serif;font-size:10px;}
            h1{font-size:14px;margin:0 0 8px;}
            h2{font-size:12px;margin:12px 0 6px;}
            .meta{color:#444;margin:0 0 8px;}
            table{border-collapse:collapse;width:100%;margin-bottom:8px;}
            th,td{border:1px solid #333;padding:3px 4px;text-align:left;}
            th{background:#eee;font-weight:bold;}
            table.sum th,table.sum td{text-align:center;padding:6px;}';

        return '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>'.$css.'</style></head><body>'
            .'<h1>'.$title.'</h1>'.$metaHtml.$extraTop.$bodyHtml.'</body></html>';
    }
}
