<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $users = [

            // DEV
            [
                'full_name' => 'Developer',
                'username' => 'developer',
                'email' => 'dev@system.bgeo',
                'role' => 'dev',
                'password' => Hash::make('developer123'),
            ],

            // ORGANIZATION 1
            [
                'full_name' => 'Head Ofc',
                'username' => 'headofc',
                'email' => 'headofc@gmail.com',
                'role' => 'ho',
                'organization_id' => 1,
                'password' => Hash::make('ho123456'),
            ],
            [
                'full_name' => 'Admin Ofc 1.1',
                'username' => 'adminofc1.1',
                'email' => 'adminofficer1.1@gmail.com',
                'role' => 'admin_project',
                'organization_id' => 1,
                'project_id' => 1,
                'password' => Hash::make('admin123'),
            ],
            [
                'full_name' => 'Admin Ofc 2.1',
                'username' => 'adminofc2.1',
                'email' => 'adminofficer2.1@gmail.com',
                'role' => 'admin_project',
                'organization_id' => 1,
                'project_id' => 2,
                'password' => Hash::make('admin123'),
            ],
            [
                'full_name' => 'Danru Ofc 1.1',
                'username' => 'danruofc1.1',
                'email' => 'danruofficer1.1@gmail.com',
                'role' => 'komandan_regu',
                'organization_id' => 1,
                'project_id' => 1,
                'password' => Hash::make('danru123'),
            ],
            [
                'full_name' => 'Danru Ofc 2.1',
                'username' => 'danruofc2.1',
                'email' => 'danruofficer2.1@gmail.com',
                'role' => 'komandan_regu',
                'organization_id' => 1,
                'project_id' => 2,
                'password' => Hash::make('danru123'),
            ],
            [
                'full_name' => 'Anggota Ofc 1.1',
                'username' => 'anggotaofc1.1',
                'email' => 'anggotaofficer1.1@gmail.com',
                'role' => 'anggota',
                'organization_id' => 1,
                'project_id' => 1,
                'password' => Hash::make('anggota123'),
            ],
            [
                'full_name' => 'Anggota Ofc 1.2',
                'username' => 'anggotaofc1.2',
                'email' => 'anggotaofficer1.2@gmail.com',
                'role' => 'anggota',
                'organization_id' => 1,
                'project_id' => 1,
                'password' => Hash::make('anggota123'),
            ],
            [
                'full_name' => 'Anggota Ofc 2.1',
                'username' => 'anggotaofc2.1',
                'email' => 'anggotaofficer2.1@gmail.com',
                'role' => 'anggota',
                'organization_id' => 1,
                'project_id' => 2,
                'password' => Hash::make('anggota123'),
            ],
            [
                'full_name' => 'Anggota Ofc 2.2',
                'username' => 'anggotaofc2.2',
                'email' => 'anggotaofficer2.2@gmail.com',
                'role' => 'anggota',
                'organization_id' => 1,
                'project_id' => 2,
                'password' => Hash::make('anggota123'),
            ],

            // ORGANIZATION 2
            [
                'full_name' => 'Head buj',
                'username' => 'headbuj',
                'email' => 'headbujp@gmail.com',
                'role' => 'ho',
                'organization_id' => 2,
                'password' => Hash::make('ho123456'),
            ],
            [
                'full_name' => 'Admin buj 1.1',
                'username' => 'adminbuj1.1',
                'email' => 'adminbujp1.1@gmail.com',
                'role' => 'admin_project',
                'organization_id' => 2,
                'project_id' => 3,
                'password' => Hash::make('admin123'),
            ],
            [
                'full_name' => 'Admin buj 2.1',
                'username' => 'adminbuj2.1',
                'email' => 'adminbujp2.1@gmail.com',
                'role' => 'admin_project',
                'organization_id' => 2,
                'project_id' => 4,
                'password' => Hash::make('admin123'),
            ],
            [
                'full_name' => 'Danru buj 1.1',
                'username' => 'danrubuj1.1',
                'email' => 'danrubujp1.1@gmail.com',
                'role' => 'komandan_regu',
                'organization_id' => 2,
                'project_id' => 3,
                'password' => Hash::make('danru123'),
            ],
            [
                'full_name' => 'Danru buj 2.1',
                'username' => 'danrubuj2.1',
                'email' => 'danrubujp2.1@gmail.com',
                'role' => 'komandan_regu',
                'organization_id' => 2,
                'project_id' => 4,
                'password' => Hash::make('danru123'),
            ],
            [
                'full_name' => 'Anggota buj 1.1',
                'username' => 'anggotabuj1.1',
                'email' => 'anggotabujp1.1@gmail.com',
                'role' => 'anggota',
                'organization_id' => 2,
                'project_id' => 3,
                'password' => Hash::make('anggota123'),
            ],
            [
                'full_name' => 'Anggota buj 1.2',
                'username' => 'anggotabuj1.2',
                'email' => 'anggotabujp1.2@gmail.com',
                'role' => 'anggota',
                'organization_id' => 2,
                'project_id' => 3,
                'password' => Hash::make('anggota123'),
            ],
            [
                'full_name' => 'Anggota buj 2.1',
                'username' => 'anggotabuj2.1',
                'email' => 'anggotabujp2.1@gmail.com',
                'role' => 'anggota',
                'organization_id' => 2,
                'project_id' => 4,
                'password' => Hash::make('anggota123'),
            ],
            [
                'full_name' => 'Anggota buj 2.2',
                'username' => 'anggotabuj2.2',
                'email' => 'anggotabujp2.2@gmail.com',
                'role' => 'anggota',
                'organization_id' => 2,
                'project_id' => 4,
                'password' => Hash::make('anggota123'),
            ],
        ];

        foreach ($users as $user) {
            User::create($user);
        }
    }
}
