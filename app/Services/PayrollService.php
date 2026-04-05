<?php

namespace App\Services;

use App\Models\PayrollDetail;
use App\Models\PayrollPolicy;
use App\Models\PayrollRun;
use App\Models\PayrollUserTemplate;
use App\Models\Project;
use App\Models\Schedule;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use RuntimeException;

class PayrollService
{
    public function __construct(private readonly ScheduleSheetService $scheduleSheetService) {}

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

            foreach ($sheetData['rows'] as $row) {
                $user = User::find($row['user']['id'] ?? null);
                if (! $user) {
                    continue;
                }

                $detail = $this->calculateUserPayrollFromScheduleRow($run, $policy, $user, $row);
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

        return [
            'meta' => [
                'project_id' => $project->id,
                'month' => $month,
                'status' => $run->status,
                'generated_at' => $run->generated_at,
                'released_at' => $run->released_at,
            ],
            'columns' => [
                'nik',
                'nama',
                'bank',
                'nomor_rekening',
                'jabatan',
                'status_membership',
                'i_gaji_pokok',
                'j_bpjs_tk_tambah',
                'k_bpjs_kes_tambah',
                'l_tukin_budget',
                'm_tukin_per_hari',
                'n_hari_kerja',
                'o_total_schedule',
                'p_total_tukin',
                'u_backup_nominal',
                'v_hari_ot',
                'w_total_backup',
                'x_bonus_thr',
                'y_subtotal_penambah',
                's_sakit',
                'i_izin',
                'c_cuti',
                'tk_a_alpha',
                'soc_a',
                'ae_total_ketidakhadiran',
                'af_bpjs_tk_potongan',
                'ag_bpjs_kes_potongan',
                'ah_sanksi',
                'aj_pinjaman',
                'al_lain_lain',
                'am_subtotal_pengurang',
                'an_upah',
                'be_pph21',
                'ap_upah_setelah_pajak',
                'aq_upah_setelah_thr',
                'ar_thp',
            ],
            'summary' => [
                'total_employees' => $run->total_employees,
                'total_payroll_amount' => (float) $run->total_payroll_amount,
                'total_deductions' => (float) $run->total_deductions,
                'total_additions' => (float) $run->total_additions,
            ],
            'rows' => $run->payrollDetails->map(function (PayrollDetail $detail) use ($liveScheduleRows) {
                $earning = collect($detail->earnings_breakdown ?? [])->keyBy('key');
                $deduction = collect($detail->deductions_breakdown ?? [])->keyBy('key');
                $other = collect($detail->other_breakdown ?? [])->keyBy('key');
                $meta = $detail->calculation_meta ?? [];
                $absenceCounts = $meta['absence_counts'] ?? [];

                $liveRow = $liveScheduleRows->get($detail->user_id);
                $liveSum = is_array($liveRow) ? ($liveRow['summary'] ?? []) : [];
                $hk = (int) ($liveSum['HK'] ?? ($earning->get('n')['amount'] ?? 0));
                $scheduleCount = (int) ($liveSum['SCHEDULE_COUNT'] ?? ($earning->get('o')['amount'] ?? 0));
                $otDays = (int) ($liveSum['OT'] ?? $detail->overtime_count);
                $sakit = (int) ($liveSum['SAKIT'] ?? $absenceCounts['sakit'] ?? $detail->absence_type_sakit);
                $izin = (int) ($liveSum['IZIN'] ?? $absenceCounts['izin'] ?? $detail->absence_type_izin);
                $cuti = (int) ($liveSum['CUTI'] ?? $absenceCounts['cuti'] ?? $detail->absence_type_cuti);
                $alpa = (int) ($liveSum['ALPA'] ?? $absenceCounts['alpha'] ?? $detail->alpha_count);

                $i = (float) ($earning->get('i')['amount'] ?? 0);
                $j = (float) ($earning->get('j')['amount'] ?? 0);
                $k = (float) ($earning->get('k')['amount'] ?? 0);
                $lBudget = (float) ($earning->get('l')['amount'] ?? 0);
                $mTukin = $scheduleCount > 0 ? round($lBudget / $scheduleCount, 2) : 0;
                $pTukin = round($mTukin * $hk, 2);
                $u = (float) ($earning->get('u')['amount'] ?? 0);
                $wBackup = round($u * $otDays, 2);
                $x = (float) ($earning->get('x')['amount'] ?? 0);
                $yPenambah = $i + $j + $k + $pTukin + $wBackup + $x;

                $membershipLabel = $this->mapScheduleMembershipToLabel(
                    is_array($liveRow) ? ($liveRow['user']['membership_status'] ?? null) : null,
                    $detail
                );

                return [
                    'user' => [
                        'id' => $detail->user_id,
                        'name' => $detail->user->full_name,
                        'nik' => $detail->user_nik,
                        'bank' => $detail->user_bank_name,
                        'bank_account' => $detail->user_bank_account,
                        'position' => $detail->user_position,
                    ],
                    'sheet' => [
                        'nik' => $detail->user_nik ?? '',
                        'nama' => $detail->user?->full_name ?? '',
                        'bank' => $detail->user_bank_name ?? '',
                        'nomor_rekening' => $detail->user_bank_account ?? '',
                        'jabatan' => $detail->user_position ?? '',
                        'status_membership' => $membershipLabel,
                        'i_gaji_pokok' => $i,
                        'j_bpjs_tk_tambah' => $j,
                        'k_bpjs_kes_tambah' => $k,
                        'l_tukin_budget' => $lBudget,
                        'm_tukin_per_hari' => $mTukin,
                        'n_hari_kerja' => $hk,
                        'o_total_schedule' => $scheduleCount,
                        'p_total_tukin' => $pTukin,
                        'u_backup_nominal' => $u,
                        'v_hari_ot' => $otDays,
                        'w_total_backup' => $wBackup,
                        'x_bonus_thr' => $x,
                        'y_subtotal_penambah' => $yPenambah,
                        's_sakit' => $sakit,
                        'i_izin' => $izin,
                        'c_cuti' => $cuti,
                        'tk_a_alpha' => $alpa,
                        'soc_a' => (int) ($absenceCounts['soc_a'] ?? 0),
                        'ae_total_ketidakhadiran' => (float) ($deduction->get('ae')['amount'] ?? 0),
                        'af_bpjs_tk_potongan' => (float) ($deduction->get('af')['amount'] ?? 0),
                        'ag_bpjs_kes_potongan' => (float) ($deduction->get('ag')['amount'] ?? 0),
                        'ah_sanksi' => (float) ($deduction->get('ah')['amount'] ?? 0),
                        'aj_pinjaman' => (float) ($deduction->get('aj')['amount'] ?? 0),
                        'al_lain_lain' => (float) ($deduction->get('al')['amount'] ?? 0),
                        'am_subtotal_pengurang' => (float) ($deduction->get('am')['amount'] ?? 0),
                        'an_upah' => (float) ($other->get('an')['amount'] ?? 0),
                        'be_pph21' => (float) ($other->get('be')['amount'] ?? 0),
                        'ap_upah_setelah_pajak' => (float) ($other->get('ap')['amount'] ?? 0),
                        'aq_upah_setelah_thr' => (float) ($other->get('aq')['amount'] ?? 0),
                        'ar_thp' => (float) ($other->get('ar')['amount'] ?? $detail->net_salary),
                    ],
                    'totals' => [
                        'base_salary' => (float) $detail->base_salary,
                        'total_additions' => (float) $detail->total_additions,
                        'total_deductions' => (float) $detail->total_deductions,
                        'net_salary' => (float) $detail->net_salary,
                    ],
                    'metrics' => [
                        'schedule_count' => $scheduleCount,
                        'hk' => $hk,
                        'working_days' => $scheduleCount,
                        'attendance_count' => $hk,
                        'overtime_count' => $otDays,
                        'late_minutes' => $detail->late_total_minutes,
                        'alpha_count' => $alpa,
                        'schedule_sheet_summary' => ! empty($liveSum) ? $liveSum : ($meta['schedule_sheet_summary'] ?? null),
                    ],
                ];
            })->values()->all(),
        ];
    }

