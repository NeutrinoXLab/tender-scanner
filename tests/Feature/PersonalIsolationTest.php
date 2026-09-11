<?php

namespace Tests\Feature;

use App\Models\Notice;
use App\Models\User;
use App\Models\UserNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PersonalIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_personal_fields_are_isolated_and_forged_owner_ids_are_ignored(): void
    {
        $this->withoutVite();
        $a = User::factory()->create();
        $b = User::factory()->create();
        $notice = Notice::factory()->create(['title' => 'Licitație comună']);
        $url = '/notices/'.$notice->id.'/personal';
        $this->actingAs($a)->patch($url, ['action' => 'save']);
        $this->patch($url, ['action' => 'update', 'personal_status' => 'analyzing', 'personal_notes' => 'Notița privată A']);
        $this->actingAs($b)->get('/notices/'.$notice->id)->assertDontSee('Notița privată A')->assertSee('⭐ Salvează');
        $this->patch($url, ['action' => 'ignore', 'user_id' => $a->id, 'notice_id' => 9999]);
        $this->patch($url, ['action' => 'update', 'personal_status' => 'declined', 'personal_notes' => 'Notița privată B', 'user_id' => $a->id]);
        $this->patch($url, ['action' => 'ignore']);

        $this->assertDatabaseHas('user_notices', ['user_id' => $a->id, 'notice_id' => $notice->id,
            'is_saved' => true, 'is_ignored' => false, 'personal_status' => 'analyzing', 'personal_notes' => 'Notița privată A']);
        $this->assertDatabaseHas('user_notices', ['user_id' => $b->id, 'notice_id' => $notice->id,
            'is_saved' => false, 'is_ignored' => true, 'personal_status' => 'declined', 'personal_notes' => 'Notița privată B']);
        $this->assertDatabaseCount('user_notices', 2);
        $this->get('/notices/'.$notice->id)->assertSee('Notița privată B')->assertDontSee('Notița privată A');
        $this->actingAs($a)->get('/notices/'.$notice->id)->assertSee('Notița privată A')->assertDontSee('Notița privată B');
        $this->assertFalse($notice->fresh()->is_saved);
        $this->assertNull($notice->fresh()->personal_notes);
    }

    public function test_filters_and_default_new_state_are_scoped_to_current_user(): void
    {
        $this->withoutVite();
        $a = User::factory()->create();
        $b = User::factory()->create();
        $notice = Notice::factory()->create(['title' => 'Monitor comun', 'cpv_code' => '30231300']);
        UserNotice::factory()->for($a)->for($notice)->create(['is_saved' => true, 'personal_status' => 'analyzing']);
        $this->actingAs($b)->get('/notices?collection=saved')->assertViewHas('notices', fn ($rows) => $rows->total() === 0);
        $this->get('/notices?personal_status=analyzing')->assertViewHas('notices', fn ($rows) => $rows->total() === 0);
        $this->get('/notices?personal_status=new')->assertViewHas('notices', fn ($rows) => $rows->total() === 1);
        $this->patch('/notices/'.$notice->id.'/personal', ['action' => 'ignore']);
        $this->get('/notices?collection=ignored')->assertViewHas('notices', fn ($rows) => $rows->total() === 1);
        $this->actingAs($a)->get('/notices?collection=ignored')->assertViewHas('notices', fn ($rows) => $rows->total() === 0);
        $this->get('/notices?collection=saved&personal_status=analyzing&cpv=302313')->assertViewHas('notices', fn ($rows) => $rows->total() === 1);
        $this->get('/notices?personal_status=new')->assertViewHas('notices', fn ($rows) => $rows->total() === 0);
    }

    public function test_admin_does_not_see_other_users_notes_and_legacy_global_data_is_hidden(): void
    {
        $this->withoutVite();
        $admin = User::factory()->admin()->create();
        $notice = Notice::factory()->create();
        $notice->forceFill(['personal_notes' => 'Legacy secret', 'is_saved' => true])->save();
        UserNotice::factory()->for($notice)->create(['personal_notes' => 'Other user secret', 'is_saved' => true]);
        $this->actingAs($admin)->get('/notices/'.$notice->id)->assertDontSee('Legacy secret')->assertDontSee('Other user secret')->assertSee('⭐ Salvează');
        $this->get('/notices?collection=saved')->assertViewHas('notices', fn ($rows) => $rows->total() === 0);
        $this->get('/notices/'.$notice->id.'/suppliers')->assertDontSee('Legacy secret')->assertDontSee('Other user secret');
    }
}
