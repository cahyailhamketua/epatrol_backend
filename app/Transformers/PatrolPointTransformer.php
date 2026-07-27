<?php

namespace App\Transformers;

class PatrolPointTransformer
{
    public static function transform($point): array
    {
        return [
            'id' => $point['id'],
            'name' => $point['name'],
            'sequence_order' => $point['sequence_order'],
            'latitude' => $point['latitude'],
            'longitude' => $point['longitude'],
            'is_scanned' => $point['is_scanned'],
            'scanned_count' => $point['scanned_count'],
            'last_scan_time' => $point['last_scan_time'],
            'last_scan_note' => $point['last_scan_note'],
            'last_scan_user' => $point['last_scan_user'],
        ];
    }
}