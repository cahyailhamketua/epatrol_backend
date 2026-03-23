<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\TemplateSchedule;

class TemplateScheduleSeeder extends Seeder
{
    public function run(): void
    {
        TemplateSchedule::insert([
            [
                'id' => 1,
                'project_id' => 1,
                'team_id' => 1,
                'pattern' => json_encode(["p","p","m","m","o","o"]),
                'start_date' => '2026-03-01',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ]);
    }
}