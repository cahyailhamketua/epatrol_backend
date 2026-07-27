<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Harian Pengamanan</title>
    <style>
        body {
            font-family: Arial, Helvetica, sans-serif;
            color: #1f2937;
            background: #ffffff;
            margin: 0;
            padding: 24px;
            font-size: 12px;
        }

        .page {
            max-width: 1000px;
            margin: 0 auto;
            background: #fff;
        }

        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            border-bottom: 3px solid #d1d5db;
            padding-bottom: 12px;
            margin-bottom: 18px;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .logo {
            width: 64px;
            height: 64px;
            object-fit: contain;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            background: #fff;
        }

        h1 {
            margin: 0;
            font-size: 24px;
            color: #111827;
        }

        .subtitle {
            margin-top: 4px;
            color: #6b7280;
            font-size: 12px;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 16px;
        }

        .panel {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 12px;
            background: #fafafa;
        }

        .panel-title {
            color: #374151;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 10px;
        }

        .label {
            font-weight: bold;
            color: #4b5563;
            display: inline-block;
            min-width: 120px;
        }

        .value {
            color: #111827;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
            font-size: 11px;
        }

        th {
            background: #111827;
            color: #ffffff;
            text-align: left;
            padding: 7px 8px;
            font-size: 10px;
            text-transform: uppercase;
        }

        td {
            border-bottom: 1px solid #e5e7eb;
            padding: 7px 8px;
            vertical-align: top;
        }

        tbody tr:nth-child(even) {
            background: #f9fafb;
        }

        .section {
            margin-bottom: 18px;
        }

        .muted {
            color: #6b7280;
        }

        .no-data {
            color: #1f2937;
            font-size: 11px;
            padding-top: 2px; /* Memberikan sedikit napas agar tidak terlalu menempel dengan judul */
        }

        .chips {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .chip {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 999px;
            font-size: 10px;
            background: #e5e7eb;
            color: #111827;
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="header">
            <div style="text-align:center; margin-bottom:20px;">
                @if ($logoUrl)
                    <img
                        src="{{ $logoUrl }}"
                        style="width:80px;height:auto;margin-bottom:10px;"
                    >
                @endif

                <h2 style="margin:0;text-decoration: underline;">
                    LAPORAN HARIAN PROJECT
                </h2>

                <div style="font-size:16px;font-weight:bold;">
                    PENGECEKAN KINERJA PENGAMANAN
                </div>

                <div style="margin-top:8px;font-size:14px;">
                    {{ $project->name }}
                </div>
            </div>

            <hr>

            <table style="margin-bottom: 6px; width: 100%; border-collapse: collapse;">
                <tr>
                    <td width="120" style="padding: 4px 0;">Hari / Tanggal</td>
                    <td width="10" style="padding: 4px 0;">:</td>
                    <td style="padding: 4px 0;">
                        {{ \Carbon\Carbon::parse($report->report_date)->translatedFormat('l, d F Y') }}
                    </td>
                </tr>

                <tr>
                    <td style="padding: 4px 0;">Dibuat Oleh</td>
                    <td style="padding: 4px 0;">:</td>
                    <td style="padding: 4px 0;">
                        {{ $report->creator?->full_name ?? '-' }}
                    </td>
                </tr>
            </table>
        </div>

        <div class="section">
            <h3>I. JUMLAH PERSONIL</h3>

            <table>
                <thead>
                    <tr>
                        <th>Keterangan</th>
                        <th width="150">Jumlah</th>
                    </tr>
                </thead>

                <tbody>
                    <tr>
                        <td>Personil seharusnya bertugas</td>
                        <td>{{ $report->total_personnel }}</td>
                    </tr>

                    <tr>
                        <td>Personil hadir</td>
                        <td>{{ $report->present_personnel }}</td>
                    </tr>

                    <tr>
                        <td>Status kehadiran</td>
                        <td>
                            {{ $report->present_personnel >= $report->total_personnel ? 'Lengkap' : 'Tidak Lengkap' }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        
        <div class="section">
            <h3>II. KETERLAMBATAN PERSONIL</h3>
            @if ($latePersonnel->count())
                <table>
                    <thead>
                        <tr>
                            <th width="40">#</th>
                            <th>Nama</th>
                            <th>Status</th>
                            <th>Terlambat (Menit)</th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($latePersonnel as $index => $person)
                            <tr>
                                <td>{{ $index + 1 }}</td>
                                <td>{{ $person['user_name'] }}</td>
                                <td>{{ $person['attendance_status'] }}</td>
                                <td>{{ $person['late_minutes'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <div class="no-data">Tidak Ada</div>
            @endif
        </div>

        <div class="section">
            <h3>III. ABSENSI & PERSONIL BACK-UP</h3>
            @if(!empty($report->absent_personnel) && count($report->absent_personnel) > 0)
            <table>
                <thead>
                    <tr>
                        <th width="40">#</th>
                        <th>Nama</th>
                        <th>Alasan</th>
                        <th>Back Up</th>
                        <th>Asal</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($report->absent_personnel as $index => $row)
                        <tr>
                            <td>{{ $index + 1 }}</td>
                            <td>{{ $row['employee_name'] ?? '-' }}</td>
                            <td>{{ $row['reason'] ?? '-' }}</td>
                            <td>{{ $row['backup_name'] ?? '-' }}</td>
                            <td>{{ $row['origin'] ?? '-' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            @else
                <div class="no-data">Tidak Ada</div>
            @endif
        </div>

        <div class="section">
            <h3>IV. PENGECEKAN FISIK PERSONIL</h3>
            @if($report->personnelConditions && $report->personnelConditions->count() > 0)
                <table>
                    <thead>
                        <tr>
                            <th width="40">#</th>
                            <th>Nama</th>
                            <th>Jabatan / Pos</th>
                            <th>Kondisi Fisik</th>
                            <th>Keterangan</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($report->personnelConditions as $index => $condition)
                            <tr>
                                <td>{{ $index + 1 }}</td>
                                <td>{{ $condition->user?->full_name ?? '-' }}</td>
                                <td>
                                    {{
                                        ucwords(
                                            str_replace(
                                                '_',
                                                ' ',
                                                $condition->position ?? '-'
                                            )
                                        )
                                    }}
                                </td>
                                <td>
                                    {{
                                        ucwords(
                                            str_replace(
                                                '_',
                                                ' ',
                                                $condition->physical_condition ?? '-'
                                            )
                                        )
                                    }}
                                </td>
                                <td>{{ $condition->remarks ?? '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <div class="no-data">Tidak Ada</div>
            @endif
        </div>

        <div class="section">
            <h3>V. PENGECEKAN SERAGAM & KELENGKAPAN PERORANGAN</h3>
            @php
                $uniformComponents = \App\Models\UniformComponent::where(
                    'project_id',
                    $project->id
                )
                ->orderBy('sort_order')
                ->get();
            @endphp

            @if($report->uniformPersonnels && $report->uniformPersonnels->count() > 0)
                <table>
                    <thead>
                        <tr>
                            <th width="40">#</th>
                            <th>Nama</th>
                            <th>Seragam</th>

                        @foreach($uniformComponents as $component)
                            <th>{{ $component->name }}</th>
                        @endforeach

                        <th>Ket.</th>
                    </tr>
                </thead>

                <tbody>
                    @foreach($report->uniformPersonnels as $index => $uniformPerson)

                        @php
                            $checkMap = $uniformPerson->checks
                                ->keyBy('uniform_component_id');
                        @endphp

                        <tr>
                            <td>{{ $index + 1 }}</td>

                            <td>
                                {{ $uniformPerson->user?->full_name ?? '-' }}
                            </td>

                            <td>
                                {{
                                    ucwords(
                                        str_replace(
                                            '_',
                                            ' ',
                                            $uniformPerson->overall_status ?? '-'
                                        )
                                    )
                                }}
                            </td>

                            @foreach($uniformComponents as $component)

                                @php
                                    $check = $checkMap->get($component->id);
                                @endphp

                                <td>
                                    @if($check)
                                        {{ $check->status == 'ada'
                                            ? 'Ada'
                                            : 'Tidak Ada' }}
                                    @else
                                        -
                                    @endif
                                </td>

                            @endforeach

                            <td>
                                {{ $uniformPerson->notes ?? '-' }}
                            </td>
                        </tr>

                    @endforeach
                </tbody>
            </table>
            @else
                <div class="no-data">Tidak Ada</div>
            @endif
        </div>

        <div class="section">
            <h3>VI. PENGECEKAN PERALATAN & PERLENGKAPAN KERJA</h3>
            @if($report->equipmentChecks && $report->equipmentChecks->count() > 0)
            <table>
                <thead>
                    <tr>
                        <th width="40">#</th>
                        <th>Nama Peralatan</th>
                        <th>Std</th>
                        <th>Tersedia</th>
                        <th>Kondisi</th>
                        <th>Keterangan</th>
                    </tr>
                </thead>

                <tbody>

                    @foreach($report->equipmentChecks as $index => $check)

                        <tr>
                            <td>{{ $index + 1 }}</td>

                            <td>
                                {{ $check->equipmentComponent?->name ?? '-' }}
                            </td>

                            <td>
                                {{ $check->equipmentComponent?->standard_quantity ?? 0 }}
                            </td>

                            <td>
                                {{ $check->available_quantity }}
                            </td>

                            <td>
                                {{ ucfirst($check->condition) }}
                            </td>

                            <td>
                                {{ $check->remarks ?? '-' }}
                            </td>
                        </tr>

                    @endforeach

                </tbody>
            </table>
            @else
                <div class="no-data">Tidak Ada</div>
            @endif
        </div>

        <div class="section">
            <h3>VII. DINAMIKA / HAL MENONJOL</h3>
            @if (!empty($report->incidents) && count($report->incidents) > 0)
                    <table>
                        <thead>
                            <tr>
                                <th width="40">#</th>
                                <th>Dinamika</th>
                                <th>Deskripsi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($report->incidents as $index => $item)
                                <tr>
                                    <td>{{ $index + 1 }}</td>
                                    <td>{{ $item['component'] ?? '-' }}</td>
                                    <td>{{ $item['description'] ?? '-' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
            @else
                <div class="no-data">Tidak Ada</div>
            @endif
        </div>

        <div class="section">
            <h3>Berita Acara</h3>
            @if(!empty($report->berita_acara) && count($report->berita_acara) > 0)
                <table>
                    <thead>
                        <tr>
                            <th width="40">#</th>
                            <th>Berita Acara</th>
                            <th>Deskripsi</th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach($report->berita_acara as $index => $item)
                            <tr>
                                <td>{{ $index + 1 }}</td>
                                <td>{{ $item['berita_acara'] ?? '-' }}</td>
                                <td>{{ $item['description'] ?? '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <div class="no-data">Tidak Ada</div>
            @endif
        </div>

        <div class="section">
            <h3>VIII. INFORMASI UNTUK REGU BERIKUTNYA</h3>
            <div class="no-data">
                {{ $report->general_information ?? 'Tidak Ada' }}
            </div>
        </div>

        <div class="section">
            <h3>IX. HAL YANG PERLU DIESKALASI LEBIH LANJUT</h3>
            <div class="no-data">
                {{ $report->further_escalation ?? 'Tidak Ada' }}
            </div>
        </div>
        
        <hr style="margin-top:40px;">

        <div style="
            text-align:right;
            margin-top:20px;
            margin-bottom:60px;
        ">
            {{ $project->location_city ?? 'Jakarta' }},
            {{ \Carbon\Carbon::parse($report->report_date)->translatedFormat('d F Y') }}
        </div>

        <table style="width:100%; border:none; border-collapse:collapse;">
            <tr>

                <td
                    width="50%"
                    style="
                        text-align:center;
                        border:none;
                        padding-right:80px;
                        vertical-align:top;
                    "
                >

                    <strong>YANG MEMBUAT</strong>

                    <div style="height:100px;"></div>

                    <strong>
                        {{ strtoupper($report->creator?->full_name ?? '-') }}
                    </strong>

                    <br>

                    {{ strtoupper(str_replace('_', ' ', $report->creator?->role ?? '-')) }}

                </td>

                <td
                    width="50%"
                    style="
                        text-align:center;
                        border:none;
                        padding-left:80px;
                        vertical-align:top;
                    "
                >

                    <strong>MENGETAHUI</strong>

                    <div style="height:100px;"></div>

                    <strong>
                        {{ strtoupper($report->bos_name ?? '-') }}
                    </strong>

                    <br>

                    {{ strtoupper($report->bos_position ?? '-') }}

                </td>

            </tr>
        </table>
    </div>
</body>
</html>
