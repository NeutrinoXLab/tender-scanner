<?php

namespace Tests\Feature;

use App\Models\Notice;
use App\Models\User;
use App\Models\UserNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NoticeAsyncSaveTest extends TestCase
{
    use RefreshDatabase;

    public function test_json_save_is_idempotent_isolated_and_reversible(): void
    {
        $user = User::factory()->create();
        $notice = Notice::factory()->create();
        $other = UserNotice::factory()->for($notice)->create(['is_saved' => true]);
        $personal = UserNotice::factory()->for($notice)->for($user)->create([
            'is_ignored' => true, 'personal_status' => 'analyzing', 'personal_notes' => 'Nota mea',
        ]);
        $url = route('notices.personal.update', $notice);
        $this->actingAs($user);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->post($url, ['_method' => 'PATCH', 'action' => 'save'], ['Accept' => 'application/json'])
                ->assertOk()->assertHeaderMissing('Location')->assertExactJson([
                    'is_saved' => true, 'is_ignored' => false, 'message' => 'Licitația a fost salvată.',
                ]);
        }
        $this->assertDatabaseCount('user_notices', 2);
        $this->assertSame('Nota mea', $personal->fresh()->personal_notes);
        $this->assertSame('analyzing', $personal->fresh()->personal_status);
        $this->patchJson($url, ['action' => 'unsave'])->assertOk()->assertJsonPath('is_saved', false);
        $this->patchJson($url, ['action' => 'unsave'])->assertOk()->assertJsonPath('is_saved', false);
        $this->assertFalse($personal->fresh()->is_saved);
        $this->assertTrue($other->fresh()->is_saved);
    }

    public function test_json_guest_and_inactive_user_cannot_save(): void
    {
        $notice = Notice::factory()->create();
        $url = route('notices.personal.update', $notice);
        $this->patchJson($url, ['action' => 'save'])->assertUnauthorized();
        $this->actingAs(User::factory()->inactive()->create())->patchJson($url, ['action' => 'save'])->assertUnauthorized();
        $this->assertDatabaseCount('user_notices', 0);
    }

    public function test_json_save_requires_csrf_and_preserves_state_on_validation_error(): void
    {
        $notice = Notice::factory()->create();
        $this->actingAs(User::factory()->create());
        $url = route('notices.personal.update', $notice);
        $this->patchJson($url, ['action' => 'invalid'])->assertUnprocessable();
        $this->assertDatabaseCount('user_notices', 0);
        $this->app['env'] = 'local';
        $this->patchJson($url, ['action' => 'save'])->assertStatus(419);
        $this->assertDatabaseCount('user_notices', 0);
        $this->withSession(['_token' => 'test-token'])->patchJson($url, ['action' => 'save', '_token' => 'test-token'])
            ->assertOk()->assertJsonPath('is_saved', true);
    }

    public function test_json_save_resolves_merged_notice_and_list_renders_saved_state(): void
    {
        $this->withoutVite();
        $this->actingAs(User::factory()->create());
        $canonical = Notice::factory()->create();
        $old = Notice::factory()->create(['canonical_notice_id' => $canonical->id]);
        $this->patchJson(route('notices.personal.update', $old), ['action' => 'save'])
            ->assertOk()->assertJsonPath('is_saved', true);
        $this->assertDatabaseHas('user_notices', ['notice_id' => $canonical->id, 'is_saved' => true]);
        $this->assertDatabaseMissing('user_notices', ['notice_id' => $old->id]);
        $this->get('/notices?collection=saved')->assertOk()->assertSee('data-save-notice', false)
            ->assertSee('Elimină din salvate')->assertSee('data-save-feedback', false);
    }
}
