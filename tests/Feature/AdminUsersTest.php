<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminUsersTest extends TestCase
{
    use RefreshDatabase;

    private function data(array $overrides = []): array
    {
        return array_replace(['name' => 'Operator', 'email' => 'operator@example.test', 'password' => 'Strong password 2026',
            'password_confirmation' => 'Strong password 2026', 'role' => 'user', 'is_active' => true], $overrides);
    }

    public function test_admin_creates_user_with_hashed_password_and_lists_edit_form(): void
    {
        $this->withoutVite();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get('/admin/users/create')->assertOk();
        $this->post('/admin/users', $this->data())->assertRedirect(route('admin.users.index'));
        $user = User::where('email', 'operator@example.test')->sole();
        $this->assertTrue(Hash::check('Strong password 2026', $user->password));
        $this->assertSame('user', $user->role);
        $this->assertTrue($user->is_active);
        $this->get('/admin/users')->assertSee('operator@example.test')->assertDontSee($user->password);
        $this->get('/admin/users/'.$user->id.'/edit')->assertSee('Operator')->assertDontSee('Strong password 2026');
    }

    public function test_admin_updates_identity_role_active_flag_and_optional_password(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $oldHash = $user->password;
        $this->actingAs($admin)->patch('/admin/users/'.$user->id, $this->data(['password' => '', 'password_confirmation' => '',
            'role' => 'admin', 'is_active' => false]))->assertRedirect();
        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Operator', 'email' => 'operator@example.test', 'role' => 'admin', 'is_active' => false]);
        $this->assertSame($oldHash, $user->fresh()->password);
        $this->patch('/admin/users/'.$user->id, $this->data(['role' => 'user']))->assertRedirect();
        $this->assertTrue(Hash::check('Strong password 2026', $user->fresh()->password));
        $this->assertTrue($user->fresh()->is_active);
    }

    public function test_normal_user_cannot_reach_any_admin_action_or_promote_self(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->admin()->create();
        $this->actingAs($user);
        foreach (['/admin/users', '/admin/users/create', '/admin/users/'.$other->id.'/edit'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->post('/admin/users', $this->data())->assertForbidden();
        $this->patch('/admin/users/'.$other->id, $this->data())->assertForbidden();
        $this->patch('/admin/users/'.$user->id, $this->data(['role' => 'admin']))->assertForbidden();
        $this->assertDatabaseCount('users', 2);
        $this->assertSame('user', $user->fresh()->role);
    }

    public function test_gate_allows_only_active_admins(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $inactive = User::factory()->admin()->inactive()->create();
        $this->assertTrue(Gate::forUser($admin)->allows('manage-users'));
        $this->assertFalse(Gate::forUser($user)->allows('manage-users'));
        $this->assertFalse(Gate::forUser($inactive)->allows('manage-users'));
    }

    public function test_last_active_admin_cannot_be_demoted_or_disabled(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->patch('/admin/users/'.$admin->id, $this->data(['email' => $admin->email]))
            ->assertSessionHasErrors('role');
        $this->patch('/admin/users/'.$admin->id, $this->data(['email' => $admin->email, 'role' => 'admin', 'is_active' => false]))
            ->assertSessionHasErrors('role');
        $this->assertTrue($admin->fresh()->isAdmin());
    }

    public function test_validation_rejects_duplicate_email_weak_password_and_invalid_fields_without_writes(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $this->postJson('/admin/users', [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'email', 'password', 'role', 'is_active']);
        $this->postJson('/admin/users', $this->data(['email' => strtoupper($admin->email)]))->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/admin/users', $this->data(['password' => 'short', 'password_confirmation' => 'short']))->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/admin/users', $this->data(['password_confirmation' => 'different']))->assertUnprocessable();
        $this->postJson('/admin/users', $this->data(['name' => [], 'role' => 'superadmin', 'is_active' => 'yes']))->assertUnprocessable();
        $this->patchJson('/admin/users/9999', $this->data())->assertNotFound();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_admin_can_demote_another_admin_and_untrusted_fields_do_not_change_identity(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->admin()->create();
        $this->actingAs($admin)->patch('/admin/users/'.$other->id, $this->data(['id' => $admin->id, 'email_verified_at' => '2000-01-01']))
            ->assertRedirect();
        $this->assertSame('user', $other->fresh()->role);
        $this->assertTrue($admin->fresh()->isAdmin());
        $this->assertDatabaseCount('users', 2);
    }
}
