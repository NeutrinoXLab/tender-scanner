<?php

namespace Tests\Feature;

use App\Models\Notice;
use App\Models\NoticeRevision;
use App\Models\User;
use App\Services\NoticeIngestor;
use App\Services\SeapClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NoticeIngestionTest extends TestCase
{
    use RefreshDatabase;

    public function test_source_identity_and_revision_are_idempotent(): void
    {
        $this->actingAs(User::factory()->create());
        $service = app(NoticeIngestor::class);
        $notice = $service->ingest('seap', '100', ['title' => 'Lucrări', 'submission_deadline' => '2026-10-01'], []);
        $service->ingest('seap', '100', ['title' => 'Lucrări', 'submission_deadline' => '2026-10-01'], []);
        $this->assertDatabaseCount('notices', 1);
        $this->assertDatabaseCount('notice_sources', 1);
        $this->assertDatabaseCount('notice_revisions', 1);
        $service->ingest('seap', '100', ['title' => 'Lucrări', 'submission_deadline' => '2026-10-15'], []);
        $this->assertSame('2026-10-15', $notice->fresh()->submission_deadline->toDateString());
        $change = NoticeRevision::latest('id')->first();
        $this->assertSame(['submission_deadline'], $change->changed_fields);
        $this->assertSame('2026-10-01 00:00:00', $change->before_data['submission_deadline']);
    }

    public function test_ted_then_seap_share_the_same_record_using_explicit_reference(): void
    {
        $this->actingAs(User::factory()->create());
        $service = app(NoticeIngestor::class);
        $ted = $service->ingest('ted', '123456-2026', ['title' => 'TED'], [], '100');
        $seap = $service->ingest('seap', '100', ['title' => 'SEAP'], []);
        $this->assertSame($ted->id, $seap->id);
        $this->assertDatabaseCount('notice_sources', 2);
        $this->assertSame('SEAP', $seap->title);
        $service->ingest('ted', '123456-2026', ['title' => 'TED actualizat'], [], '100');
        $this->assertSame('SEAP', $seap->fresh()->title);
    }

    public function test_late_reference_merges_without_deleting_old_record_and_old_url_redirects(): void
    {
        $this->actingAs(User::factory()->create());
        $this->withoutVite();
        $service = app(NoticeIngestor::class);
        $ted = $service->ingest('ted', '123456-2026', ['title' => 'TED'], []);
        $seap = $service->ingest('seap', '100', ['title' => 'SEAP'], []);
        $service->ingest('ted', '123456-2026', ['title' => 'TED'], [], '100');
        $this->assertSame(1, Notice::whereNull('canonical_notice_id')->count());
        $this->assertDatabaseCount('notices', 2);
        $this->assertSame($seap->id, $ted->fresh()->canonical_notice_id);
        $this->assertSame(2, $seap->sources()->count());
        $this->get('/notices/'.$ted->id)->assertRedirect(route('notices.show', $seap));
        $this->get('/notices?source=ted')->assertOk()->assertViewHas('notices', fn ($rows) => $rows->total() === 1);
        $this->get('/notices/'.$seap->id)->assertOk()->assertSee('Istoric')->assertSee('Surse și actualizări');
    }

    public function test_seap_reference_to_ted_is_recognized_and_previous_notice_is_not(): void
    {
        $this->actingAs(User::factory()->create());
        $service = app(NoticeIngestor::class);
        $ted = $service->ingest('ted', '123456-2026', ['title' => 'TED'], []);
        $new = $service->ingest('seap', '200', ['title' => 'Alt anunț'], ['previousNotice' => ['tedNoticeNumber' => '123456-2026']]);
        $this->assertNotSame($ted->id, $new->id);
        $linked = $service->ingest('seap', '100', ['title' => 'SEAP'], ['tedNoticeNumber' => '123456-2026']);
        $this->assertSame($ted->id, $linked->id);
    }

    public function test_missing_fields_do_not_erase_existing_details(): void
    {
        $this->actingAs(User::factory()->create());
        $service = app(NoticeIngestor::class);
        $notice = $service->ingest('seap', '100', ['title' => 'Titlu', 'description' => 'Detalii existente'], []);
        $service->ingest('seap', '100', ['title' => 'Titlu', 'description' => null], []);
        $this->assertSame('Detalii existente', $notice->fresh()->description);
        $this->assertDatabaseCount('notice_revisions', 1);
    }

    public function test_home_opens_the_local_tender_catalog(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get('/')->assertRedirect(route('notices.index'));
    }

    public function test_seap_then_ted_can_link_using_the_seap_publication_reference(): void
    {
        $this->actingAs(User::factory()->create());
        $service = app(NoticeIngestor::class);
        $seap = $service->ingest('seap', '100', ['title' => 'SEAP'], ['tedNoticeNumber' => '123456-2026']);
        $ted = $service->ingest('ted', '123456-2026', ['title' => 'TED'], []);
        $this->assertSame($seap->id, $ted->id);
        $this->assertDatabaseCount('notice_sources', 2);
    }

    public function test_seap_command_uses_shared_import_and_preserves_details_after_network_failure(): void
    {
        $this->actingAs(User::factory()->create());
        $this->mock(SeapClient::class, function ($mock) {
            $mock->shouldReceive('getFamilyNotices')->andReturn(['total' => 0, 'items' => []]);
            $mock->shouldReceive('getOpenNotices')->times(3)->andReturn(['total' => 1, 'items' => [[
                'piNoticeId' => 100, 'noticeNo' => 'PI100', 'title' => 'Catering',
            ]]]);
            $mock->shouldReceive('getNoticeDetails')->once()->andReturn(['piNoticeV2' => ['section2' => [[
                'section2_1' => ['description' => 'Descriere completă'],
            ]]]]);
            $mock->shouldReceive('getNoticeDetails')->twice()->andThrow(new \RuntimeException('Temporar indisponibil'));
        });
        $this->artisan('seap:scan')->assertSuccessful();
        $this->travel(4)->days();
        $this->artisan('seap:scan')->assertFailed();
        $this->artisan('seap:scan')->assertFailed();
        $this->assertDatabaseCount('notice_sources', 1);
        $notice = Notice::first();
        $this->assertSame('Descriere completă', $notice->description);
        $this->assertSame('Descriere completă', data_get($notice->raw_data, 'piNoticeV2.section2.0.section2_1.description'));
        $this->assertDatabaseCount('notice_revisions', 1);
    }
}
