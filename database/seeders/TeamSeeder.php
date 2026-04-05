<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Team;

class TeamSeeder extends Seeder
{
    public function run(): void
    {
        Team::insert([
            [
                'id' => 1,
                'project_id' => 1,
                'name' => 'regu 1',
                'leader_id' => 6,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ]);
    }
}