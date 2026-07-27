<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">

<style>

@page{
    margin:20px;
}

*{
    margin:0;
    padding:0;
    box-sizing:border-box;
}

body{
    font-family:"DejaVu Sans",sans-serif;
    font-size:10px;
    color:#2c3e50;
    line-height:1.5;
    background:#fff;
}

table{
    width:100%;
    border-collapse:collapse;
}

.header{
    border-bottom:3px solid #1f4e79;
    padding-bottom:15px;
    margin-bottom:18px;
}

.title{
    font-size:22px;
    font-weight:bold;
    color:#1f4e79;
    margin-bottom:4px;
}

.subtitle{
    font-size:10px;
    color:#7f8c8d;
}

.info-table{
    margin-top:15px;
}

.info-box{
    width:33%;
    padding:6px;
}

.card{

    border:1px solid #dcdcdc;

    background:#f8fafc;

    border-radius:5px;

    padding:8px;

    min-height:52px;

}

.card-title{

    font-size:8px;

    font-weight:bold;

    color:#7f8c8d;

    text-transform:uppercase;

    margin-bottom:4px;

}

.card-value{

    font-size:10px;

    color:#2c3e50;

}

.section{

    margin-top:18px;

}

.section-title{

    background:#1f4e79;

    color:#fff;

    padding:7px 10px;

    font-size:11px;

    font-weight:bold;

    border-radius:4px 4px 0 0;

}

.section-body{

    border:1px solid #dcdcdc;

    border-top:none;

    padding:10px;

}

.progress-box{

    margin-top:8px;

}

.progress-bar{

    width:100%;

    height:18px;

    background:#e5e7eb;

    border-radius:4px;

    overflow:hidden;

}

.progress-fill{

    height:18px;

    background:#16a34a;

    color:#fff;

    text-align:center;

    font-size:8px;

    line-height:18px;

    font-weight:bold;

}

.post-header{

    background:#34495e;

    color:#fff;

    padding:8px 10px;

    margin-top:20px;

    font-weight:bold;

    border-radius:4px;

}

.point-box{

    border:1px solid #dcdcdc;

    border-left:4px solid #3498db;

    margin-top:10px;

    padding:10px;

    page-break-inside:avoid;

}

.point-title{

    font-size:10px;

    font-weight:bold;

    margin-bottom:6px;

}

.point-meta{

    font-size:8px;

    color:#7f8c8d;

    margin-bottom:8px;

}

.scan-box{

    border:1px solid #dcdcdc;

    border-left:4px solid #27ae60;

    padding:8px;

    margin-top:10px;

    page-break-inside:avoid;

}

.footer{

    margin-top:25px;

    border-top:1px solid #ccc;

    padding-top:8px;

    text-align:center;

    font-size:8px;

    color:#7f8c8d;

}

.page-break{

    page-break-after:always;

}

.no-data{

    padding:12px;

    background:#fff8e1;

    border-left:4px solid #f59e0b;

    font-size:9px;

}

</style>

</head>

<body>

<div class="header">

<div class="title">

LAPORAN PROGRESS PATROL SCAN

</div>

<div class="subtitle">

E-Patrol Monitoring Report

</div>

<table class="info-table">

<tr>

<td class="info-box">

<div class="card">

<div class="card-title">

Organisasi

</div>

<div class="card-value">

{{ $organization->name }}

</div>

</div>

</td>

<td class="info-box">

<div class="card">

<div class="card-title">

Proyek

</div>

<div class="card-value">

{{ $project->name }}

</div>

</div>

</td>

<td class="info-box">

<div class="card">

<div class="card-title">

Tanggal Laporan

</div>

<div class="card-value">

{{ $generated_at->format('d/m/Y H:i') }}

</div>

</div>

</td>

</tr>

<tr>

<td class="info-box">

<div class="card">

<div class="card-title">

Nama Petugas

</div>

<div class="card-value">

{{ $attendance->user->full_name }}

</div>

</div>

</td>

<td class="info-box">

<div class="card">

<div class="card-title">

Jabatan

</div>

<div class="card-value">

@if($attendance->user->role==='komandan_regu')

Komandan Regu (Danru)

@elseif($attendance->user->role==='anggota')

Anggota

@else

{{ $attendance->user->role }}

@endif

</div>

</div>

</td>

<td class="info-box">

<div class="card">

<div class="card-title">

Generated

</div>

<div class="card-value">

{{ $generated_at->format('d/m/Y H:i:s') }}

</div>

</div>

</td>

</tr>

</table>

</div>

@if($snapshot)

<div class="section">

<div class="section-title">

RINGKASAN PROGRESS

</div>

<div class="section-body">

<div style="margin-bottom:8px;">

Titik Patrol Selesai

<strong>

{{ $snapshot->scanned_patrol_points }}

/

{{ $snapshot->total_patrol_points }}

</strong>

</div>

<div class="progress-box">

<div class="progress-bar">

<div class="progress-fill"

style="width:{{ $snapshot->progress_percentage }}%;">

{{ round($snapshot->progress_percentage,1) }}%

</div>

</div>

</div>

</div>

</div>

@endif

@if($scans_by_post->isEmpty())

<div class="no-data">

