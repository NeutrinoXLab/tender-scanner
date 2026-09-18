<?php

namespace Tests\Feature;

use App\Models\Notice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NoticePresentationTest extends TestCase
{
    use RefreshDatabase;

    public static function deadlines(): array
    {
        return [
            ['2026-09-17 14:59:00', 'expired'],
            ['2026-09-17 15:00:00', 'expired'],
            ['2026-09-17 15:01:00', 'soon'],
            ['2026-09-24 15:00:00', 'soon'],
            ['2026-09-24 15:01:00', 'normal'],
            [null, 'unknown'],
        ];
    }

    #[DataProvider('deadlines')]
    public function test_deadline_labels_use_bucharest_wall_time(?string $deadline, string $state): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-17 12:00:00', 'UTC'));
        $notice = new Notice(['submission_deadline' => $deadline]);
        $html = Blade::render('<x-notice-deadline :notice="$notice" />', compact('notice'));
        $this->assertStringContainsString('deadline-'.$state, $html);
        if ($deadline === null) {
            $this->assertStringContainsString('Necomunicat', $html);
        } else {
            $this->assertStringContainsString($notice->submission_deadline->format('d.m.Y H:i'), $html);
        }
    }

    public function test_cards_offer_existing_personal_actions_and_keep_other_users_notes_private(): void
    {
        $this->withoutVite();
        $user = User::factory()->create();
        $other = User::factory()->create();
        $notice = Notice::create(['source' => 'ted', 'ted_id' => 'presentation', 'title' => 'Furnizare echipamente', 'county' => 'Cluj']);
        $notice->personalEntries()->create(['user_id' => $other->id, 'is_saved' => true, 'personal_status' => 'participating', 'personal_notes' => 'Secret personal']);
        $this->actingAs($user)->get(route('notices.index'))->assertOk()
            ->assertSee('Status personal: Nouă')->assertDontSee('Secret personal')
            ->assertSee(route('notices.suppliers', $notice), false)
            ->assertSee('name="action" value="save"', false)
            ->assertSee('name="action" value="ignore"', false);
        $this->patch(route('notices.personal.update', $notice), ['action' => 'save'])->assertRedirect(route('notices.show', $notice));
        $this->get(route('notices.index'))->assertOk()->assertSee('name="action" value="unsave"', false);
        $this->get(route('notices.show', $notice))->assertOk()->assertSee('Spațiul meu de lucru')->assertDontSee('Secret personal');
        $this->assertDatabaseHas('user_notices', ['user_id' => $other->id, 'personal_notes' => 'Secret personal']);
    }

    public function test_all_personal_statuses_have_their_real_labels(): void
    {
        foreach (Notice::PERSONAL_STATUSES as $status => $label) {
            $html = Blade::render('<x-personal-badge :status="$status" />', compact('status'));
            $this->assertStringContainsString('status-'.$status, $html);
            $this->assertStringContainsString($label, $html);
        }
    }
}
