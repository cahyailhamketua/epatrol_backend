<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\TeamUser;

class TeamUserSeeder extends Seeder
{
    public function run(): void
    {
        TeamUser::insert([
            [
                'id' => 1,
                'team_id' => 1,
                'user_id' => 6,
                'start_date' => '2026-03-01',
                'end_date' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 2,
                'team_id' => 1,
                'user_id' => 8,
                'start_date' => '2026-03-01',
                'end_date' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ]);
    }
}