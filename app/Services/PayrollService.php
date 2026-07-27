<?php

namespace App\Services;

use App\Models\PayrollDetail;
use App\Models\PayrollPolicy;
use App\Models\PayrollProjectRule;
use App\Models\PayrollRun;
use App\Models\PayrollTerBracket;
use App\Models\PayrollUserTemplate;
use App\Models\Project;
use App\Models\Schedule;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use RuntimeException;

class PayrollService
{
    public const PTKP_STATUSES = [
        'TK/0', 'TK/1', 'K/0',
        'TK/2', 'TK/3', 'K/1', 'K/2', 'K/3',
    ];

    /** @var array<string, array{key: string, name: string, group: string}> */
    public const USER_TEMPLATE_FIELDS = [
        'gaji_pokok' => ['key' => 'gaji_pokok', 'name' => 'Gaji Pokok', 'group' => 'earning'],
        'bpjs_tk_tambah' => ['key' => 'bpjs_tk_tambah', 'name' => 'BPJS TK', 'group' => 'earning'],
        'bpjs_kes_tambah' => ['key' => 'bpjs_kes_tambah', 'name' => 'BPJS KES', 'group' => 'earning'],
        'tukin_budget' => ['key' => 'tukin_budget', 'name' => 'TUKIN Budget', 'group' => 'earning'],
        'bonus_thr' => ['key' => 'bonus_thr', 'name' => 'Bonus/THR', 'group' => 'earning'],
        'bpjs_tk_potongan' => ['key' => 'bpjs_tk_potongan', 'name' => 'BPJS TK Potongan', 'group' => 'deduction'],
        'bpjs_kes_potongan' => ['key' => 'bpjs_kes_potongan', 'name' => 'BPJS KES Potongan', 'group' => 'deduction'],
        'sanksi_sp' => ['key' => 'sanksi_sp', 'name' => 'Sanksi SP', 'group' => 'deduction'],
        'pinjaman_potongan' => ['key' => 'pinjaman_potongan', 'name' => 'Pinjaman', 'group' => 'deduction'],
        'lain_lain_potongan' => ['key' => 'lain_lain_potongan', 'name' => 'Lain-lain', 'group' => 'deduction'],
    ];

    public function __construct(private readonly ScheduleSheetService $scheduleSheetService) {}

