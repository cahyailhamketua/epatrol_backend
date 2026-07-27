<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: Arial, sans-serif;
            color: #333;
            font-size: 10px;
            line-height: 1.4;
        }
        .header {
            border-bottom: 2px solid #2c3e50;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        .header-title {
            font-size: 18px;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 5px;
        }
        .header-info {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 15px;
            font-size: 9px;
        }
        .info-item {
            background: #ecf0f1;
            padding: 8px;
            border-radius: 4px;
        }
        .info-label {
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 3px;
        }
        .info-value {
            color: #555;
        }
        
        .post-section {
            margin-bottom: 25px;
            page-break-inside: avoid;
        }
        .post-header {
            background: #34495e;
            color: white;
            padding: 10px;
            margin-bottom: 10px;
            border-radius: 4px;
            font-weight: bold;
            font-size: 11px;
        }
        
        .point-group {
            margin-bottom: 15px;
            border-left: 3px solid #3498db;
            padding-left: 10px;
            page-break-inside: avoid;
        }
        .point-header {
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 8px;
            font-size: 10px;
        }
        .point-meta {
            font-size: 8px;
            color: #7f8c8d;
            margin-bottom: 8px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }
        
        .scan-entry {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            padding: 8px;
            margin-bottom: 8px;
            border-radius: 3px;
        }
        .scan-timestamp {
            font-weight: bold;
            color: #27ae60;
            font-size: 9px;
            margin-bottom: 4px;
        }
        .scan-user {
            font-size: 8px;
            color: #555;
            margin-bottom: 3px;
        }
        .scan-note {
            font-size: 8px;
            color: #7f8c8d;
            font-style: italic;
            margin-bottom: 6px;
        }
        
        .photos-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin-top: 6px;
            page-break-inside: avoid;
        }
        .photo-item {
            text-align: center;
            page-break-inside: avoid;
        }
        .photo-item img {
            max-width: 100%;
            height: auto;
            max-height: 120px;
            border: 1px solid #bdc3c7;
            border-radius: 3px;
        }
        .photo-label {
            font-size: 7px;
            color: #7f8c8d;
            margin-top: 3px;
            word-wrap: break-word;
        }
        
        .progress-summary {
            background: #ecf0f1;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 20px;
            page-break-inside: avoid;
        }
        .progress-bar {
            height: 20px;
            background: #bdc3c7;
            border-radius: 3px;
            overflow: hidden;
            margin-bottom: 5px;
        }
        .progress-fill {
            height: 100%;
            background: #27ae60;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 8px;
            font-weight: bold;
        }
        .progress-text {
            font-size: 9px;
            color: #555;
            text-align: right;
        }
        
        .footer {
            margin-top: 30px;
            padding-top: 15px;
            border-top: 1px solid #bdc3c7;
            font-size: 8px;
            color: #7f8c8d;
            text-align: center;
        }
        
        .no-data {
            padding: 15px;
            background: #fff3cd;
            border-left: 3px solid #ffc107;
            margin-bottom: 15px;
            border-radius: 3px;
            font-size: 9px;
        }
        
        .page-break {
            page-break-after: always;
        }
        
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-title">
            📋 Laporan Progress Patrol Scan
        </div>
        <div class="header-info">
            <div class="info-item">
                <div class="info-label">Organisasi</div>
                <div class="info-value">{{ $organization->name }}</div>
            </div>
            <div class="info-item">
                <div class="info-label">Proyek</div>
                <div class="info-value">{{ $project->name }}</div>
            </div>
            <div class="info-item">
                <div class="info-label">Tanggal Laporan</div>
                <div class="info-value">{{ $generated_at->format('d/m/Y H:i') }}</div>
            </div>
        </div>
    </div>

    <div class="grid-2">
        <div class="info-item">
            <div class="info-label">Nama Petugas</div>
            <div class="info-value">{{ $attendance->user->full_name }}</div>
        </div>
        <div class="info-item">
            <div class="info-label">Jabatan</div>
            <div class="info-value">
                @if($attendance->user->role === 'komandan_regu')
                    Komandan Regu (Danru)
                @elseif($attendance->user->role === 'anggota')
                    Anggota
                @else
                    {{ $attendance->user->role }}
                @endif
            </div>
        </div>
    </div>

    @if($snapshot)
    <div class="progress-summary" style="margin-top: 15px;">
        <div style="font-weight: bold; margin-bottom: 8px; font-size: 10px;">
            📊 Ringkasan Progress
        </div>
        <div style="margin-bottom: 8px;">
            <div style="font-size: 9px; margin-bottom: 3px;">
                Titik Patroli: <strong>{{ $snapshot->scanned_patrol_points }}/{{ $snapshot->total_patrol_points }}</strong> selesai
            </div>
            <div class="progress-bar">
                <div class="progress-fill" style="width: {{ $snapshot->progress_percentage }}%;">
                    {{ round($snapshot->progress_percentage, 1) }}%
                </div>
            </div>
        </div>
    </div>
    @endif

    @if($scans_by_post->isEmpty())
        <div class="no-data">
            ⚠️ Tidak ada data scan pada periode ini
        </div>
    @else
        @foreach($scans_by_post as $postIndex => $post)
            <div class="post-section">
                <div class="post-header">
                    🚩 {{ $post['post_name'] }}
                    <span style="font-size: 9px; font-weight: normal; margin-left: 10px;">
                        ({{ $post['post_type'] === 'mobile' ? 'Mobile' : 'Statis' }})
                    </span>
                </div>

                @if($post['patrol_points']->isEmpty())
                    <div class="no-data" style="font-size: 9px;">
                        Belum ada data titik patroli untuk post ini
                    </div>
                @else
                    @foreach($post['patrol_points'] as $pointIndex => $point)
                        <div class="point-group">
                            <div class="point-header">
                                📍 {{ $point['point_name'] }}
                                @if($point['sequence_order'])
                                    <span style="color: #7f8c8d;">(Urutan: {{ $point['sequence_order'] }})</span>
                                @endif
                            </div>
                            
                            <div class="point-meta">
                                <div>
                                    <span style="color: #7f8c8d;">Latitude:</span> {{ $point['latitude'] }}
                                </div>
                                <div>
                                    <span style="color: #7f8c8d;">Longitude:</span> {{ $point['longitude'] }}
                                </div>
                            </div>

                            @if($point['scans']->isEmpty())
                                <div class="no-data" style="margin-bottom: 0;">
                                    Belum di-scan
                                </div>
                            @else
                                @foreach($point['scans'] as $scan)
                                    <div class="scan-entry">
                                        <div class="scan-timestamp">
                                            ✓ {{ $scan['scan_time']->format('d/m/Y H:i:s') }}
                                        </div>
                                        <div class="scan-user">
                                            👤 {{ $scan['scan_user'] ?? 'Unknown User' }}
                                        </div>
                                        @if($scan['note'])
                                            <div class="scan-note">
                                                💬 {{ $scan['note'] }}
                                            </div>
                                        @endif

                                        @if(!$scan['photos']->isEmpty())
                                            <div class="photos-grid">
                                                @foreach($scan['photos'] as $photoIndex => $photo)
                                                    @if($photoIndex < 4)
                                                        <div class="photo-item">
                                                            <img src="{{ $photo['url'] }}" alt="Foto Scan {{ $photoIndex + 1 }}">
                                                            <div class="photo-label">
                                                                Foto {{ $photoIndex + 1 }}
                                                            </div>
                                                        </div>
                                                    @endif
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            @endif
                        </div>
                    @endforeach
                @endif
            </div>
            
            @if($postIndex < count($scans_by_post) - 1)
                <div class="page-break"></div>
            @endif
        @endforeach
    @endif

    <div class="footer">
        <div style="margin-bottom: 10px;">
            Laporan ini dibuat secara otomatis oleh sistem E-Patrol
        </div>
        <div>
            Generated: {{ $generated_at->format('d/m/Y H:i:s') }} | 
            @if(isset($session_start))
                Periode: {{ $session_start->format('d/m/Y H:i:s') }} - {{ $session_end->format('d/m/Y H:i:s') }}
            @else
                Berdasarkan data tersimpan
            @endif
        </div>
    </div>
</body>
</html>
