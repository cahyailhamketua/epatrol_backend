<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">

<style>

    @page{
        margin:40px 50px 50px 50px;
    }

    body{
        margin:0;
        font-family: Arial, Helvetica, sans-serif;
        font-size:11px;
        color:#000;
        line-height:1.5;
    }

    .header-table{
        width:100%;
        border-collapse:collapse;
        margin-bottom:20px;
    }

    .header-table td{
        border:none;
        vertical-align:top;
    }

    .logo-left{
        width:90px;
        text-align:left;
    }

    .logo-right{
        width:90px;
        text-align:right;
    }

    .logo{
        width:65px;
        height:auto;
    }

    .title{
        text-align:center;
    }

    .title-text{
        font-size:12px;
        text-transform:uppercase;
    }

    .title-line{
        width:190px;
        border-top:1px solid #999;
        margin:1.5px auto;
    }

    .doc-number{
        font-size:12px;
        text-transform:uppercase;
    }

    .section{
        margin-top:12px;
    }

    .detail-table{
        margin-top:5px;
        border-collapse:collapse;
    }

    .detail-table td{
        padding:1px 0;
        vertical-align:top;
    }

    .info-person{
        border-collapse:collapse;
        margin-top:0;
        margin-bottom:0;
    }

    .info-person td{
        padding:1px 0;
    }

    .intro-text{
        margin-bottom:0;
    }

    .report-text{
        margin-top:0;
        margin-bottom:8px;
    }

    ol{
        margin-top:4px;
        padding-left:55px;
    }

    li{
        margin-bottom:2px;
    }

    .signature-top{
        width:100%;
        margin-top:40px;
        border-collapse:collapse;
    }

    .signature-top td{
        width:50%;
        text-align:center;
        vertical-align:top;
    }

    .signature-bottom{
        width:100%;
        margin-top:30px;
        border-collapse:collapse;
    }

    .signature-bottom td{
        text-align:center;
    }

    .signature-space{
        height:70px;
    }

    .signature-name{
        text-transform:uppercase;
        display:inline-block;
        min-width:160px;
    }

    .signature-position{
        text-transform:uppercase;
    }

    .signature-line{
        width:150px;
        margin:1.5px auto;
        border-top:1px solid #999;
    }

    .signature-date{
        margin-bottom:15px;
    }

    .signature-date-placeholder{
        height:20px;
        margin-bottom:15px;
    }

</style>

</head>

<body>
@php
\Carbon\Carbon::setLocale('id');
@endphp
<table class="header-table">
    <tr>

        <td class="logo-left">
            @if($organizationLogo)
                <img
                    src="{{ $organizationLogo }}"
                    class="logo"
                >
            @endif
        </td>

        <td class="title">

            <div class="title-text">
                BERITA ACARA
            </div>

            <div class="title-line"></div>

            <div class="doc-number">
                NOMOR :
                {{ $beritaAcara->document_number }}
            </div>

        </td>

        <td class="logo-right">
            @if($projectLogo)
                <img
                    src="{{ $projectLogo }}"
                    class="logo"
                >
            @endif
        </td>

    </tr>
</table>

<p class="intro-text">
    Pada hari ini
    {{ $beritaAcara->incident_date->translatedFormat('l') }}
    tanggal
    {{ $beritaAcara->incident_date->translatedFormat('d F Y') }},
    saya yang sedang melaksanakan tugas dibawah ini :
</p>

<table class="info-person">

    <tr>
        <td width="80">Nama</td>
        <td width="10">:</td>
        <td>
            {{ $beritaAcara->creator?->full_name ?? '-' }}
        </td>
    </tr>

    <tr>
        <td>Jabatan</td>
        <td>:</td>
        <td>
            {{ ucwords(str_replace('_', ' ', $beritaAcara->creator?->role ?? '-')) }}
        </td>
    </tr>

</table>

<p class="report-text">
    Melaporkan sebagai berikut :
</p>

<div class="section">

    <u>
        A. Informasi Awal :
    </u>

    <table class="detail-table">

        <tr>
            <td width="80">Hari/Tanggal</td>
            <td width="10">:</td>
            <td>
                {{
                    $beritaAcara->incident_date
                    ->translatedFormat('l, d F Y')
                }}
            </td>
        </tr>

        <tr>
            <td>Pukul</td>
            <td>:</td>
            <td>
                {{ $beritaAcara->incident_time }} WIB
            </td>
        </tr>

        <tr>
            <td>Kejadian/Kegiatan</td>
            <td>:</td>
            <td>
                {{ $beritaAcara->subject }}
            </td>
        </tr>

        <tr>
            <td>Lokasi</td>
            <td>:</td>
            <td>
                {{ $beritaAcara->location }}
            </td>
        </tr>

    </table>

    <p class="report-text">
        {{ $beritaAcara->description }}
    </p>

</div>

<div class="section">

    <u>
        B. Kronologi :
    </u>

    @if(!empty($beritaAcara->chronologies))

        <ol>

            @foreach($beritaAcara->chronologies as $item)

                <li>
                    {{ $item }}
                </li>

            @endforeach

        </ol>

    @endif

</div>

<div class="section">

    <u>
        C. Tindakan Yang Dilakukan :
    </u>

    @if(!empty($beritaAcara->actions_taken))

        <ol>

            @foreach($beritaAcara->actions_taken as $item)

                <li>
                    {{ $item }}
                </li>

            @endforeach

        </ol>

    @endif

</div>

<div class="section">

    <p>
        Demikian laporan yang dapat saya sampaikan
        sebagai bahan periksa pimpinan.
        Terima kasih.
    </p>

</div>

<table class="signature-top">

    <tr>

        <td>

            <div class="signature-date-placeholder">
                &nbsp;
            </div>


            <div>
                YANG MEMBUAT
            </div>

            <div class="signature-space"></div>

            <div class="signature-name">
                {{
                    strtoupper(
                        $beritaAcara->creator?->full_name
                        ?? '-'
                    )
                }}
            </div>

            <div class="signature-line"></div>

            <div class="signature-position">
                {{ strtoupper(str_replace('_', ' ', $beritaAcara->creator?->role ?? '-')) }}
            </div>

        </td>

        <td>

            <div class="signature-date">
                {{ $project->location_city }},
                {{
                    $beritaAcara->incident_date
                    ->translatedFormat('d F Y')
                }}
            </div>

            <div>
                DIPERIKSA
            </div>

            <div class="signature-space"></div>

            <div class="signature-name">
                {{
                    strtoupper(
                        $beritaAcara->inspector_name
                        ?? '-'
                    )
                }}
            </div>

            <div class="signature-line"></div>

            <div class="signature-position">
                {{
                    strtoupper(
                        $beritaAcara->inspector_position
                        ?? '-'
                    )
                }}
            </div>

        </td>

    </tr>

</table>

<table class="signature-bottom">

    <tr>
        <td>

            <div>
                DIKETAHUI:
            </div>

            <div class="signature-space"></div>

            <div class="signature-name">
                {{
                    strtoupper(
                        $beritaAcara->acknowledged_by
                        ?? '-'
                    )
                }}
            </div>

            <div class="signature-line"></div>

            <div class="signature-position">
                {{
                    strtoupper(
                        $beritaAcara->acknowledged_position
                        ?? '-'
                    )
                }}
            </div>

        </td>
    </tr>

</table>

</body>
</html>