    public function getProjectRules(Project $project): array
    {
        $rules = PayrollProjectRule::query()->where('project_id', $project->id)->first();

        return [
            'project_id' => $project->id,
            'backup_rate' => (float) ($rules?->backup_rate ?? 0),
            'potongan_sakit' => (float) ($rules?->potongan_sakit ?? 0),
            'potongan_izin' => (float) ($rules?->potongan_izin ?? 0),
            'potongan_cuti' => (float) ($rules?->potongan_cuti ?? 0),
            'potongan_alpha' => (float) ($rules?->potongan_alpha ?? 0),
            'potongan_soc_a' => (float) ($rules?->potongan_soc_a ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function syncUserTemplate(Project $project, User $user, array $data): array
    {
        foreach (self::USER_TEMPLATE_FIELDS as $field => $meta) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            PayrollUserTemplate::updateOrCreate(
                [
                    'project_id' => $project->id,
                    'user_id' => $user->id,
                    'component_key' => $meta['key'],
                    'component_group' => $meta['group'],
                ],
                [
                    'component_name' => $meta['name'],
                    'amount' => (float) $data[$field],
                    'is_active' => true,
                ]
            );
        }

        if (array_key_exists('ptkp_status', $data) && $data['ptkp_status'] !== null) {
            $ptkpStatus = strtoupper(trim((string) $data['ptkp_status']));
            $terCategory = $this->mapPtkpStatusToTerCategory($ptkpStatus);

            PayrollUserTemplate::updateOrCreate(
                [
                    'project_id' => $project->id,
                    'user_id' => $user->id,
                    'component_key' => 'ptkp_status',
                    'component_group' => 'other',
                ],
                [
                    'component_name' => $ptkpStatus,
                    'amount' => 0,
                    'is_active' => true,
                ]
            );

            PayrollUserTemplate::updateOrCreate(
                [
                    'project_id' => $project->id,
                    'user_id' => $user->id,
                    'component_key' => 'ter_category',
                    'component_group' => 'other',
                ],
                [
                    'component_name' => $terCategory,
                    'amount' => 0,
                    'is_active' => true,
                ]
            );
        }

        return $this->formatUserTemplate($project, $user);
    }

    public function formatUserTemplate(Project $project, User $user): array
    {
        $templates = PayrollUserTemplate::query()
            ->where('project_id', $project->id)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->get()
            ->keyBy('component_key');

        $amount = fn (string $key): float => (float) ($templates->get($key)?->amount ?? 0);

        $ptkpStatus = strtoupper(trim((string) ($templates->get('ptkp_status')?->component_name ?: 'TK/0')));
        $terCategory = strtoupper((string) ($templates->get('ter_category')?->component_name ?: ''));
        if (! in_array($terCategory, ['A', 'B', 'C'], true)) {
            $terCategory = $this->mapPtkpStatusToTerCategory($ptkpStatus);
        }

        return [
            'user_id' => $user->id,
            'nama' => $user->full_name,
            'nik' => $user->nik,
            'gaji_pokok' => $amount('gaji_pokok'),
            'bpjs_tk_tambah' => $amount('bpjs_tk_tambah'),
            'bpjs_kes_tambah' => $amount('bpjs_kes_tambah'),
            'tukin_budget' => $amount('tukin_budget'),
            'bonus_thr' => $amount('bonus_thr'),
            'bpjs_tk_potongan' => $amount('bpjs_tk_potongan'),
            'bpjs_kes_potongan' => $amount('bpjs_kes_potongan'),
            'sanksi_sp' => $amount('sanksi_sp'),
            'pinjaman_potongan' => $amount('pinjaman_potongan'),
            'lain_lain_potongan' => $amount('lain_lain_potongan'),
            'ptkp_status' => $ptkpStatus,
            'ter_category' => $terCategory,
        ];
    }

    public function listUserTemplates(Project $project): array
    {
        $userIds = PayrollUserTemplate::query()
            ->where('project_id', $project->id)
            ->where('is_active', true)
            ->distinct()
            ->pluck('user_id');

        return $userIds->map(function (int $userId) use ($project) {
            $user = User::find($userId);
            if (! $user) {
                return null;
            }

            return $this->formatUserTemplate($project, $user);
        })->filter()->values()->all();
    }

    public function generateOrRefreshDraft(Project $project, string $month, bool $force = false): PayrollRun
    {
        [$periodStart, $periodEnd, $year, $monthInt] = $this->resolvePeriod($month);

        $run = PayrollRun::query()
            ->where('project_id', $project->id)
            ->where('year', $year)
            ->where('month', $monthInt)
            ->first();

        if ($run && $run->isFinalized() && ! $force) {
            return $run->load('payrollDetails.user');
        }

        $policy = PayrollPolicy::query()
            ->byProject($project->id)
            ->active()
            ->effectiveOn($periodEnd->toDateString())
            ->orderByDesc('effective_from')
            ->first();

        return DB::transaction(function () use (
            $project,
            $periodStart,
            $periodEnd,
            $year,
            $monthInt,
            $policy,
            $run,
            $force
        ) {
            $period = $periodStart->format('Y-m');

            $run = $run ?: new PayrollRun;
            $run->fill([
                'project_id' => $project->id,
                'payroll_policy_id' => $policy?->id,
                'year' => $year,
                'month' => $monthInt,
                'period' => $period,
                'pay_period_start' => $periodStart->toDateString(),
                'pay_period_end' => $periodEnd->toDateString(),
                'status' => $run?->status ?? PayrollRun::STATUS_DRAFT,
            ]);

            if ($run->isFinalized() && $force) {
                $run->status = PayrollRun::STATUS_DRAFT;
                $run->finalized_at = null;
                $run->finalized_by = null;
                $run->released_at = null;
            }

            $run->generated_at = now();
            $run->save();

            $run->payrollDetails()->delete();

            $sheetData = $this->scheduleSheetService->generate($project->id, $period);
            $projectRules = PayrollProjectRule::query()->where('project_id', $project->id)->first();

            foreach ($sheetData['rows'] as $row) {
                $user = User::find($row['user']['id'] ?? null);
                if (! $user) {
                    continue;
                }

                $detail = $this->calculateUserPayrollFromScheduleRow($run, $policy, $user, $row, $projectRules);
                $run->payrollDetails()->create($detail);
            }

            PayrollUserTemplate::query()
                ->where('project_id', $project->id)
                ->where('is_active', true)
                ->update(['last_used_period' => $period]);

            $summary = $run->payrollDetails()
                ->selectRaw('COUNT(*) as employees')
                ->selectRaw('COALESCE(SUM(net_salary),0) as total_payroll_amount')
                ->selectRaw('COALESCE(SUM(total_deductions),0) as total_deductions')
                ->selectRaw('COALESCE(SUM(total_additions),0) as total_additions')
                ->first();

            $run->update([
                'total_employees' => $summary->employees ?? 0,
                'total_payroll_amount' => $summary->total_payroll_amount ?? 0,
                'total_deductions' => $summary->total_deductions ?? 0,
                'total_additions' => $summary->total_additions ?? 0,
            ]);

            return $run->fresh(['policy', 'payrollDetails.user']);
        });
    }

    public function release(PayrollRun $run, User $actor, ?string $notes = null): PayrollRun
    {
        if ($run->isFinalized()) {
            return $run;
        }

        if ($run->payrollDetails()->count() === 0) {
            throw new RuntimeException('Payroll detail masih kosong. Generate draft terlebih dahulu.');
        }

        $run->update([
            'status' => PayrollRun::STATUS_FINALIZED,
            'finalized_by' => $actor->id,
            'finalized_at' => now(),
            'released_at' => now(),
            'notes' => $notes ?? $run->notes,
        ]);

        return $run->fresh(['project', 'payrollDetails']);
    }

    public function sheet(Project $project, string $month): array
    {
        $run = PayrollRun::query()
            ->where('project_id', $project->id)
            ->where('period', $month)
            ->with(['payrollDetails.user'])
            ->first();

        if (! $run) {
            $run = $this->generateOrRefreshDraft($project, $month);
            $run->load(['payrollDetails.user']);
        }

        $liveScheduleRows = collect(
            $this->scheduleSheetService->generate($project->id, $month)['rows'] ?? []
        )->keyBy(fn (array $r) => $r['user']['id']);

        $rowNumber = 0;
        $rows = $run->payrollDetails->map(function (PayrollDetail $detail) use ($liveScheduleRows, $project, &$rowNumber) {
            $rowNumber++;

            return $this->buildSheetRowFromDetail(
                $rowNumber,
                $detail,
                $liveScheduleRows->get($detail->user_id),
                $project
            );
        })->values()->all();

        return [
            'meta' => [
                'project_id' => $project->id,
                'month' => $month,
                'sheet_name' => $this->formatExcelSheetName($month),
                'status' => $run->status,
                'generated_at' => $run->generated_at,
                'released_at' => $run->released_at,
            ],
            'project_rules' => $this->getProjectRules($project),
            'rows' => $rows,
            'summary' => [
                'total_employees' => $run->total_employees,
                'total_payroll_amount' => (float) $run->total_payroll_amount,
                'total_deductions' => (float) $run->total_deductions,
                'total_additions' => (float) $run->total_additions,
            ],
        ];
    }

    private function formatExcelSheetName(string $month): string
    {
        $date = Carbon::createFromFormat('Y-m', $month);
        $labels = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
        ];

        return sprintf(
            '%02d. %s %02d',
            $date->month,
            $labels[$date->month],
            $date->year % 100
        );
    }

    /**
     * @param  array<string, mixed>|null  $liveRow
     * @return array<string, mixed>
     */
    private function buildSheetRowFromDetail(int $rowNumber, PayrollDetail $detail, ?array $liveRow, Project $project): array
    {
        $earning = collect($detail->earnings_breakdown ?? [])->keyBy('key');
        $deduction = collect($detail->deductions_breakdown ?? [])->keyBy('key');
        $other = collect($detail->other_breakdown ?? [])->keyBy('key');
        $meta = $detail->calculation_meta ?? [];
        $tax = $meta['tax'] ?? [];
        $absenceCounts = $meta['absence_counts'] ?? [];
        $projectRules = $meta['project_rules'] ?? $this->getProjectRules($project);
        $liveSum = is_array($liveRow) ? ($liveRow['summary'] ?? []) : [];

        $gajiPokok = (float) ($earning->get('i')['amount'] ?? 0);
        $bpjsTk = (float) ($earning->get('j')['amount'] ?? 0);
        $bpjsKes = (float) ($earning->get('k')['amount'] ?? 0);
        $tukinBudget = (float) ($earning->get('l')['amount'] ?? 0);
        $totalSchedule = (int) ($liveSum['SCHEDULE_COUNT'] ?? $earning->get('o')['amount'] ?? 0);
        $hariKerja = (int) ($liveSum['HK'] ?? $earning->get('n')['amount'] ?? 0);
        $tukinPerHari = $totalSchedule > 0 ? ($tukinBudget / $totalSchedule) : (float) ($earning->get('m')['amount'] ?? 0);
        $totalTukin = (float) ($earning->get('p')['amount'] ?? ($tukinPerHari * $hariKerja));
        $backupRate = (float) ($earning->get('u')['amount'] ?? ($projectRules['backup_rate'] ?? 0));
        $hariOt = (int) ($liveSum['OT'] ?? $earning->get('v')['amount'] ?? $detail->overtime_count);
        $totalBackup = (float) ($earning->get('w')['amount'] ?? ($backupRate * $hariOt));
        $bonusThr = (float) ($earning->get('x')['amount'] ?? 0);

        $membershipStatus = is_array($liveRow)
            ? ($liveRow['user']['membership_status'] ?? null)
            : ($meta['membership_status'] ?? null);

        $template = $detail->user
            ? $this->formatUserTemplate($project, $detail->user)
            : null;

        $penambahGajiPokok = $this->calculatePenambahGajiPokok(
            (float) ($template['gaji_pokok'] ?? $gajiPokok),
            $gajiPokok,
            $totalSchedule,
            $hariKerja,
            $membershipStatus,
            $detail
        );

        $storedSubtotalPenambah = (float) ($earning->get('y')['amount'] ?? 0);
        $subtotalPenambah = $penambahGajiPokok !== $gajiPokok
            ? round($penambahGajiPokok + $bpjsTk + $bpjsKes + $totalTukin + $totalBackup + $bonusThr, 2)
            : ($storedSubtotalPenambah ?: round($gajiPokok + $bpjsTk + $bpjsKes + $totalTukin + $totalBackup + $bonusThr, 2));

        $kroscekBudget = (float) ($earning->get('r')['amount'] ?? ($gajiPokok + $bpjsTk + $bpjsKes + $tukinBudget));
        $kroscekRealisasi = (float) ($earning->get('s')['amount'] ?? ($gajiPokok + $bpjsTk + $bpjsKes + $tukinBudget));
        $kroscekMinus = (float) ($earning->get('t')['amount'] ?? 0);

        $sakit = (int) ($liveSum['SAKIT'] ?? $absenceCounts['sakit'] ?? $detail->absence_type_sakit);
        $izin = (int) ($liveSum['IZIN'] ?? $absenceCounts['izin'] ?? $detail->absence_type_izin);
        $cuti = (int) ($liveSum['CUTI'] ?? $absenceCounts['cuti'] ?? $detail->absence_type_cuti);
        $alpha = (int) ($liveSum['ALPA'] ?? $absenceCounts['alpha'] ?? $detail->alpha_count);
        $socA = (int) ($liveSum['SOC_A'] ?? $absenceCounts['soc_a'] ?? 0);

        $potonganSakit = $sakit * (float) ($projectRules['potongan_sakit'] ?? 0);
        $potonganIzin = $izin * (float) ($projectRules['potongan_izin'] ?? 0);
        $potonganCuti = $cuti * (float) ($projectRules['potongan_cuti'] ?? 0);
        $potonganAlpha = $alpha * (float) ($projectRules['potongan_alpha'] ?? 0);
        $potonganSocA = $socA * (float) ($projectRules['potongan_soc_a'] ?? 0);
        $totalKetidakhadiran = (float) ($deduction->get('ae')['amount'] ?? ($potonganSakit + $potonganIzin + $potonganCuti + $potonganAlpha + $potonganSocA));

        $bpjsTkPotongan = (float) ($deduction->get('af')['amount'] ?? 0);
        $bpjsKesPotongan = (float) ($deduction->get('ag')['amount'] ?? 0);
        $sanksiSp = (float) ($deduction->get('ah')['amount'] ?? 0);
        $pinjamanPotongan = (float) ($deduction->get('aj')['amount'] ?? 0);
        $lainLainPotongan = (float) ($deduction->get('al')['amount'] ?? 0);
        $subtotalPengurang = (float) ($deduction->get('am')['amount'] ?? 0);

        $upah = (float) ($other->get('an')['amount'] ?? 0);
        $pph21 = (float) ($tax['pph_be'] ?? $other->get('be')['amount'] ?? 0);
        $upahSetelahPajak = (float) ($other->get('ap')['amount'] ?? 0);
        $upahSetelahThr = (float) ($other->get('aq')['amount'] ?? 0);
        $thp = (float) ($other->get('ar')['amount'] ?? $detail->net_salary);

        return [
            'no' => $rowNumber,
            'user_id' => $detail->user_id,
            'identitas' => [
                'nik' => $detail->user_nik ?? '',
                'nama' => $detail->user?->full_name ?? '',
                'bank' => $detail->user_bank_name ?? '',
                'nomor_rekening' => $detail->user_bank_account ?? '',
                'jabatan' => $detail->user_position ?? '',
                'status_keanggotaan' => $this->mapMembershipToExcelStatus($membershipStatus, $detail),
            ],
            'template' => $template,
            'penambah' => [
                'gaji_pokok' => $penambahGajiPokok,
                'bpjs_tk' => $bpjsTk,
                'bpjs_kes' => $bpjsKes,
                'tukin_budget' => $tukinBudget,
                'tukin_per_hari' => $tukinPerHari,
                'hari_kerja' => $hariKerja,
                'total_schedule' => $totalSchedule,
                'total_tukin' => $totalTukin,
                'kroscek' => [
                    'budget' => $kroscekBudget,
                    'realisasi' => $kroscekRealisasi,
                    'minus' => $kroscekMinus,
                ],
                'backup' => [
                    'nominal' => $backupRate,
                    'hari_ot' => $hariOt,
                    'total' => $totalBackup,
                ],
                'bonus_thr' => $bonusThr,
                'subtotal' => $subtotalPenambah,
            ],
            'pengurang' => [
                'ketidakhadiran' => [
                    'sakit' => ['hari' => $sakit, 'potongan' => $potonganSakit],
                    'izin' => ['hari' => $izin, 'potongan' => $potonganIzin],
                    'cuti' => ['hari' => $cuti, 'potongan' => $potonganCuti],
                    'alpha' => ['hari' => $alpha, 'potongan' => $potonganAlpha],
                    'soc_a' => ['hari' => $socA, 'potongan' => $potonganSocA],
                    'total' => $totalKetidakhadiran,
                ],
                'bpjs_tk' => $bpjsTkPotongan,
                'bpjs_kes' => $bpjsKesPotongan,
                'sanksi_sp' => $sanksiSp,
                'pinjaman' => $pinjamanPotongan,
                'lain_lain' => $lainLainPotongan,
                'subtotal' => $subtotalPengurang,
            ],
            'upah' => [
                'bruto' => $upah,
                'pph21' => $pph21,
                'setelah_pajak' => $upahSetelahPajak,
                'setelah_thr' => $upahSetelahThr,
                'thp' => $thp,
            ],
            'pajak' => [
                'ptkp_status' => (string) ($meta['ptkp_status'] ?? $template['ptkp_status'] ?? 'TK/0'),
                'ter_category' => (string) ($meta['ter_category'] ?? $template['ter_category'] ?? 'A'),
                'gaji_pokok' => $gajiPokok,
                'tukin' => $totalTukin,
                'backup' => $totalBackup,
                'bonus_thr' => $bonusThr,
                'bpjs_jkk' => (float) ($tax['jkk'] ?? 0),
                'bpjs_jkm' => (float) ($tax['jkm'] ?? 0),
                'bpjs_kes_4_persen' => (float) ($tax['bpjs_kes_4_percent'] ?? 0),
                'base_pajak' => (float) ($tax['base_pajak_bc'] ?? 0),
                'tarif_ter' => (float) ($tax['ter_rate'] ?? 0),
                'pph21' => $pph21,
            ],
        ];
    }

    private function mapMembershipToExcelStatus(?string $membershipStatus, PayrollDetail $detail): string
    {
        $status = $membershipStatus ?? ($detail->calculation_meta['membership_status'] ?? null);

        if ($status === Schedule::STATUS_FULL_EXISTING) {
            return 'FULL - EXISTING';
        }
        if ($status === Schedule::STATUS_PRORATE_IN) {
            return 'PRORATE - MASUK';
        }
        if ($status === Schedule::STATUS_PRORATE_OUT) {
            return 'PRORATE - KELUAR';
        }
        if ($detail->schedule_prorate_in_count > 0) {
            return 'PRORATE - MASUK';
        }
        if ($detail->schedule_prorate_out_count > 0) {
            return 'PRORATE - KELUAR';
        }

        return 'FULL - EXISTING';
    }

    private function isProrateMembership(string $membershipStatus): bool
    {
        return in_array($membershipStatus, [Schedule::STATUS_PRORATE_IN, Schedule::STATUS_PRORATE_OUT], true);
    }

    private function shouldProrateGajiPokok(?string $membershipStatus, PayrollDetail $detail): bool
    {
        if ($membershipStatus === Schedule::STATUS_FULL_EXISTING) {
            return false;
        }

        if ($this->isProrateMembership($membershipStatus ?? '')) {
            return true;
        }

        return $detail->schedule_prorate_in_count > 0 || $detail->schedule_prorate_out_count > 0;
    }

    private function calculatePenambahGajiPokok(
        float $templateGajiPokok,
        float $storedGajiPokok,
        int $totalSchedule,
        int $hariKerja,
        ?string $membershipStatus,
        PayrollDetail $detail
    ): float {
        if (! $this->shouldProrateGajiPokok($membershipStatus, $detail) || $totalSchedule <= 0) {
            return $storedGajiPokok;
        }

        return round(($templateGajiPokok / $totalSchedule) * max($hariKerja, 0), 2);
    }

    public function buildSpreadsheet(array $sheetData): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($sheetData['meta']['sheet_name'] ?? 'Payroll');

        $headers = [
            'No', 'NIK', 'Nama', 'Bank', 'Rekening', 'Jabatan', 'Status',
            'Gaji Pokok', 'BPJS TK', 'BPJS KES', 'TUKIN', 'Backup', 'Bonus/THR',
            'Subtotal Penambah', 'Subtotal Pengurang', 'PPh21', 'THP',
        ];
        foreach ($headers as $index => $header) {
            $col = chr(65 + $index);
            $sheet->setCellValue($col.'1', $header);
        }

        $rowIndex = 2;
        foreach ($sheetData['rows'] ?? [] as $row) {
            $sheet->setCellValue("A{$rowIndex}", $row['no'] ?? '');
            $sheet->setCellValue("B{$rowIndex}", $row['identitas']['nik'] ?? '');
            $sheet->setCellValue("C{$rowIndex}", $row['identitas']['nama'] ?? '');
            $sheet->setCellValue("D{$rowIndex}", $row['identitas']['bank'] ?? '');
            $sheet->setCellValue("E{$rowIndex}", $row['identitas']['nomor_rekening'] ?? '');
            $sheet->setCellValue("F{$rowIndex}", $row['identitas']['jabatan'] ?? '');
            $sheet->setCellValue("G{$rowIndex}", $row['identitas']['status_keanggotaan'] ?? '');
            $sheet->setCellValue("H{$rowIndex}", $row['penambah']['gaji_pokok'] ?? 0);
            $sheet->setCellValue("I{$rowIndex}", $row['penambah']['bpjs_tk'] ?? 0);
            $sheet->setCellValue("J{$rowIndex}", $row['penambah']['bpjs_kes'] ?? 0);
            $sheet->setCellValue("K{$rowIndex}", $row['penambah']['total_tukin'] ?? 0);
            $sheet->setCellValue("L{$rowIndex}", $row['penambah']['backup']['total'] ?? 0);
            $sheet->setCellValue("M{$rowIndex}", $row['penambah']['bonus_thr'] ?? 0);
            $sheet->setCellValue("N{$rowIndex}", $row['penambah']['subtotal'] ?? 0);
            $sheet->setCellValue("O{$rowIndex}", $row['pengurang']['subtotal'] ?? 0);
            $sheet->setCellValue("P{$rowIndex}", $row['upah']['pph21'] ?? 0);
            $sheet->setCellValue("Q{$rowIndex}", $row['upah']['thp'] ?? 0);
            $rowIndex++;
        }

        return $spreadsheet;
    }

