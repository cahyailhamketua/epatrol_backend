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

        AssignmentSeeder::class,
        PostSeeder::class,
        PatrolPointSeeder::class,
        QrCodeSeeder::class,

        ActivitySeeder::class,
        ActivityAssignmentTimeSeeder::class,

        TeamSeeder::class,
        TeamUserSeeder::class,
        TemplateScheduleSeeder::class,
        ScheduleSeeder::class,
        AbsenceSeeder::class,
    ]);
}

}
