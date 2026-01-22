<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Organization;

class OrganizationSeeder extends Seeder
{
    public function run(): void
    {
        Organization::insert([
            [
                'id' => 1,
                'name' => 'Office',
                'email' => 'office@gmail.com',
                'phone' => '0892134562',
                'code' => 'ofc',
                'timezone' => 'Asia/Jakarta',
                'active' => true,
            ],
            [
                'id' => 2,
                'name' => 'BUJP',
                'email' => 'bujp@gmail.com',
                'phone' => '0892134562',
                'code' => 'buj',
                'timezone' => 'Asia/Jakarta',
                'active' => true,
            ],
        ]);
    }
}
