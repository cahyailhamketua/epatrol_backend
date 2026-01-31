<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Activity;

class ActivitySeeder extends Seeder
{
    public function run(): void
    {
        Activity::insert([
            [
                'id' => 1,
                'post_id' => 1,
                'name' => 'Istirahat',
                'location' => 'masjid',
                'active' => true,
            ],
            [
                'id' => 2,
                'post_id' => 1,
                'name' => 'Serah Terima',
                'location' => 'posko',
                'active' => true,
            ],
            [
                'id' => 3,
                'post_id' => 2,
                'name' => 'Patroli Area',
                'location' => 'post',
                'active' => true,
            ],
        ]);
    }
}
