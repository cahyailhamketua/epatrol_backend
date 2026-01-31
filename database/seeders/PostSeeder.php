<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Post;

class PostSeeder extends Seeder
{
    public function run(): void
    {
        Post::insert([
            [
                'id' => 1,
                'project_id' => 1,
                'name' => 'Post Gerbang Utama',
                'type' => 'static',
            ],
            [
                'id' => 2,
                'project_id' => 1,
                'name' => 'patroli dalam ',
                'type' => 'mobile',
            ],
            [
                'id' => 3,
                'project_id' => 2,
                'name' => 'Post Lobby',
                'type' => 'static',
            ],
            [
                'id' => 4,
                'project_id' => 3,
                'name' => 'Patroli luar',
                'type' => 'mobile',
            ],
        ]);
    }
}
