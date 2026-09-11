<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->info('Nu se creează conturi implicite. Primul admin: php artisan app:create-admin');
    }
}
