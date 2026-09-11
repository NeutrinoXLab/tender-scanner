<?php

namespace Tests\Feature;

use App\Models\Notice;
use App\Models\User;
use App\Models\UserNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_admin_command_prompts_for_hidden_password_and_creates_no_default_account(): void
    {
        $this->artisan('db:seed')->assertSuccessful();
        $this->assertDatabaseCount('users', 0);
        $this->artisan('app:create-admin')
            ->expectsQuestion('Nume', 'Administrator')
            ->expectsQuestion('Email', 'ADMIN@example.test')
            ->expectsQuestion('Parolă (minimum 12 caractere)', 'A private password 2026')
            ->expectsQuestion('Confirmă parola', 'A private password 2026')
            ->assertSuccessful();

        $admin = User::sole();
        $this->assertSame('admin@example.test', $admin->email);
        $this->assertTrue($admin->isAdmin());
        $this->assertTrue(Hash::check('A private password 2026', $admin->password));
        $this->artisan('app:create-admin')->assertFailed();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_first_admin_rejects_noninteractive_or_invalid_password_without_creating_account(): void
    {
        $this->artisan('app:create-admin', ['--no-interaction' => true])->assertFailed();
        $this->artisan('app:create-admin')
            ->expectsQuestion('Nume', 'Administrator')
            ->expectsQuestion('Email', 'admin@example.test')
            ->expectsQuestion('Parolă (minimum 12 caractere)', 'short')
            ->expectsQuestion('Confirmă parola', 'short')
            ->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_legacy_transfer_is_explicit_idempotent_and_preserves_originals(): void
    {
        $admin = User::factory()->admin()->create();
        $notice = Notice::factory()->create();
        $notice->forceFill(['is_saved' => true, 'personal_status' => 'analyzing', 'personal_notes' => 'Notițe vechi'])->save();
        $args = ['email' => $admin->email];
        $this->artisan('app:assign-legacy-personal-data', $args + ['--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseCount('user_notices', 0);
        $this->assertNull($notice->fresh()->legacy_personal_user_id);

        $this->artisan('app:assign-legacy-personal-data', $args)->assertSuccessful();
        $this->artisan('app:assign-legacy-personal-data', $args)->assertSuccessful();
        $this->assertDatabaseCount('user_notices', 1);
        $this->assertDatabaseHas('user_notices', ['user_id' => $admin->id, 'notice_id' => $notice->id,
            'is_saved' => true, 'personal_status' => 'analyzing', 'personal_notes' => 'Notițe vechi']);
        $this->assertDatabaseHas('notices', ['id' => $notice->id, 'legacy_personal_user_id' => $admin->id, 'personal_notes' => 'Notițe vechi']);
    }

    public function test_legacy_transfer_rejects_non_admin_and_never_overwrites_existing_personal_data(): void
    {
        $user = User::factory()->create();
        $this->artisan('app:assign-legacy-personal-data', ['email' => $user->email])->assertFailed();
        $admin = User::factory()->admin()->create();
        $notice = Notice::factory()->create();
        $notice->forceFill(['personal_notes' => 'Vechi'])->save();
        UserNotice::factory()->for($admin)->for($notice)->create(['personal_notes' => 'Nou']);

        $this->artisan('app:assign-legacy-personal-data', ['email' => $admin->email])->assertFailed();
        $this->assertDatabaseHas('user_notices', ['notice_id' => $notice->id, 'personal_notes' => 'Nou']);
        $this->assertDatabaseHas('notices', ['id' => $notice->id, 'personal_notes' => 'Vechi', 'legacy_personal_user_id' => null]);
    }

    public function test_legacy_transfer_targets_canonical_notice_without_discarding_old_record(): void
    {
        $admin = User::factory()->admin()->create();
        $canonical = Notice::factory()->create();
        $old = Notice::factory()->create();
        $old->forceFill(['canonical_notice_id' => $canonical->id, 'personal_notes' => 'Istoric personal'])->save();

        $this->artisan('app:assign-legacy-personal-data', ['email' => $admin->email])->assertSuccessful();
        $this->assertDatabaseHas('user_notices', ['user_id' => $admin->id, 'notice_id' => $canonical->id, 'personal_notes' => 'Istoric personal']);
        $this->assertDatabaseCount('notices', 2);
    }
}
