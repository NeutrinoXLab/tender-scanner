<?php

namespace Tests\Feature;

use App\Models\Notice;
use App\Services\NoticeIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NoticePersonalTest extends TestCase
{
    use RefreshDatabase;

    public function test_save_unsave_ignore_restore_are_reversible_and_preserve_the_notice(): void
    {
        $notice = Notice::create(['seap_id' => 'personal', 'title' => 'Calculator', 'seap_metadata' => ['family' => 'PI']]);
        $url = route('notices.personal.update', $notice);

        $this->patch($url, ['action' => 'save'])->assertRedirect(route('notices.show', $notice));
        $this->assertTrue($notice->fresh()->is_saved);
        $this->patch($url, ['action' => 'save'])->assertRedirect();
        $this->patch($url, ['action' => 'unsave'])->assertRedirect();
        $this->assertFalse($notice->fresh()->is_saved);
        $this->patch($url, ['action' => 'save']);
        $this->patch($url, ['action' => 'ignore'])->assertRedirect();
        $this->assertTrue($notice->fresh()->is_ignored);
        $this->assertFalse($notice->fresh()->is_saved);
        $this->patch($url, ['action' => 'restore'])->assertRedirect();
        $this->assertFalse($notice->fresh()->is_ignored);
        $this->patch($url, ['action' => 'ignore']);
        $this->patch($url, ['action' => 'save']);
        $this->assertFalse($notice->fresh()->is_ignored);
        $this->assertDatabaseCount('notices', 1);
        $this->assertSame(['family' => 'PI'], $notice->fresh()->seap_metadata);
    }

    public static function statuses(): array
    {
        return [['new'], ['analyzing'], ['requesting_quote'], ['participating'], ['declined']];
    }

    #[DataProvider('statuses')]
    public function test_updates_personal_status_and_notes_without_changing_procurement_data(string $status): void
    {
        $notice = Notice::create(['seap_id' => 'personal', 'title' => 'Laptop', 'status' => 'Publicat']);

        $this->patch(route('notices.personal.update', $notice), ['action' => 'update', 'personal_status' => $status,
            'personal_notes' => "Am cerut ofertă.\nTermen: 18 septembrie.", 'status' => 'Anulata', 'title' => 'Overwrite'])->assertRedirect();

        $notice->refresh();
        $this->assertSame($status, $notice->personal_status);
        $this->assertSame("Am cerut ofertă.\nTermen: 18 septembrie.", $notice->personal_notes);
        $this->assertSame('Publicat', $notice->status);
        $this->assertSame('Laptop', $notice->title);
    }

    public function test_notes_can_be_edited_cleared_and_are_escaped(): void
    {
        $this->withoutVite();
        $notice = Notice::create(['seap_id' => 'personal', 'title' => 'Laptop']);
        $url = route('notices.personal.update', $notice);
        $this->patch($url, ['action' => 'update', 'personal_status' => 'analyzing', 'personal_notes' => '<script>alert(1)</script>']);
        $this->get(route('notices.show', $notice))->assertSee('<script>alert(1)</script>')->assertDontSee('<script>alert(1)</script>', false);
        $this->patch($url, ['action' => 'update', 'personal_status' => 'analyzing', 'personal_notes' => 'Modificat']);
        $this->assertSame('Modificat', $notice->fresh()->personal_notes);
        $this->patch($url, ['action' => 'update', 'personal_status' => 'analyzing', 'personal_notes' => '']);
        $this->assertNull($notice->fresh()->personal_notes);
    }

    public function test_filters_collections_and_personal_status_including_saved_expired_notices(): void
    {
        $this->withoutVite();
        $notice = Notice::create(['seap_id' => 'saved', 'title' => 'Monitor', 'submission_deadline' => '2020-01-01',
            'seap_metadata' => ['category' => 'opportunity'], 'cpv_code' => '30231300-0']);
        $this->patch(route('notices.personal.update', $notice), ['action' => 'save']);
        $this->patch(route('notices.personal.update', $notice), ['action' => 'update', 'personal_status' => 'analyzing']);
        $ignored = Notice::create(['seap_id' => 'ignored', 'title' => 'Scaun']);
        $this->patch(route('notices.personal.update', $ignored), ['action' => 'ignore']);

        $this->get('/notices?collection=saved&personal_status=analyzing&cpv=302313')->assertViewHas('notices', fn ($rows) => $rows->total() === 1)->assertSee('Monitor');
        $this->get('/notices?collection=ignored')->assertViewHas('notices', fn ($rows) => $rows->total() === 1)->assertSee('Scaun');
        $this->get('/notices?collection=all&seap_history=1')->assertViewHas('notices', fn ($rows) => $rows->total() === 2);
        $this->get('/notices?collection=saved&personal_status=declined')->assertViewHas('notices', fn ($rows) => $rows->total() === 0);
    }

    public function test_reimport_preserves_personal_data_and_does_not_add_source_revisions_for_personal_edits(): void
    {
        $ingestor = app(NoticeIngestor::class);
        $notice = $ingestor->ingest('seap', '100', ['title' => 'Laptop', 'status' => 'Publicat'], []);
        $this->patch(route('notices.personal.update', $notice), ['action' => 'save']);
        $this->patch(route('notices.personal.update', $notice), ['action' => 'update', 'personal_status' => 'analyzing', 'personal_notes' => 'Distribuitor X']);
        $ingestor->ingest('seap', '100', ['title' => 'Laptop', 'status' => 'Publicat'], []);

        $this->assertTrue($notice->fresh()->is_saved);
        $this->assertSame('analyzing', $notice->fresh()->personal_status);
        $this->assertSame('Distribuitor X', $notice->fresh()->personal_notes);
        $this->assertDatabaseCount('notice_revisions', 1);
    }

    public function test_rejects_invalid_personal_updates_and_unknown_notices(): void
    {
        $notice = Notice::create(['seap_id' => 'personal', 'title' => 'Laptop']);
        $url = route('notices.personal.update', $notice);
        $this->patchJson($url, ['action' => 'update', 'personal_status' => 'invalid'])->assertUnprocessable();
        $this->patchJson($url, ['action' => 'delete'])->assertUnprocessable();
        $this->patchJson($url, ['action' => ['save']])->assertUnprocessable();
        $this->patchJson($url, ['action' => 'update', 'personal_status' => ['new']])->assertUnprocessable();
        $this->patchJson($url, ['action' => 'update', 'personal_status' => 'new', 'personal_notes' => str_repeat('x', 20001)])->assertUnprocessable();
        $this->patchJson('/notices/9999/personal', ['action' => 'save'])->assertNotFound();
        $this->getJson('/notices?personal_status=invalid')->assertUnprocessable();
        $this->getJson('/notices?collection=invalid')->assertUnprocessable();
        $this->getJson('/notices?collection[]=saved')->assertUnprocessable();
        $this->getJson('/notices?personal_status[]=new')->assertUnprocessable();
        $this->assertSame('new', $notice->fresh()->personal_status);
    }

    public function test_old_merged_notice_action_updates_canonical_notice(): void
    {
        $canonical = Notice::create(['seap_id' => 'canonical', 'title' => 'Canonical']);
        $old = Notice::create(['seap_id' => 'old', 'title' => 'Old']);
        $old->forceFill(['canonical_notice_id' => $canonical->id])->save();

        $this->patch(route('notices.personal.update', $old), ['action' => 'save'])->assertRedirect(route('notices.show', $canonical));
        $this->assertTrue($canonical->fresh()->is_saved);
    }
}
