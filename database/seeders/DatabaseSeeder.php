<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
{
    $this->call([
        OrganizationSeeder::class,
        ProjectSeeder::class,
        UserSeeder::class,

        ShiftSeeder::class,
        PostSeeder::class,
        PatrolPointSeeder::class,
        QrCodeSeeder::class,

        ActivitySeeder::class,
        ActivityShiftTimeSeeder::class,
    ]);
}

}
