<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\QrCode;
use Illuminate\Support\Str;

class QrCodeSeeder extends Seeder
{
    public function run(): void
    {
        QrCode::insert([
            [
                'patrol_point_id' => 1,
                'code' => strtoupper(Str::uuid()),
                'active' => true,
            ],
            [
                'patrol_point_id' => 2,
                'code' => strtoupper(Str::uuid()),
                'active' => true,
            ],
            [
                'patrol_point_id' => 3,
                'code' => strtoupper(Str::uuid()),
                'active' => true,
            ],
            [
                'patrol_point_id' => 4,
                'code' => strtoupper(Str::uuid()),
                'active' => true,
            ],
            [
                'patrol_point_id' => 5,
                'code' => strtoupper(Str::uuid()),
                'active' => true,
            ],
            [
                'patrol_point_id' => 6,
                'code' => strtoupper(Str::uuid()),
                'active' => true,
            ],
        ]);
    }
}