    /**
     * Metrik absensi (SCHEDULE_COUNT, HK, ALPA, dll.) harus sama dengan ScheduleSheetService.
     *
     * @param  array<string, mixed>  $row
     */
    private function calculateUserPayrollFromScheduleRow(
        PayrollRun $run,
        ?PayrollPolicy $policy,
        User $user,
        array $row,
        ?PayrollProjectRule $projectRules = null
    ): array {
        $policy ??= new PayrollPolicy([
            'daily_rate' => 0,
            'late_deduction_per_minute' => 0,
            'late_minimum_minutes' => 0,
            'absence_deduction_amount' => 0,
            'alpha_deduction_amount' => 0,
            'overtime_rate_amount' => 0,
            'daily_allowance' => 0,
            'perfect_attendance_bonus' => 0,
            'policy_code' => 'NO_POLICY',
        ]);

        $summary = $row['summary'] ?? [];
        $scheduleCount = (int) ($summary['SCHEDULE_COUNT'] ?? 0);
        $hk = (int) ($summary['HK'] ?? 0);
        $overtimeCount = (int) ($summary['OT'] ?? 0);
        $sakit = (int) ($summary['SAKIT'] ?? 0);
        $izin = (int) ($summary['IZIN'] ?? 0);
        $cuti = (int) ($summary['CUTI'] ?? 0);
        $alphaCount = (int) ($summary['ALPA'] ?? 0);
        $socA = (int) ($summary['SOC_A'] ?? 0);

        $membershipStatus = $row['user']['membership_status'] ?? Schedule::STATUS_FULL_EXISTING;

        $lateCount = 0;
        $lateMinutes = 0;
        $workedHours = 0;
        $dailyBreakdown = [];

        foreach ($row['days'] ?? [] as $dateString => $day) {
            $att = $day['attendance'] ?? null;
            if ($att) {
                $lateMinutes += (int) ($att['late_minutes'] ?? 0);
                if ((int) ($att['late_minutes'] ?? 0) > 0) {
                    $lateCount++;
                }
            }
            $dailyBreakdown[] = [
                'date' => $dateString,
                'assignment_code' => $day['scheduled_assignment_code'] ?? null,
                'attendance_status' => is_array($att) ? ($att['status'] ?? null) : null,
                'late_minutes' => is_array($att) ? (int) ($att['late_minutes'] ?? 0) : 0,
                'absence' => $day['absence'] ?? null,
                'overtime' => $day['overtime'] ?? null,
            ];
        }

        $userSchedules = Schedule::query()
            ->where('project_id', $run->project_id)
            ->where('user_id', $user->id)
            ->whereBetween('date', [$run->pay_period_start->toDateString(), $run->pay_period_end->toDateString()])
            ->get();

        $fullExistingCount = $userSchedules->where('membership_status', Schedule::STATUS_FULL_EXISTING)->count();
        $prorateInCount = $userSchedules->where('membership_status', Schedule::STATUS_PRORATE_IN)->count();
        $prorateOutCount = $userSchedules->where('membership_status', Schedule::STATUS_PRORATE_OUT)->count();

        $manualTemplates = PayrollUserTemplate::query()
            ->where('project_id', $run->project_id)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->get();

        $templateRows = $manualTemplates->map(fn (PayrollUserTemplate $template) => [
            'key' => $template->component_key,
            'name' => $template->component_name,
            'group' => $template->component_group,
            'amount' => (float) $template->amount,
        ])->values()->all();

        $manual = function (string $key, float $default = 0) use ($manualTemplates): float {
            return (float) optional($manualTemplates->firstWhere('component_key', $key))->amount ?: $default;
        };

        $ptkpStatus = strtoupper(trim((string) optional($manualTemplates->firstWhere('component_key', 'ptkp_status'))->component_name ?: 'TK/0'));
        $terCategory = strtoupper((string) optional($manualTemplates->firstWhere('component_key', 'ter_category'))->component_name ?: '');
        if (! in_array($terCategory, ['A', 'B', 'C'], true)) {
            $terCategory = $this->mapPtkpStatusToTerCategory($ptkpStatus);
        }

        $overtimeRatePerEvent = (float) ($policy->overtime_rate_amount ?: config('payroll.default_overtime_rate_per_event', 0));

        $baseSalary = $scheduleCount * (float) $policy->daily_rate;
        $defaultGajiPokok = $manual('gaji_pokok', $baseSalary);
        if ($this->isProrateMembership($membershipStatus)) {
            $iGajiPokok = $scheduleCount > 0
                ? round(($defaultGajiPokok / $scheduleCount) * max($hk, 0), 2)
                : 0.0;
        } else {
            $iGajiPokok = $defaultGajiPokok;
        }

        $jBpjsTkTambah = $manual('bpjs_tk_tambah');
        $kBpjsKesTambah = $manual('bpjs_kes_tambah');
        $lTukinBudget = $manual('tukin_budget');
        $mTukinPerHari = $scheduleCount > 0 ? round($lTukinBudget / $scheduleCount, 2) : 0;
        $nHariKerja = $hk;
        $oTotalSchedule = $scheduleCount;
        $pTotalTukin = round($mTukinPerHari * $nHariKerja, 2);
        $uBackupNominal = (float) ($projectRules?->backup_rate ?? 0);
        $vHariOt = $overtimeCount;
        $wTotalBackup = round($uBackupNominal * $vHariOt, 2);
        $xBonusThr = $manual('bonus_thr');
        $rKroscekBudget = $iGajiPokok + $jBpjsTkTambah + $kBpjsKesTambah + $lTukinBudget;
        $sKroscekRealisasi = $iGajiPokok + $jBpjsTkTambah + $kBpjsKesTambah + $lTukinBudget;
        $tKroscekMinus = ($rKroscekBudget - $sKroscekRealisasi) < 0
            ? ($rKroscekBudget - $sKroscekRealisasi)
            : 0;
        $ySubtotalPenambah = $iGajiPokok + $jBpjsTkTambah + $kBpjsKesTambah + $pTotalTukin + $wTotalBackup + $xBonusThr;

        $potonganSakit = (float) ($projectRules?->potongan_sakit ?? 0);
        $potonganIzin = (float) ($projectRules?->potongan_izin ?? 0);
        $potonganCuti = (float) ($projectRules?->potongan_cuti ?? 0);
        $potonganAlpha = (float) ($projectRules?->potongan_alpha ?? 0);
        $potonganSocA = (float) ($projectRules?->potongan_soc_a ?? 0);
        $aeKetidakhadiran = ($sakit * $potonganSakit)
            + ($izin * $potonganIzin)
            + ($cuti * $potonganCuti)
            + ($alphaCount * $potonganAlpha)
            + ($socA * $potonganSocA);
        $afBpjsTkPotongan = $manual('bpjs_tk_potongan');
        $agBpjsKesPotongan = $manual('bpjs_kes_potongan');
        $ahSanksi = $manual('sanksi_sp');
        $ajPinjaman = $manual('pinjaman_potongan');
        $alLain = $manual('lain_lain_potongan');
        $amSubtotalPengurang = $aeKetidakhadiran + $afBpjsTkPotongan + $agBpjsKesPotongan + $ahSanksi + $ajPinjaman + $alLain;
        $anUpah = $ySubtotalPenambah - $amSubtotalPengurang;

        $jkk = $jBpjsTkTambah == 0.0 ? 0.0 : ($iGajiPokok * 0.0024);
        $jkm = $jBpjsTkTambah == 0.0 ? 0.0 : ($iGajiPokok * 0.0030);
        $bpjsKesCompany = $kBpjsKesTambah == 0.0 ? 0.0 : ($iGajiPokok * 0.04);
        $bcBasePajak = $iGajiPokok + $pTotalTukin + $wTotalBackup + $xBonusThr + $jkk + $jkm + $bpjsKesCompany;
        $terRate = $this->resolveTerRate($terCategory, $bcBasePajak);
        $bePph21 = $bcBasePajak * $terRate;

        $apAfterTax = $anUpah - $bePph21;
        $aqAfterThr = $apAfterTax - $xBonusThr;
        $arThp = $aqAfterThr > 0 ? round($aqAfterThr, -2) : 0;

        $deductionLate = $policy->getLatePenalty($lateMinutes);

        return [
            'period' => $run->period,
            'project_id' => $run->project_id,
            'user_id' => $user->id,
            'assignment_id' => $userSchedules->first()?->assignment_id,
            'user_nik' => $user->nik,
            'user_bank_name' => $user->bank_name,
            'user_bank_account' => $user->bank_account,
            'user_position' => $user->role,
            'working_days' => $scheduleCount,
            'schedule_full_existing_count' => $fullExistingCount,
            'schedule_prorate_in_count' => $prorateInCount,
            'schedule_prorate_out_count' => $prorateOutCount,
            'worked_hours' => $workedHours,
            'base_salary' => $baseSalary,
            'attendance_count' => $hk,
            'late_count' => $lateCount,
            'late_total_minutes' => $lateMinutes,
            'absence_count' => $sakit + $izin + $cuti + $alphaCount + $socA,
            'absence_type_sakit' => $sakit,
            'absence_type_izin' => $izin,
            'absence_type_cuti' => $cuti,
            'alpha_count' => $alphaCount,
            'overtime_count' => $overtimeCount,
            'overtime_total_hours' => 0,
            'deduction_late' => $deductionLate,
            'deduction_absence' => $aeKetidakhadiran,
            'deduction_cuti' => 0,
            'deduction_alpha' => 0,
            'deduction_other' => 0,
            'total_deductions' => $amSubtotalPengurang,
            'addition_overtime' => $wTotalBackup,
            'addition_allowance' => $pTotalTukin,
            'addition_bonus' => $xBonusThr,
            'addition_other' => ($jBpjsTkTambah + $kBpjsKesTambah),
            'total_additions' => $ySubtotalPenambah - $iGajiPokok,
            'earnings_breakdown' => [
                ['key' => 'i', 'name' => 'Gaji Pokok (I)', 'amount' => $iGajiPokok],
                ['key' => 'j', 'name' => 'BPJS TK (J)', 'amount' => $jBpjsTkTambah],
                ['key' => 'k', 'name' => 'BPJS KES (K)', 'amount' => $kBpjsKesTambah],
                ['key' => 'l', 'name' => 'TUKIN Budget (L)', 'amount' => $lTukinBudget],
                ['key' => 'm', 'name' => 'TUKIN per Hari (M)', 'amount' => $mTukinPerHari],
                ['key' => 'n', 'name' => 'Hari Kerja (N)', 'amount' => $nHariKerja],
                ['key' => 'o', 'name' => 'Total Schedule (O)', 'amount' => $oTotalSchedule],
                ['key' => 'p', 'name' => 'Total TUKIN (P)', 'amount' => $pTotalTukin],
                ['key' => 'r', 'name' => 'Kroscek Budget (R)', 'amount' => $rKroscekBudget],
                ['key' => 's', 'name' => 'Kroscek Realisasi (S)', 'amount' => $sKroscekRealisasi],
                ['key' => 't', 'name' => 'Kroscek Minus (T)', 'amount' => $tKroscekMinus],
                ['key' => 'u', 'name' => 'Backup Nominal (U)', 'amount' => $uBackupNominal],
                ['key' => 'v', 'name' => 'Hari OT (V)', 'amount' => $vHariOt],
                ['key' => 'w', 'name' => 'Total Backup (W)', 'amount' => $wTotalBackup],
                ['key' => 'x', 'name' => 'Bonus/THR (X)', 'amount' => $xBonusThr],
                ['key' => 'y', 'name' => 'Subtotal Penambah (Y)', 'amount' => $ySubtotalPenambah],
            ],
            'deductions_breakdown' => [
                ['key' => 'ae', 'name' => 'Total Ketidakhadiran (AE)', 'amount' => $aeKetidakhadiran],
                ['key' => 'af', 'name' => 'BPJS TK Potongan (AF)', 'amount' => $afBpjsTkPotongan],
                ['key' => 'ag', 'name' => 'BPJS KES Potongan (AG)', 'amount' => $agBpjsKesPotongan],
                ['key' => 'ah', 'name' => 'SP/Sanksi (AH)', 'amount' => $ahSanksi],
                ['key' => 'aj', 'name' => 'Pinjaman (AJ)', 'amount' => $ajPinjaman],
                ['key' => 'al', 'name' => 'Lain-lain (AL)', 'amount' => $alLain],
                ['key' => 'am', 'name' => 'Subtotal Pengurang (AM)', 'amount' => $amSubtotalPengurang],
            ],
            'other_breakdown' => [
                ['key' => 'an', 'name' => 'Upah (AN)', 'amount' => $anUpah],
                ['key' => 'be', 'name' => 'PPh21 (BE)', 'amount' => $bePph21],
                ['key' => 'ap', 'name' => 'Upah setelah pajak (AP)', 'amount' => $apAfterTax],
                ['key' => 'aq', 'name' => 'Upah setelah THR (AQ)', 'amount' => $aqAfterThr],
                ['key' => 'ar', 'name' => 'THP (AR)', 'amount' => $arThp],
            ],
            'manual_breakdown' => $templateRows,
            'daily_breakdown' => $dailyBreakdown,
            'calculation_meta' => [
                'policy_id' => $policy?->id,
                'policy_code' => $policy->policy_code ?? 'NO_POLICY',
                'membership_status' => $membershipStatus,
                'schedule_source' => 'schedule_sheet',
                'schedule_sheet_summary' => [
                    'SCHEDULE_COUNT' => $scheduleCount,
                    'HK' => $hk,
                    'OT' => $overtimeCount,
                    'SAKIT' => $sakit,
                    'IZIN' => $izin,
                    'CUTI' => $cuti,
                    'ALPA' => $alphaCount,
                    'SOC_A' => $socA,
                ],
                'overtime_rate_per_event' => $overtimeRatePerEvent,
                'ptkp_status' => $ptkpStatus,
                'ter_category' => $terCategory,
                'project_rules' => [
                    'backup_rate' => $uBackupNominal,
                    'potongan_sakit' => $potonganSakit,
                    'potongan_izin' => $potonganIzin,
                    'potongan_cuti' => $potonganCuti,
                    'potongan_alpha' => $potonganAlpha,
                    'potongan_soc_a' => $potonganSocA,
                ],
                'absence_counts' => [
                    'sakit' => $sakit,
                    'izin' => $izin,
                    'cuti' => $cuti,
                    'alpha' => $alphaCount,
                    'soc_a' => $socA,
                ],
                'tax' => [
                    'jkk' => $jkk,
                    'jkm' => $jkm,
                    'bpjs_kes_4_percent' => $bpjsKesCompany,
                    'base_pajak_bc' => $bcBasePajak,
                    'ter_rate' => $terRate,
                    'pph_be' => $bePph21,
                ],
            ],
            'net_salary' => $arThp,
        ];
    }