    private function mapScheduleMembershipToLabel(?string $membershipStatus, PayrollDetail $detail): string
    {
        if ($membershipStatus === Schedule::STATUS_FULL_EXISTING) {
            return 'FULL';
        }
        if ($membershipStatus === Schedule::STATUS_PRORATE_IN) {
            return 'PRORATE MASUK';
        }
        if ($membershipStatus === Schedule::STATUS_PRORATE_OUT) {
            return 'PRORATE KELUAR';
        }

        return $this->resolveMembershipLabel($detail);
    }

    private function resolveMembershipLabel(PayrollDetail $detail): string
    {
        $status = $detail->calculation_meta['membership_status'] ?? null;
        if ($status === Schedule::STATUS_PRORATE_IN) {
            return 'PRORATE MASUK';
        }
        if ($status === Schedule::STATUS_PRORATE_OUT) {
            return 'PRORATE KELUAR';
        }
        if ($status === Schedule::STATUS_FULL_EXISTING) {
            return 'FULL';
        }
        if ($detail->schedule_prorate_in_count > 0) {
            return 'PRORATE MASUK';
        }
        if ($detail->schedule_prorate_out_count > 0) {
            return 'PRORATE KELUAR';
        }

        return 'FULL';
    }

    private function isProrateMembership(string $membershipStatus): bool
    {
        return in_array($membershipStatus, [Schedule::STATUS_PRORATE_IN, Schedule::STATUS_PRORATE_OUT], true);
    }

