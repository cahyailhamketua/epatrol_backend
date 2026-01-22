<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Project;

class ProjectSeeder extends Seeder
{
    public function run(): void
    {
        Project::insert([
            [
                'id' => 1,
                'organization_id' => 1,
                'name' => 'office project 1',
                'code' => 'ofc1',
                'location_city' => 'Bogor',
                'active' => true,
            ],
            [
                'id' => 2,
                'organization_id' => 1,
                'name' => 'office project 2',
                'code' => 'ofc2',
                'location_city' => 'Bogor',
                'active' => true,
            ],
            [
                'id' => 3,
                'organization_id' => 2,
                'name' => 'bujp project 1',
                'code' => 'buj1',
                'location_city' => 'Bogor',
                'active' => true,
            ],
            [
                'id' => 4,
                'organization_id' => 2,
                'name' => 'bujp project 2',
                'code' => 'buj2',
                'location_city' => 'Bogor',
                'active' => true,
            ],
        ]);
    }
}