    private function resolvePeriod(string $month): array
    {
        $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        return [$start, $end, (int) $start->year, (int) $start->month];
    }

    private function resolveTerRate(string $category, float $basePajak): float
    {
        $table = $this->loadTerTable();
        $rows = $table[$category] ?? [];
        foreach ($rows as $row) {
            $min = $row['min'];
            $max = $row['max'];
            if ($basePajak >= $min && ($max === null || $basePajak <= $max)) {
                return $row['rate'];
            }
        }

        return 0.0;
    }

    private function loadTerTable(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $cache = ['A' => [], 'B' => [], 'C' => []];
        $rows = PayrollTerBracket::query()
            ->orderBy('sort_order')
            ->orderBy('min_income')
            ->get(['category', 'min_income', 'max_income', 'rate']);

        foreach ($rows as $row) {
            $category = strtoupper((string) $row->category);
            if (! in_array($category, ['A', 'B', 'C'], true)) {
                continue;
            }
            $cache[$category][] = [
                'min' => (float) $row->min_income,
                'max' => $row->max_income === null ? null : (float) $row->max_income,
                'rate' => (float) $row->rate,
            ];
        }

        return $cache;
    }

    public function mapPtkpStatusToTerCategory(string $ptkpStatus): string
    {
        $status = strtoupper(trim($ptkpStatus));
        if (in_array($status, ['TK/0', 'TK/1', 'K/0'], true)) {
            return 'A';
        }
        if (in_array($status, ['TK/2', 'TK/3', 'K/1', 'K/2'], true)) {
            return 'B';
        }
        if ($status === 'K/3') {
            return 'C';
        }

        return 'A';
    }

