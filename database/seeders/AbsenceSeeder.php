<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Absence;

class AbsenceSeeder extends Seeder
{
    public function run(): void
    {
        Absence::insert([
            [
                'id' => 1,
                'schedule_id' => 1,
                'absence_type' => 'S',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 2,
                'schedule_id' => 32,
                'absence_type' => 'I',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 4,
                'schedule_id' => 35,
                'absence_type' => 'S',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}