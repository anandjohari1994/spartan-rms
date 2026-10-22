<?php

namespace Database\Seeders;

use App\Models\JobPosition;
use Illuminate\Database\Seeder;

class JobPositionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['UI' => 'UI Developer', 'PHP' => 'PHP Developer', 'JAVA' => 'Java Full Stack Developer', 'PYTHON' => 'Python Developer', 'DBA' => 'Database Administrator', 'SUPPORT' => 'Application Support Engineer'] as $code => $name) {
            JobPosition::firstOrCreate(['code' => $code], ['name' => $name, 'is_active' => true]);
        }
    }
}
