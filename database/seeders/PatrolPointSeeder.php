<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PatrolPoint;

class PatrolPointSeeder extends Seeder
{
    public function run(): void
    {
        PatrolPoint::insert([
            [
                'id' => 1,
                'post_id' => 1,
                'name' => 'Gerbang Depan',
                'sequence_order' => 1,
                'latitude' => -6.595038,
                'longitude' => 106.816635,
                'altitude' => 0,
                'radius' => 5,
            ],
            [
                'id' => 2,
                'post_id' => 2,
                'name' => 'dalam 1',
                'sequence_order' => 1,
                'latitude' => -6.595100,
                'longitude' => 106.816700,
                'altitude' => 0,
                'radius' => 5,
            ],
            [
                'id' => 3,
                'post_id' => 2,
                'name' => 'dalam 2',
                'sequence_order' => 2,
                'latitude' => -6.594900,
                'longitude' => 106.816800,
                'altitude' => 0,
                'radius' => 5,
            ],
            [
                'id' => 4,
                'post_id' => 3,
                'name' => 'Area Lobby',
                'sequence_order' => 1,
                'latitude' => -6.594900,
                'longitude' => 106.816800,
                'altitude' => 0,
                'radius' => 5,
            ],
            [
                'id' => 5,
                'post_id' => 4,
                'name' => 'luar 1',
                'sequence_order' => 1,
                'latitude' => -6.594900,
                'longitude' => 106.816800,
                'altitude' => 0,
                'radius' => 5,
            ],
            [
                'id' => 6,
                'post_id' => 4,
                'name' => 'luar 2',
                'sequence_order' => 2,
                'latitude' => -6.594900,
                'longitude' => 106.816800,
                'altitude' => 0,
                'radius' => 5,
            ],
        ]);
    }
}