    public function buildSpreadsheet(array $sheetData): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Payroll');

        $headers = [
            'Nama',
            'NIK',
            'Bank',
            'No Rekening',
            'Jabatan',
            'Gaji Pokok',
            'Pendapatan',
            'Biaya',
            'Take Home Pay',
        ];

        foreach ($headers as $index => $header) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($index + 1).'1', $header);
        }

        $rowIndex = 2;
        foreach ($sheetData['rows'] as $row) {
            $sheet->setCellValue("A{$rowIndex}", $row['user']['name']);
            $sheet->setCellValue("B{$rowIndex}", $row['user']['nik']);
            $sheet->setCellValue("C{$rowIndex}", $row['user']['bank']);
            $sheet->setCellValue("D{$rowIndex}", $row['user']['bank_account']);
            $sheet->setCellValue("E{$rowIndex}", $row['user']['position']);
            $sheet->setCellValue("F{$rowIndex}", $row['totals']['base_salary']);
            $sheet->setCellValue("G{$rowIndex}", $row['totals']['total_additions']);
            $sheet->setCellValue("H{$rowIndex}", $row['totals']['total_deductions']);
            $sheet->setCellValue("I{$rowIndex}", $row['totals']['net_salary']);
            $rowIndex++;
        }

        return $spreadsheet;
    }

    /**
     * Metrik absensi (SCHEDULE_COUNT, HK, ALPA, dll.) harus sama dengan ScheduleSheetService.
     *
     * @param  array<string, mixed>  $row
     */
    private function calculateUserPayrollFromScheduleRow(PayrollRun $run, ?PayrollPolicy $policy, User $user, array $row): array
    {
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
        $socA = 0;

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

        $ptkpStatus = (string) optional($manualTemplates->firstWhere('component_key', 'ptkp_status'))->component_name ?: 'TK/0';
        $terCategory = strtoupper((string) optional($manualTemplates->firstWhere('component_key', 'ter_category'))->component_name ?: 'A');

        $overtimeRatePerEvent = (float) ($policy->overtime_rate_amount ?: config('payroll.default_overtime_rate_per_event', 0));

        $baseSalary = $scheduleCount * (float) $policy->daily_rate;
        $defaultGajiPokok = $manual('gaji_pokok', $baseSalary);
        if ($this->isProrateMembership($membershipStatus)) {
            $iGajiPokok = round(($defaultGajiPokok / 30) * max($hk, 0), 2);
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
        $uBackupNominal = $manual('backup_rate');
        $vHariOt = $overtimeCount;
        $wTotalBackup = round($uBackupNominal * $vHariOt, 2);
        $xBonusThr = $manual('bonus_thr');
        $ySubtotalPenambah = $iGajiPokok + $jBpjsTkTambah + $kBpjsKesTambah + $pTotalTukin + $wTotalBackup + $xBonusThr;

        $nomS = $manual('potongan_sakit');
        $nomI = $manual('potongan_izin');
        $nomC = $manual('potongan_cuti');
        $nomA = $manual('potongan_alpha');
        $nomSocA = $manual('potongan_soc_a');
        $aeKetidakhadiran = ($sakit * $nomS) + ($izin * $nomI) + ($cuti * $nomC) + ($alphaCount * $nomA) + ($socA * $nomSocA);
        $afBpjsTkPotongan = $manual('bpjs_tk_potongan');
        $agBpjsKesPotongan = $manual('bpjs_kes_potongan');
        $ahSanksi = $manual('sanksi_sp');
        $ajPinjaman = $manual('pinjaman_potongan');
        $alLain = $manual('lain_lain_potongan');
        $amSubtotalPengurang = $aeKetidakhadiran + $afBpjsTkPotongan + $agBpjsKesPotongan + $ahSanksi + $ajPinjaman + $alLain;
        $anUpah = $ySubtotalPenambah - $amSubtotalPengurang;

        $jkk = round(0.0024 * $iGajiPokok, 2);
        $jkm = round(0.0030 * $iGajiPokok, 2);
        $bpjsKesCompany = round(0.04 * $iGajiPokok, 2);
        $bcBasePajak = $iGajiPokok + $pTotalTukin + $wTotalBackup + $xBonusThr + $jkk + $jkm + $bpjsKesCompany;
        $terRate = $this->resolveTerRate($terCategory, $bcBasePajak);
        $bePph21 = round($bcBasePajak * $terRate, 2);

        $apAfterTax = $anUpah - $bePph21;
        $aqAfterThr = $apAfterTax - $xBonusThr;
        $arThp = round($aqAfterThr, -2);

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
            'absence_count' => $sakit + $izin + $cuti + $alphaCount,
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
                ],
                'overtime_rate_per_event' => $overtimeRatePerEvent,
                'ptkp_status' => $ptkpStatus,
                'ter_category' => $terCategory,
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
        $files = glob(base_path('Total Salary Kerja *.xlsx')) ?: [];
        if (empty($files)) {
            return $cache;
        }

        try {
            $spreadsheet = IOFactory::load($files[0]);
            $sheet = $spreadsheet->getSheetByName('TER');
            if (! $sheet) {
                return $cache;
            }

            $highest = $sheet->getHighestRow();
            for ($row = 1; $row <= $highest; $row++) {
                $category = strtoupper(trim((string) $sheet->getCell([1, $row])->getValue()));
                $min = $this->toNumber($sheet->getCell([2, $row])->getValue());
                $maxRaw = $sheet->getCell([3, $row])->getValue();
                $rate = $this->toPercent($sheet->getCell([4, $row])->getValue());
                if (! in_array($category, ['A', 'B', 'C'], true) || $min === null || $rate === null) {
                    continue;
                }
                $max = $this->toNumber($maxRaw);
                $cache[$category][] = ['min' => $min, 'max' => $max, 'rate' => $rate];
            }
        } catch (\Throwable) {
            return $cache;
        }

        return $cache;
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
