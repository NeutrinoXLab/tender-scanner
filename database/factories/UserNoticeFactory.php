<?php

namespace Database\Factories;

use App\Models\Notice;
use App\Models\User;
use App\Models\UserNotice;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<UserNotice> */
class UserNoticeFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'notice_id' => Notice::factory(),
            'is_saved' => false, 'is_ignored' => false, 'personal_status' => 'new', 'personal_notes' => null];
    }
}
