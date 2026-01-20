<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class DevUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Cegah duplikasi user dev
        $exists = User::where('username')->exists();

        if ($exists) {
            $this->command->info('user already exists.');
            return;
        }

        User::create([
            'organization_id' => null, // DEV tidak terikat organisasi
            'project_id'      => null, // DEV lintas project
            'full_name'       => 'System Developer',
            'username'        => 'developer',
            'email'           => 'dev@system.bgeo',
            'phone'           => null,
            'role'            => 'dev',
            'password'        => Hash::make('developer123'),
            'active'          => true,
            'join_date'       => now()->toDateString(),
        ]);

        User::create([
            'organization_id' => null, // DEV tidak terikat organisasi
            'project_id'      => null, // DEV lintas project
            'full_name'       => 'Cahya Ilham',
            'username'        => 'cahyailham',
            'email'           => 'cahyailham@gmail.com',
            'phone'           => null,
            'role'            => 'anggota',
            'password'        => Hash::make('cahya123'),
            'active'          => true,
            'join_date'       => now()->toDateString(),
        ]);

        User::create([
            'organization_id' => null, // DEV tidak terikat organisasi
            'project_id'      => null, // DEV lintas project
            'full_name'       => 'Rafi Alexander',
            'username'        => 'rafialexander',
            'email'           => 'alex@gmail.com',
            'phone'           => null,
            'role'            => 'anggota',
            'password'        => Hash::make('alex123'),
            'active'          => true,
            'join_date'       => now()->toDateString(),
        ]);

        User::create([
            'organization_id' => null, // DEV tidak terikat organisasi
            'project_id'      => null, // DEV lintas project
            'full_name'       => 'Faris Fadhil',
            'username'        => 'farisfadhil',
            'email'           => 'fadhil@gmail.com',
            'phone'           => null,
            'role'            => 'komandan_regu',
            'password'        => Hash::make('fadhil123'),
            'active'          => true,
            'join_date'       => now()->toDateString(),
        ]);

        User::create([
            'organization_id' => null, // DEV tidak terikat organisasi
            'project_id'      => null, // DEV lintas project
            'full_name'       => 'admin project',
            'username'        => 'adminproject',
            'email'           => 'admin@gmail.com',
            'phone'           => null,
            'role'            => 'admin_project',
            'password'        => Hash::make('admin123'),
            'active'          => true,
            'join_date'       => now()->toDateString(),
        ]);

        User::create([
            'organization_id' => null, // DEV tidak terikat organisasi
            'project_id'      => null, // DEV lintas project
            'full_name'       => 'Head Officer',
            'username'        => 'headofficer',
            'email'           => 'ho@gmail.com',
            'phone'           => null,
            'role'            => 'ho',
            'password'        => Hash::make('ho123'),
            'active'          => true,
            'join_date'       => now()->toDateString(),
        ]);


        $this->command->info('user created successfully.');
    }
}
