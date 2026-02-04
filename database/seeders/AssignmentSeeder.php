<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Assignment;

class AssignmentSeeder extends Seeder
{
    public function run(): void
    {
        Assignment::insert([
            [
                'id' => 1,
                'project_id' => 1,
                'name' => 'Assignment Pagi',
                'code' => 'p',
                'start_time' => '06:00',
                'end_time' => '14:00',
            ],
            [
                'id' => 2,
                'project_id' => 1,
                'name' => 'Assignment Malam',
                'code' => 'm',
                'start_time' => '22:00',
                'end_time' => '06:00',
            ],
            [
                'id' => 3,
                'project_id' => 2,
                'name' => 'Assignment Pagi',
                'code' => 'p',
                'start_time' => '07:00',
                'end_time' => '15:00',
            ],
            [
                'id' => 4,
                'project_id' => 2,
                'name' => 'Assignment Malam',
                'code' => 'm',
                'start_time' => '22:00',
                'end_time' => '06:00',
            ],
        ]);
    }
}
