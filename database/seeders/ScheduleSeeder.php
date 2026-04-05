<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Schedule;
use Carbon\Carbon;

class ScheduleSeeder extends Seeder
{
    public function run(): void
    {
        $pattern = [1,1,2,2,5,5];
        $startDate = Carbon::create(2026, 3, 1);
        $endDate = Carbon::create(2026, 4, 30);

        $users = [6, 8];
        $data = [];
        $id = 1;

        $currentDate = $startDate->copy();
        $i = 0;

        while ($currentDate <= $endDate) {
            foreach ($users as $user) {
                $data[] = [
                    'id' => $id++,
                    'project_id' => 1,
                    'user_id' => $user,
                    'assignment_id' => $pattern[$i % count($pattern)],
                    'team_id' => 1,
                    'date' => $currentDate->toDateString(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            $currentDate->addDay();
            $i++;
        }

        Schedule::insert($data);
    }
}