<?php

namespace Database\Factories;

use App\Models\Notice;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Notice> */
class NoticeFactory extends Factory
{
    public function definition(): array
    {
        return ['seap_id' => fake()->unique()->uuid(), 'title' => fake()->sentence(),
            'status' => 'Publicat', 'submission_deadline' => now()->addWeek()];
    }
}
