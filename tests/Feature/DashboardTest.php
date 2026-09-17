<?php

namespace Tests\Feature;

use App\Models\Notice;
use App\Models\User;
use App\Models\UserNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_requires_authentication(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_dashboard_counts_real_catalog_and_only_current_users_personal_entries(): void
    {
        Http::preventStrayRequests();
        $this->withoutVite();
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $seap = Notice::create(['source' => 'seap', 'seap_id' => 'dashboard-cn', 'title' => 'Procedură curentă', 'status' => 'Publicat',
            'submission_deadline' => now()->addDays(5), 'seap_metadata' => ['family' => 'CN', 'category' => 'opportunity']]);
        $expired = Notice::create(['source' => 'seap', 'seap_id' => 'dashboard-expired', 'title' => 'Salvată din istoric', 'status' => 'Publicat',
            'submission_deadline' => now()->subDays(5), 'seap_metadata' => ['family' => 'CN', 'category' => 'opportunity']]);
        Notice::create(['source' => 'ted', 'ted_id' => 'dashboard-ted', 'title' => 'Anunț european']);
        Notice::create(['source' => 'ted', 'ted_id' => 'dashboard-alias', 'title' => 'Alias exclus'])->forceFill(['canonical_notice_id' => $seap->id])->save();
        UserNotice::create(['user_id' => $user->id, 'notice_id' => $expired->id, 'is_saved' => true, 'personal_status' => 'analyzing']);
        UserNotice::create(['user_id' => $otherUser->id, 'notice_id' => $seap->id, 'is_saved' => true, 'personal_status' => 'analyzing', 'personal_notes' => 'Notă privată străină']);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertViewHas('sourceCounts', fn ($counts) => (int) $counts['seap'] === 1 && (int) $counts['ted'] === 1)
            ->assertViewHas('savedCount', 1)
            ->assertViewHas('analyzingCount', 1)
            ->assertViewHas('savedNotices', fn ($notices) => $notices->modelKeys() === [$expired->id])
            ->assertSee('Salvată din istoric')
            ->assertDontSee('Alias exclus')
            ->assertDontSee('Notă privată străină')
            ->assertDontSee(route('admin.users.index'), false);
        Http::assertNothingSent();
    }

    public function test_empty_dashboard_and_admin_navigation_render_without_imports(): void
    {
        Http::preventStrayRequests();
        $this->withoutVite();
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('dashboard'))->assertOk()
            ->assertSee('Catalogul este încă gol')
            ->assertSee('Un loc pentru lista ta scurtă')
            ->assertSee(route('admin.users.index'), false)
            ->assertViewHas('savedCount', 0);
        Http::assertNothingSent();
    }
}
