<?php

namespace Tests\Feature;

use App\Models\Notice;
use App\Services\NoticeIngestor;
use App\Services\SeapImporter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SeapImportTest extends TestCase
{
    use RefreshDatabase;

    private function fakeDetails(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://e-licitatie.ro/api-pub/ComboPub/getNoticeGeneralInfo/*' => Http::response(['tedNoticeNumber' => '538493-2026']),
            'https://e-licitatie.ro/api-pub/NoticeCommon/GetSection1View/*' => Http::response(['noticeEntityAddress' => ['city' => 'Cluj']]),
            'https://e-licitatie.ro/api-pub/NoticeCommon/GetSection21View/*' => Http::response(['shortDescription' => 'Descriere completa']),
            'https://e-licitatie.ro/api-pub/NoticeCommon/GetSection4View/*' => Http::response(['sysProcedureTypeName' => 'Procedura simplificata']),
            'https://e-licitatie.ro/api-pub/PUBLICPINotice/getView/*' => Http::response([]),
        ]);
    }

    public function test_imports_all_families_without_id_collisions_or_automatic_ted_merges(): void
    {
        $this->fakeDetails();
        Http::fake([
            'https://e-licitatie.ro/api-pub/PUBLICPINotice/GetAll/' => Http::response(['total' => 1, 'items' => [['piNoticeId' => 100, 'title' => 'Intentie']]]),
            'https://e-licitatie.ro/api-pub/PUBLICCNotice/GetAll/' => Http::response(['total' => 1, 'items' => [['cNoticeID' => 100, 'contractTitle' => 'Participare']]]),
            'https://e-licitatie.ro/api-pub/PublicRFQInvitation/GetAll/' => Http::response(['total' => 1, 'items' => [['rfqInvitationId' => 100, 'title' => 'Simplificat']]]),
        ]);
        app(NoticeIngestor::class)->ingest('ted', '538493-2026', ['title' => 'TED'], []);

        $this->artisan('seap:scan')->assertSuccessful();
        $this->artisan('seap:scan')->assertSuccessful();

        $this->assertDatabaseCount('notices', 4);
        $this->assertDatabaseCount('notice_revisions', 4);
        $this->assertDatabaseHas('notices', ['seap_id' => '100', 'title' => 'Intentie']);
        $this->assertDatabaseHas('notices', ['seap_id' => 'CN:100', 'title' => 'Participare', 'description' => 'Descriere completa']);
        $this->assertDatabaseHas('notices', ['seap_id' => 'SCN:100', 'title' => 'Simplificat']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'CANotice'));
    }

    public function test_paginates_by_returned_rows_deduplicates_and_counts_actual_updates(): void
    {
        $this->fakeDetails();
        Http::fake(['https://e-licitatie.ro/api-pub/PUBLICCNotice/GetAll/' => Http::sequence()
            ->push(['total' => 3, 'items' => [['cNoticeID' => 100, 'contractTitle' => 'Unu'], ['cNoticeID' => 100, 'contractTitle' => 'Unu']]])
            ->push(['total' => 3, 'items' => [['cNoticeID' => 101, 'contractTitle' => 'Doi']]])
            ->push(['total' => 2, 'items' => [['cNoticeID' => 100, 'contractTitle' => 'Modificat'], ['cNoticeID' => 101, 'contractTitle' => 'Doi']]])]);
        $importer = app(SeapImporter::class);

        $first = $importer->scan(null, null, ['CN'], fn () => null)['CN'];
        $second = $importer->scan(null, null, ['CN'], fn () => null)['CN'];

        $this->assertSame([3, 2, 2, 1], [$first['fetched'], $first['processed'], $first['new'], $first['duplicates']]);
        $this->assertSame([0, 1, 1], [$second['new'], $second['updated'], $second['unchanged']]);
        $this->assertDatabaseCount('notices', 2);
        $this->assertDatabaseCount('notice_revisions', 3);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'PUBLICCNotice/GetAll') && $request['pageIndex'] === 1);
    }

    public function test_splits_date_range_into_inclusive_days(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://e-licitatie.ro/api-pub/PUBLICCNotice/GetAll/' => Http::response(['total' => 0, 'items' => []])]);

        $this->artisan('seap:scan', ['--from' => '2026-09-09', '--to' => '2026-09-10', '--family' => ['CN']])->assertSuccessful();

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request['publicationDateFrom'] === '2026-09-10T00:00:00+03:00'
            && $request['publicationDateTo'] === '2026-09-10T23:59:59+03:00');
        $this->assertDatabaseCount('notices', 0);
    }

    public function test_empty_page_before_reported_total_is_a_failure_not_success(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://e-licitatie.ro/api-pub/PublicRFQInvitation/GetAll/' => Http::response(['total' => 5, 'items' => []])]);

        $this->artisan('seap:scan', ['--family' => ['SCN']])->assertFailed();
        $this->assertDatabaseCount('notices', 0);
    }

    public function test_failed_family_does_not_prevent_other_families(): void
    {
        $this->fakeDetails();
        Http::fake([
            'https://e-licitatie.ro/api-pub/PUBLICCNotice/GetAll/' => Http::response([], 403),
            'https://e-licitatie.ro/api-pub/PublicRFQInvitation/GetAll/' => Http::response(['total' => 1, 'items' => [['rfqInvitationId' => 200, 'title' => 'Disponibil']]]),
        ]);

        $this->artisan('seap:scan', ['--family' => ['CN', 'SCN']])->assertFailed();
        $this->assertDatabaseHas('notices', ['seap_id' => 'SCN:200', 'title' => 'Disponibil']);
    }

    public function test_partial_detail_failure_keeps_listing_and_successful_sections(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://e-licitatie.ro/api-pub/PUBLICCNotice/GetAll/' => Http::response(['total' => 1, 'items' => [['cNoticeID' => 100, 'contractTitle' => 'Disponibil']]]),
            'https://e-licitatie.ro/api-pub/ComboPub/getNoticeGeneralInfo/*' => Http::response(['tedNoticeNumber' => '538493-2026']),
            'https://e-licitatie.ro/api-pub/NoticeCommon/GetSection1View/*' => Http::response([], 403),
            'https://e-licitatie.ro/api-pub/NoticeCommon/GetSection21View/*' => Http::response(['shortDescription' => 'Descriere']),
            'https://e-licitatie.ro/api-pub/NoticeCommon/GetSection4View/*' => Http::response([]),
        ]);

        $this->artisan('seap:scan', ['--family' => ['CN']])->assertFailed();
        $notice = Notice::firstOrFail();
        $this->assertSame('Descriere', $notice->description);
        $this->assertSame('538493-2026', $notice->seap_metadata['ted_references']['general.tedNoticeNumber']);
    }

    public function test_ted_imported_later_does_not_merge_new_seap_families(): void
    {
        $service = app(NoticeIngestor::class);
        $seap = $service->ingest('seap', 'CN:100', ['title' => 'SEAP', 'seap_metadata' => ['family' => 'CN']], ['general' => ['tedNoticeNumber' => '538493-2026']]);
        $ted = $service->ingest('ted', '538493-2026', ['title' => 'TED'], []);

        $this->assertNotSame($seap->id, $ted->id);
        $this->assertDatabaseCount('notices', 2);
    }

    public function test_invalid_date_range_does_not_send_requests(): void
    {
        Http::preventStrayRequests();
        $this->artisan('seap:scan', ['--from' => '2026-09-11', '--to' => '2026-09-10'])->assertFailed();
        Http::assertNothingSent();
    }

    public function test_ip_restriction_stops_all_seap_families_without_more_requests(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://e-licitatie.ro/api-pub/PUBLICPINotice/GetAll/' => Http::response('<title>Acces restricționat - SEAP</title>', 403)]);

        $this->artisan('seap:scan')->assertFailed();

        Http::assertSentCount(1);
        $this->assertDatabaseCount('notices', 0);
    }

    public function test_active_list_excludes_expired_cancelled_and_award_notices_but_preserves_history_and_ted(): void
    {
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-09-11T10:00:00+03:00'));
        $service = app(NoticeIngestor::class);
        $base = ['seap_metadata' => ['category' => 'opportunity'], 'status' => 'In desfasurare', 'notice_type' => 'CN — Anunț de participare'];
        $active = $service->ingest('seap', 'CN:1', $base + ['title' => 'Activ', 'submission_deadline' => '2026-09-20'], []);
        $expired = $service->ingest('seap', 'CN:2', $base + ['title' => 'Expirat', 'submission_deadline' => '2026-09-10'], []);
        $service->ingest('seap', 'CN:3', array_replace($base, ['title' => 'Procedura anulata exemplu', 'status' => 'Anulata', 'submission_deadline' => '2026-10-01']), []);
        $service->ingest('seap', 'CA:4', ['title' => 'Atribuire', 'seap_metadata' => ['category' => 'result']], []);
        $service->ingest('seap', '5', ['title' => 'Intentie', 'seap_metadata' => ['category' => 'planning']], []);
        $service->ingest('ted', '123456-2026', ['title' => 'TED existent', 'submission_deadline' => '2026-09-01'], []);

        $this->get('/notices')->assertViewHas('notices', fn ($notices) => $notices->total() === 3)
            ->assertSee('CN — Anunț de participare')->assertDontSee('Expirat')->assertDontSee('Procedura anulata exemplu')->assertDontSee('Atribuire');
        $this->get('/notices?seap_history=1')->assertViewHas('notices', fn ($notices) => $notices->total() === 5)->assertSee('Expirat')->assertDontSee('Atribuire');
        $this->get('/notices?source=seap')->assertViewHas('notices', fn ($notices) => $notices->total() === 2);
        $this->get('/notices/'.$active->id)->assertSee('CN — Anunț de participare');
        $this->get('/notices/'.$expired->id)->assertSee('Expirat');
    }

    public function test_failed_enrichment_preserves_previous_sections_and_ted_metadata(): void
    {
        $service = app(NoticeIngestor::class);
        $notice = $service->ingest('seap', 'CN:1', ['title' => 'Titlu', 'description' => 'Detaliu',
            'seap_metadata' => ['family' => 'CN', 'ted_references' => ['general.tedNoticeNumber' => '123456-2026']]],
            ['section21' => ['shortDescription' => 'Detaliu'], 'general' => ['tedNoticeNumber' => '123456-2026']]);

        $service->ingest('seap', 'CN:1', ['title' => 'Titlu', 'description' => null,
            'seap_metadata' => ['family' => 'CN', 'ted_references' => []]], ['listing' => ['cNoticeID' => 1]]);

        $notice->refresh();
        $this->assertSame('Detaliu', $notice->description);
        $this->assertSame('Detaliu', $notice->raw_data['section21']['shortDescription']);
        $this->assertSame(['general.tedNoticeNumber' => '123456-2026'], $notice->seap_metadata['ted_references']);
        $this->assertDatabaseCount('notice_revisions', 1);
    }

    public function test_does_not_import_rfq_when_api_returns_it_in_the_scn_family(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://e-licitatie.ro/api-pub/PublicRFQInvitation/GetAll/' => Http::response(['total' => 1,
            'items' => [['rfqInvitationId' => 1, 'title' => 'Invitatie', 'sysNoticeType' => ['id' => 12]]]])]);

        $this->artisan('seap:scan', ['--family' => ['SCN']])->assertFailed();

        $this->assertDatabaseCount('notices', 0);
        Http::assertSentCount(1);
    }

    public function test_saves_incomplete_report_with_unprocessed_rows_on_restriction(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-11 10:00:00'));
        Storage::fake('local');
        Http::preventStrayRequests();
        Http::fake([
            'https://e-licitatie.ro/api-pub/PUBLICCNotice/GetAll/' => Http::response(['total' => 1, 'items' => [['cNoticeID' => 100, 'contractTitle' => 'Unu']]]),
            'https://e-licitatie.ro/api-pub/ComboPub/getNoticeGeneralInfo/*' => Http::response('<title>Acces restricționat</title>', 403),
        ]);

        $this->artisan('seap:scan', ['--family' => ['CN'], '--report' => true])->assertFailed();

        $report = json_decode(Storage::disk('local')->get('seap-scan-20260911-100000.json'), true);
        $this->assertFalse($report['complete']);
        $this->assertSame(1, $report['families']['CN']['fetched']);
        $this->assertSame(1, $report['families']['CN']['unprocessed']);
        $this->assertSame(0, $report['total_processed']);
        Http::assertSentCount(2);
    }
}