    /**
     * @return array<string, mixed>
     */
    public function formatPayrollSlip(PayrollDetail $detail): array
    {
        $detail->loadMissing(['user', 'project.organization', 'payrollRun']);
        $project = $detail->project;
        if (! $project) {
            throw new RuntimeException('Payroll detail tidak memiliki project.');
        }

        $liveRow = collect(
            $this->scheduleSheetService->generate($project->id, $detail->period)['rows'] ?? []
        )->keyBy(fn (array $row) => $row['user']['id'] ?? null)->get($detail->user_id);

        $sheetRow = $this->buildSheetRowFromDetail(1, $detail, is_array($liveRow) ? $liveRow : null, $project);
        $organization = $project->organization;
        $penambah = $sheetRow['penambah'];
        $pengurang = $sheetRow['pengurang'];
        $upah = $sheetRow['upah'];
        $ketidakhadiran = $pengurang['ketidakhadiran'];
        $totalHariKetidakhadiran = (int) ($ketidakhadiran['sakit']['hari'] ?? 0)
            + (int) ($ketidakhadiran['izin']['hari'] ?? 0)
            + (int) ($ketidakhadiran['cuti']['hari'] ?? 0)
            + (int) ($ketidakhadiran['alpha']['hari'] ?? 0)
            + (int) ($ketidakhadiran['soc_a']['hari'] ?? 0);

        return [
            'organization' => [
                'logo' => $this->organizationLogoUrl($organization?->logo),
                'address' => $organization?->address,
                'phone' => $organization?->phone,
                'email' => $organization?->email,
            ],
            'period' => $this->formatPayrollPeriodLabel($detail->payrollRun, $detail->period),
            'employee' => [
                'nama' => $detail->user?->full_name ?? '',
                'jabatan' => $this->formatRoleLabel($detail->user_position ?? $detail->user?->role),
                'lokasi_kerja' => $project->name,
                'bank_name' => $detail->user_bank_name ?? $detail->user?->bank_name,
                'bank_account' => $detail->user_bank_account ?? $detail->user?->bank_account,
            ],
            'pendapatan' => [
                'gaji_pokok' => (float) ($penambah['gaji_pokok'] ?? 0),
                'total_tukin' => (float) ($penambah['total_tukin'] ?? 0),
                'bpjs_kes' => (float) ($penambah['bpjs_kes'] ?? 0),
                'bpjs_tk' => (float) ($penambah['bpjs_tk'] ?? 0),
                'lembur_backup' => [
                    'total' => (float) ($penambah['backup']['total'] ?? 0),
                    'hari_ot' => (int) ($penambah['backup']['hari_ot'] ?? 0),
                ],
                'bonus_thr' => (float) ($penambah['bonus_thr'] ?? 0),
                'total' => (float) ($penambah['subtotal'] ?? 0),
            ],
            'biaya' => [
                'ketidakhadiran' => [
                    'total' => (float) ($ketidakhadiran['total'] ?? 0),
                    'total_hari' => $totalHariKetidakhadiran,
                    'detail' => [
                        'sakit' => (int) ($ketidakhadiran['sakit']['hari'] ?? 0),
                        'izin' => (int) ($ketidakhadiran['izin']['hari'] ?? 0),
                        'cuti' => (int) ($ketidakhadiran['cuti']['hari'] ?? 0),
                        'alpha' => (int) ($ketidakhadiran['alpha']['hari'] ?? 0),
                        'soc_a' => (int) ($ketidakhadiran['soc_a']['hari'] ?? 0),
                    ],
                ],
                'bpjs_kes' => (float) ($pengurang['bpjs_kes'] ?? 0),
                'bpjs_tk' => (float) ($pengurang['bpjs_tk'] ?? 0),
                'sanksi_administrasi' => (float) ($pengurang['sanksi_sp'] ?? 0),
                'pinjaman' => (float) ($pengurang['pinjaman'] ?? 0),
                'lain_lain' => (float) ($pengurang['lain_lain'] ?? 0),
                'total' => (float) ($pengurang['subtotal'] ?? 0),
            ],
            'lain_lain' => [
                'pph21' => (float) ($upah['pph21'] ?? 0),
                'total' => (float) ($upah['setelah_pajak'] ?? 0),
                'pembulatan' => (float) ($upah['thp'] ?? $detail->net_salary),
            ],
            'thp' => (float) ($upah['thp'] ?? $detail->net_salary),
            'month' => $detail->period,
            'project_id' => $detail->project_id,
            'user_id' => $detail->user_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function listProjectSlips(Project $project, string $month): array
    {
        $run = PayrollRun::query()
            ->where('project_id', $project->id)
            ->where('period', $month)
            ->where('status', PayrollRun::STATUS_FINALIZED)
            ->with(['payrollDetails.user'])
            ->first();

        if (! $run) {
            return [
                'meta' => [
                    'project_id' => $project->id,
                    'month' => $month,
                    'period' => $this->formatPayrollPeriodLabel(null, $month),
                    'status' => null,
                    'total_employees' => 0,
                ],
                'slips' => [],
            ];
        }

        $slips = $run->payrollDetails
            ->map(fn (PayrollDetail $detail) => $this->formatPayrollSlip($detail))
            ->values()
            ->all();

        return [
            'meta' => [
                'project_id' => $project->id,
                'month' => $month,
                'period' => $this->formatPayrollPeriodLabel($run),
                'status' => $run->status,
                'released_at' => $run->released_at,
                'total_employees' => count($slips),
            ],
            'slips' => $slips,
        ];
    }

    /**
     * @return list<array{month: string, project_id: int, total_payroll_amount: float}>
     */
    public function projectPayrollHistory(Project $project, int $year): array
    {
        return PayrollRun::query()
            ->where('project_id', $project->id)
            ->where('year', $year)
            ->where('status', PayrollRun::STATUS_FINALIZED)
            ->orderBy('month')
            ->get()
            ->map(fn (PayrollRun $run) => [
                'month' => $run->period,
                'project_id' => (int) $run->project_id,
                'total_payroll_amount' => (float) $run->total_payroll_amount,
            ])
            ->values()
            ->all();
    }

    private function formatPayrollPeriodLabel(?PayrollRun $run, ?string $month = null): string
    {
        if ($run?->pay_period_start && $run?->pay_period_end) {
            $start = Carbon::parse($run->pay_period_start);
            $end = Carbon::parse($run->pay_period_end);
        } elseif ($month) {
            $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
            $end = $start->copy()->endOfMonth();
        } else {
            return '';
        }

        $labels = [
            1 => 'JANUARI', 2 => 'FEBRUARI', 3 => 'MARET', 4 => 'APRIL',
            5 => 'MEI', 6 => 'JUNI', 7 => 'JULI', 8 => 'AGUSTUS',
            9 => 'SEPTEMBER', 10 => 'OKTOBER', 11 => 'NOVEMBER', 12 => 'DESEMBER',
        ];

        return sprintf(
            '%d S.D %d %s %d',
            $start->day,
            $end->day,
            $labels[$end->month] ?? strtoupper($end->format('F')),
            $end->year
        );
    }

    private function formatRoleLabel(?string $role): string
    {
        return match ($role) {
            'anggota' => 'Anggota',
            'komandan_regu' => 'Komandan Regu',
            'admin_project' => 'Admin Project',
            'ho' => 'Head Office',
            'dev' => 'Developer',
            default => (string) ($role ?? ''),
        };
    }

    private function organizationLogoUrl(?string $path): ?string
    {
        if (! $path || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        return Storage::disk('public')->url($path);
    }

    private function toNumber(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }
        $raw = preg_replace('/[^0-9,\.-]/', '', (string) $value);
        if ($raw === null || $raw === '') {
            return null;
        }
        $raw = str_replace('.', '', $raw);
        $raw = str_replace(',', '.', $raw);

        return is_numeric($raw) ? (float) $raw : null;
    }

    private function toPercent(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            $n = (float) $value;

            return $n > 1 ? $n / 100 : $n;
        }
        $raw = str_replace('%', '', (string) $value);
        $n = $this->toNumber($raw);
        if ($n === null) {
            return null;
        }

        return $n > 1 ? $n / 100 : $n;
    }
}
