<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ActivityShiftTime;

class ActivityShiftTimeSeeder extends Seeder
{
    public function run(): void
    {
        ActivityShiftTime::insert([
            // Istirahat - Shift Pagi
            [
                'activity_id' => 1,
                'shift_id' => 1,
                'start_time' => '12:00',
                'end_time' => '13:00',
            ],
            // Istirahat - Shift Malam
            [
                'activity_id' => 1,
                'shift_id' => 2,
                'start_time' => '00:00',
                'end_time' => '01:00',
            ],
            // Serah Terima
            [
                'activity_id' => 2,
                'shift_id' => 1,
                'start_time' => '13:30',
                'end_time' => '14:00',
            ],
            // Patroli
            [
                'activity_id' => 3,
                'shift_id' => 3,
                'start_time' => '09:00',
                'end_time' => '11:00',
            ],
        ]);
    }
}
