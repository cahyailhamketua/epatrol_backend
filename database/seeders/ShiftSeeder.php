<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Shift;

class ShiftSeeder extends Seeder
{
    public function run(): void
    {
        Shift::insert([
            [
                'id' => 1,
                'project_id' => 1,
                'name' => 'Shift Pagi',
                'code' => 'p',
                'start_time' => '06:00',
                'end_time' => '14:00',
            ],
            [
                'id' => 2,
                'project_id' => 1,
                'name' => 'Shift Malam',
                'code' => 'm',
                'start_time' => '22:00',
                'end_time' => '06:00',
            ],
            [
                'id' => 3,
                'project_id' => 2,
                'name' => 'Shift Pagi',
                'code' => 'p',
                'start_time' => '07:00',
                'end_time' => '15:00',
            ],
            [
                'id' => 4,
                'project_id' => 2,
                'name' => 'Shift Malam',
                'code' => 'm',
                'start_time' => '22:00',
                'end_time' => '06:00',
            ],
        ]);
    }
}
