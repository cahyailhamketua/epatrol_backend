<?php

namespace App\Transformers;

use Illuminate\Support\Facades\Storage;

class ScanDetailTransformer
{
    public static function transform($scan): array
    {
        return [
            'id' => $scan->id,
            'attendance_id' => $scan->attendance_id,
            'patrol_point_id' => $scan->qrCode?->patrolPoint?->id,
            'patrol_point_name' => $scan->qrCode?->patrolPoint?->name,
            'scan_time' => $scan->scan_time,
            'note' => $scan->note,
            'photos' => $scan->photos->map(fn($p) => [
                'id' => $p->id,
                'url' => Storage::disk('public')->url($p->photo),
            ]),
            'scan_user' => [
                'id' => $scan->attendance->user_id,
                'full_name' => $scan->attendance->user?->full_name,
            ],
        ];
    }
}