Tidak ada data scan pada periode ini.

</div>

@else

@foreach($scans_by_post as $postIndex => $post)

<div class="post-header">

<table width="100%">

<tr>

<td style="font-size:12px;font-weight:bold;">

{{ strtoupper($post['post_name']) }}

</td>

<td align="right" style="font-size:9px;">

Jenis :

<strong>

{{ $post['post_type']=="mobile" ? "Mobile" : "Statis" }}

</strong>

</td>

</tr>

</table>

</div>

@if($post['patrol_points']->isEmpty())

<div class="no-data">

Belum ada data titik patroli.

</div>

@else

@foreach($post['patrol_points'] as $point)

<div class="point-box">

<table width="100%">

<tr>

<td width="70%">

<div class="point-title">

{{ $point['point_name'] }}

@if($point['sequence_order'])

&nbsp;

<span style="font-size:8px;color:#7f8c8d;">

(Sequence {{ $point['sequence_order'] }})

</span>

@endif

</div>

<div class="point-meta">

Latitude :
{{ $point['latitude'] }}

<br>

Longitude :
{{ $point['longitude'] }}

</div>

</td>

<td width="30%" align="right">

@if($point['scans']->isEmpty())

<span style="color:#dc2626;font-weight:bold;">

BELUM SCAN

</span>

@else

<span style="color:#16a34a;font-weight:bold;">

SUDAH SCAN

</span>

@endif

</td>

</tr>

</table>

@if($point['scans']->isEmpty())

<div class="no-data">

Belum dilakukan scan pada titik ini.

</div>

@else

@foreach($point['scans'] as $scan)

<div class="scan-box">

<table width="100%" style="border-collapse:collapse;">

<tr>

<td width="55%" valign="top" style="padding-right:10px;">

<table width="100%">

<tr>
<td style="width:95px;font-weight:bold;">Tanggal Scan</td>
<td>: {{ optional($scan['scan_time'])->format('d/m/Y H:i:s') }}</td>
</tr>

<tr>
<td style="font-weight:bold;">Petugas</td>
<td>: {{ $scan['scan_user'] ?? '-' }}</td>
</tr>

<tr>
<td style="font-weight:bold;">Catatan</td>
<td>:
@if(!empty($scan['note']))
{{ $scan['note'] }}
@else
-
@endif
</td>
</tr>

@if(isset($scan['project_name']))
<tr>
<td style="font-weight:bold;">Project</td>
<td>: {{ $scan['project_name'] }}</td>
</tr>
@endif

</table>

</td>

<td width="45%" valign="top">

@if(!$scan['photos']->isEmpty())

<table width="100%" style="border-collapse:collapse;">

<tr>

@foreach($scan['photos']->take(4) as $photoIndex=>$photo)

<td align="center" style="padding:3px;">

<img src="{{ $photo['url'] }}"
style="
width:100%;
height:120px;
object-fit:cover;
border:1px solid #cccccc;
border-radius:3px;
">

<div style="font-size:7px;color:#666;margin-top:2px;">

Foto {{ $photoIndex+1 }}

</div>

</td>

@if(($photoIndex+1)%2==0 && !$loop->last)

</tr>

<tr>

@endif

@endforeach

</tr>

</table>

@else

<div style="
height:120px;
border:1px dashed #bbbbbb;
text-align:center;
line-height:120px;
color:#999;
font-size:9px;
">

Tidak ada foto

</div>

@endif

</td>

</tr>

</table>

</div>

@endforeach

@endif

</div>

@endforeach

@endif

@if($postIndex < count($scans_by_post)-1)

<div class="page-break"></div>

@endif

@endforeach

@endif

<div style="margin-top:30px;"></div>

<table width="100%" style="border-top:2px solid #d1d5db;padding-top:10px;">

<tr>

<td width="50%" valign="top">

<div style="font-size:8px;color:#6b7280;">

<strong>Sistem</strong>

<br>

Laporan ini dibuat otomatis oleh sistem E-Patrol.

<br>

Seluruh data diambil dari hasil scan yang tersimpan pada database.

</div>

</td>

<td width="50%" align="right" valign="top">

<table style="font-size:8px;">

<tr>

<td style="padding:2px 5px;">

Generated

</td>

<td style="padding:2px 5px;">

:

</td>

<td style="padding:2px 5px;">

{{ $generated_at->format('d/m/Y H:i:s') }}

</td>

</tr>

@if(isset($session_start))

<tr>

<td style="padding:2px 5px;">

Periode

</td>

<td style="padding:2px 5px;">

:

</td>

<td style="padding:2px 5px;">

{{ $session_start->format('d/m/Y H:i') }}

-

{{ $session_end->format('d/m/Y H:i') }}

</td>

</tr>

@endif

<tr>

<td style="padding:2px 5px;">

Organisasi

</td>

<td style="padding:2px 5px;">

:

</td>

<td style="padding:2px 5px;">

{{ $organization->name }}

</td>

</tr>

<tr>

<td style="padding:2px 5px;">

Project

</td>

<td style="padding:2px 5px;">

:

</td>

<td style="padding:2px 5px;">

{{ $project->name }}

</td>

</tr>

</table>

</td>

</tr>

</table>

</body>

</html>