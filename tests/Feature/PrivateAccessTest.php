<?php

namespace Tests\Feature;

use App\Models\Notice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PrivateAccessTest extends TestCase
{
    use RefreshDatabase;

    public static function privateRoutes(): array
    {
        return [['GET', '/'], ['GET', '/notices'], ['GET', '/notices/1'], ['GET', '/notices/1/suppliers'],
            ['PATCH', '/notices/1/personal'], ['POST', '/scans'], ['GET', '/scans/latest'],
            ['GET', '/admin/users'], ['GET', '/admin/users/create'], ['POST', '/admin/users'],
            ['GET', '/admin/users/1/edit'], ['PATCH', '/admin/users/1'], ['POST', '/logout']];
    }

    #[DataProvider('privateRoutes')]
    public function test_guest_is_redirected_before_private_data_or_side_effects(string $method, string $url): void
    {
        Queue::fake();
        Http::preventStrayRequests();

        $this->call($method, $url)->assertRedirect(route('login'))->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertDatabaseCount('user_notices', 0);
    }

    public function test_login_is_public_but_registration_and_recovery_are_absent(): void
    {
        $this->withoutVite();
        $this->get('/login')->assertOk()->assertSee('Login')->assertSee('noindex, nofollow')
            ->assertDontSee('Creează utilizator')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        foreach (['/register', '/signup', '/forgot-password', '/reset-password', '/up'] as $url) {
            $this->get($url)->assertNotFound();
        }
        $this->post('/register', ['email' => 'intruder@example.test'])->assertNotFound();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_valid_login_regenerates_session_and_logout_revokes_access(): void
    {
        $user = User::factory()->create();
        $this->get('/notices')->assertRedirect(route('login'));
        $oldSession = session()->getId();

        $this->post('/login', ['email' => strtoupper($user->email), 'password' => 'password'])
            ->assertRedirect(route('notices.index'));
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($oldSession, session()->getId());
        $this->assertNotNull(session('password_hash_web'));
        $this->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
        $this->get('/notices')->assertRedirect(route('login'));
    }

    public function test_wrong_password_inactive_and_unknown_accounts_share_generic_error(): void
    {
        $active = User::factory()->create();
        $inactive = User::factory()->inactive()->create();
        foreach ([[$active->email, 'wrong'], [$inactive->email, 'password'], ['missing@example.test', 'password']] as [$email, $password]) {
            $this->postJson('/login', compact('email', 'password'))->assertUnprocessable()
                ->assertJsonValidationErrors('email')->assertJsonPath('errors.email.0', 'Date de autentificare incorecte sau cont inactiv.');
            $this->assertGuest();
        }
        $this->postJson('/login', ['email' => ['invalid'], 'password' => []])->assertUnprocessable();
    }

    public function test_repeated_failed_login_is_rate_limited_even_with_correct_password_afterwards(): void
    {
        $user = User::factory()->create();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        }
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->travel(61)->seconds();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    public function test_deactivation_ends_existing_session_at_next_request(): void
    {
        $user = User::factory()->create();
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $user->update(['is_active' => false]);

        $this->get('/notices')->assertRedirect(route('login'));
        $this->assertGuest();
        $this->getJson('/scans/latest')->assertUnauthorized();
    }

    public function test_password_reset_revokes_an_existing_session(): void
    {
        $user = User::factory()->create();
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $user->update(['password' => 'Another password 2026']);

        $this->get('/notices')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_csrf_is_required_for_login_and_private_writes(): void
    {
        $user = User::factory()->create();
        $notice = Notice::factory()->create();
        $this->app->instance('env', 'production');

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertStatus(419);
        $this->actingAs($user)->patch('/notices/'.$notice->id.'/personal', ['action' => 'save'])->assertStatus(419);
        $this->post('/logout')->assertStatus(419);
        $this->assertDatabaseCount('user_notices', 0);
    }
}
