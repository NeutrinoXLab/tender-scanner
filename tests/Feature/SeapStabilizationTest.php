<?php

namespace Tests\Feature;

use App\Jobs\RunManualScan;
use App\Models\Notice;
use App\Models\ScanRun;
use App\Models\User;
use App\Services\ScanContext;
use App\Services\SeapImporter;
use App\Services\TedClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SeapStabilizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.seap.fetch_procedure_details' => true]);
    }

    private function fakeApi(string $title = 'Achizitie', int $total = 1000): void
    {
        Http::preventStrayRequests();
        Http::fake(['*AdvNoticeCommon/GetAdvNoticeList/' => Http::response(['total' => 0, 'items' => []]),
            '*PUBLICPINotice/GetAll/' => Http::response(['total' => $total, 'items' => [['piNoticeId' => 11, 'title' => $title]]]),
            '*PUBLICCNotice/GetAll/' => Http::response(['total' => $total, 'items' => [['cNoticeID' => 11, 'contractTitle' => $title, 'sysNoticeTypeId' => 2, 'sysAcquisitionContractType' => ['id' => 1, 'text' => 'Furnizare']]]]),
            '*PublicRFQInvitation/GetAll/' => Http::response(['total' => $total, 'items' => [['rfqInvitationId' => 11, 'contractTitle' => $title, 'sysNoticeType' => ['id' => 17], 'sysAcquisitionContractType' => ['id' => 2, 'text' => 'Servicii']]]]),
            '*PUBLICPINotice/getView/*' => Http::response(['sectionGeneral' => ['sysAcquisitionContractType' => ['id' => 3, 'text' => 'Lucrari']]]),
            '*ComboPub/getNoticeGeneralInfo/*' => Http::response(['tedNoticeNumber' => '123456-2026']),
            '*NoticeCommon/*' => Http::response([]),
        ]);
    }

    private function dispatch(array $data): ScanRun
    {
        Queue::fake();
        $this->postJson('/scans', $data)->assertAccepted();
        $job = Queue::pushed(RunManualScan::class)->sole();
        $job->handle(app(ScanContext::class));

        return ScanRun::findOrFail($job->runId);
    }

    public function test_real_sample_uses_manual_flow_with_one_import_per_family_and_supports_updates_without_duplicates(): void
    {
        $this->actingAs(User::factory()->create());
        $this->mock(TedClient::class, fn ($mock) => $mock->shouldNotReceive('search'));
        $this->fakeApi();
        $data = ['mode' => 'sample', 'from' => '2026-08-03', 'to' => '2026-09-11'];
        $first = $this->dispatch($data);
        $this->assertSame('tested', $first->status);
        $this->assertSame(3, $first->counts()['new']);
        Http::assertSentCount(13);
        foreach (['PI', 'CN', 'SCN'] as $family) {
            $this->assertSame(1, $first->results['seap']['families'][$family]['processed']);
            $this->assertFalse($first->results['seap']['families'][$family]['complete']);
        }
        $second = $this->dispatch($data);
        $this->assertSame(3, $second->counts()['unchanged']);
        $this->assertDatabaseCount('notices', 3);
        $this->assertDatabaseCount('notice_revisions', 3);
        Http::swap(new Factory);
        $this->fakeApi('Titlu modificat');
        $third = $this->dispatch($data);
        $this->assertSame(3, $third->counts()['updated']);
        $this->assertDatabaseCount('notices', 3);
        $this->assertDatabaseCount('notice_revisions', 6);
        $this->withoutVite();
        $this->get('/notices')->assertSee('Total SEAP: 3 verificate')->assertSee('PI:')->assertSee('CN:')->assertSee('SCN:');
    }

    public function test_incremental_period_uses_only_verified_full_sync_with_overlap_and_honors_explicit_period(): void
    {
        $this->actingAs(User::factory()->create());
        $this->travelTo(now()->setDate(2026, 9, 12));
        Queue::fake();
        $this->postJson('/scans')->assertAccepted()->assertJsonPath('from', '2026-08-13');
        ScanRun::query()->delete();
        ScanRun::create(['mode' => 'full', 'status' => 'completed', 'date_from' => '2026-08-03', 'date_to' => '2026-09-10',
            'results' => ['seap' => ['status' => 'completed', 'families' => array_fill_keys(['PI', 'CN', 'SCN'], ['complete' => true])]]]);
        ScanRun::create(['mode' => 'sample', 'status' => 'tested', 'date_from' => '2026-08-03', 'date_to' => '2026-09-12']);
        $this->postJson('/scans')->assertAccepted()->assertJsonPath('from', '2026-09-10')->assertJsonPath('to', '2026-09-12');
        ScanRun::where('status', 'queued')->delete();
        $this->postJson('/scans', ['from' => '2026-08-03', 'to' => '2026-08-05'])->assertAccepted()
            ->assertJsonPath('from', '2026-08-03')->assertJsonPath('to', '2026-08-05');
    }

    public function test_403_during_details_stops_immediately_and_resume_preserves_items_counts_and_completed_days(): void
    {
        $this->actingAs(User::factory()->create());
        $this->mock(TedClient::class, fn ($mock) => $mock->shouldReceive('search')->once()->andReturn(['totalNoticeCount' => 0, 'notices' => []]));
        Http::preventStrayRequests();
        $lists = 0;
        Http::fake(['*AdvNoticeCommon/GetAdvNoticeList/' => Http::response(['total' => 0, 'items' => []]),
            '*PUBLICPINotice/GetAll/' => function () use (&$lists) {
                $lists++;

                return Http::response(['total' => 1, 'items' => [['piNoticeId' => $lists, 'title' => 'PI '.$lists]]]);
            },
            '*PUBLICPINotice/getView/1' => Http::response([]),
            '*PUBLICPINotice/getView/2' => Http::response('Forbidden', 403),
        ]);
        $run = $this->dispatch(['from' => '2026-09-09', 'to' => '2026-09-10']);
        Http::assertSentCount(4);
        $this->assertSame('partial', $run->status);
        $this->assertSame(1, $run->counts()['new']);
        $this->assertFalse($run->summary()['can_resume']);
        $this->travel(61)->minutes();
        $this->assertTrue($run->fresh()->summary()['can_resume']);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*AdvNoticeCommon/GetAdvNoticeList/' => Http::response(['total' => 0, 'items' => []]),
            '*PUBLICPINotice/GetAll/' => Http::response(['total' => 1, 'items' => [['piNoticeId' => 2, 'title' => 'PI 2']]]),
            '*PUBLICPINotice/getView/2' => Http::response([]),
            '*PUBLICCNotice/GetAll/' => Http::response(['total' => 0, 'items' => []]),
            '*PublicRFQInvitation/GetAll/' => Http::response(['total' => 0, 'items' => []]),
        ]);
        $resumed = $this->dispatch(['resume_id' => $run->id]);
        $this->assertSame($run->id, $resumed->id);
        $this->assertSame('completed', $resumed->status);
        $this->assertSame(2, $resumed->counts()['new']);
        $this->assertSame(2, $resumed->results['seap']['families']['PI']['processed']);
        $this->assertSame(2, $resumed->results['seap']['families']['PI']['reported']);
        $this->assertSame(0, $resumed->results['seap']['families']['PI']['unprocessed']);
        Http::assertSentCount(7);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'getView/1')
            || (str_contains($request->url(), 'PUBLICPINotice/GetAll') && str_starts_with($request['publicationDateFrom'], '2026-09-09')));
    }

    public function test_unchanged_recent_listing_avoids_details_but_expiry_refreshes_them(): void
    {
        $this->fakeApi(total: 1);
        $scan = fn () => app(SeapImporter::class)->scan('2026-09-10', '2026-09-10', ['CN'], fn () => null)['CN'];
        $this->assertSame(1, $scan()['new']);
        Http::assertSentCount(5);
        $this->assertSame(1, $scan()['cached']);
        Http::assertSentCount(6);
        $this->travel(4)->days();
        $this->assertSame(0, $scan()['cached']);
        Http::assertSentCount(11);
    }

    public function test_new_incremental_cycle_does_not_restart_old_active_history(): void
    {
        $this->actingAs(User::factory()->create());
        $this->travelTo(now()->setDate(2026, 9, 12));
        $completed = ['seap' => ['status' => 'completed', 'families' => array_fill_keys(['PI', 'CN', 'SCN'], ['complete' => true])]];
        ScanRun::create(['mode' => 'full', 'status' => 'completed', 'date_from' => '2026-09-07', 'date_to' => '2026-09-11',
            'finished_at' => now()->subDay(), 'checkpoint' => ['_refresh_day' => '2026-08-03'], 'results' => $completed]);
        foreach (['2026-08-03', '2026-08-04'] as $day) {
            Notice::factory()->create(['source' => 'seap', 'status' => 'In desfasurare', 'publication_date' => $day,
                'submission_deadline' => now()->addMonth()]);
        }
        Http::preventStrayRequests();
        Http::fake(['*AdvNoticeCommon/GetAdvNoticeList/' => Http::response(['total' => 0, 'items' => []]), '*/GetAll/' => Http::response(['total' => 0, 'items' => []])]);
        $this->mock(TedClient::class, fn ($mock) => $mock->shouldReceive('search')->once()->andReturn(['totalNoticeCount' => 0, 'notices' => []]));
        $run = $this->dispatch([]);
        $this->assertSame('completed', $run->status);
        $this->assertSame('2026-09-11', $run->date_from);
        $this->assertArrayNotHasKey('_refresh_day', $run->checkpoint);
        foreach (['PUBLICPINotice', 'PUBLICCNotice', 'PublicRFQInvitation'] as $endpoint) {
            $isScn = $endpoint === 'PublicRFQInvitation';
            Http::assertSent(fn ($request) => str_contains($request->url(), $endpoint.'/GetAll/')
                && $request['publicationDateFrom'] === ($isScn ? '2026-09-11 00:00:00' : '2026-09-11T00:00:00+03:00')
                && $request['publicationDateTo'] === ($isScn ? '2026-09-12 00:00:00' : '2026-09-11T23:59:59+03:00'));
        }
        $this->getJson('/scans/latest')->assertJsonPath('results.seap.families.SCN.refresh_day', null);
        Http::assertNotSent(fn ($request) => str_starts_with($request['publicationDateFrom'] ?? $request['publicationDateStart'] ?? '', '2026-08'));
    }

    public function test_sample_never_exceeds_limit_even_when_server_ignores_page_size(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*AdvNoticeCommon/GetAdvNoticeList/' => Http::response(['total' => 0, 'items' => []]),
            '*PUBLICPINotice/GetAll/' => Http::response(['total' => 2, 'items' => [
                ['piNoticeId' => 1, 'title' => 'Unu'], ['piNoticeId' => 2, 'title' => 'Doi'],
            ]]),
            '*PUBLICPINotice/getView/1' => Http::response([]),
        ]);
        $report = app(SeapImporter::class)->scan(null, null, ['PI'], fn () => null, limit: 1)['PI'];
        $this->assertSame(1, $report['processed']);
        $this->assertSame('sample_ok', $report['status']);
        Http::assertSentCount(2);
        $this->assertDatabaseCount('notices', 1);
    }

    public function test_sample_stops_after_one_page_even_if_all_rows_are_duplicates(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*AdvNoticeCommon/GetAdvNoticeList/' => Http::response(['total' => 0, 'items' => []]),
            '*PUBLICPINotice/GetAll/' => Http::response(['total' => 1000, 'items' => [
                ['piNoticeId' => 1, 'title' => 'Unu'], ['piNoticeId' => 1, 'title' => 'Unu'],
            ]]),
            '*PUBLICPINotice/getView/1' => Http::response([]),
        ]);
        $report = app(SeapImporter::class)->scan(null, null, ['PI'], fn () => null, limit: 2)['PI'];
        $this->assertSame(1, $report['processed']);
        $this->assertSame(1, $report['duplicates']);
        $this->assertSame('sample_ok', $report['status']);
        Http::assertSentCount(2);
    }

    public function test_unrecognized_contract_type_is_not_imported_or_used_to_delete_existing_data(): void
    {
        $existing = Notice::factory()->create(['seap_id' => '1', 'title' => 'Pastrat']);
        Http::preventStrayRequests();
        Http::fake(['*AdvNoticeCommon/GetAdvNoticeList/' => Http::response(['total' => 0, 'items' => []]),
            '*PUBLICPINotice/GetAll/' => Http::response(['total' => 1, 'items' => [['piNoticeId' => 1, 'title' => 'Alt tip']]]),
            '*PUBLICPINotice/getView/1' => Http::response(['sectionGeneral' => ['sysAcquisitionContractType' => ['id' => 99]]]),
        ]);
        $report = app(SeapImporter::class)->scan(null, null, ['PI'], fn () => null)['PI'];
        $this->assertSame('error', $report['status']);
        $this->assertSame(1, $report['skipped']);
        $this->assertSame('Pastrat', $existing->fresh()->title);
        $this->assertDatabaseCount('notices', 1);
    }

    public function test_wrong_notice_family_is_rejected_before_detail_requests(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*AdvNoticeCommon/GetAdvNoticeList/' => Http::response(['total' => 0, 'items' => []]), '*PUBLICCNotice/GetAll/' => Http::response(['total' => 1, 'items' => [['cNoticeID' => 12, 'sysNoticeTypeId' => 3]]])]);
        $report = app(SeapImporter::class)->scan(null, null, ['CN'], fn () => null)['CN'];
        $this->assertFalse($report['complete']);
        $this->assertSame(1, $report['skipped']);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('notices', 0);
    }

    public function test_malformed_filters_are_rejected_and_combined_procurement_filters_select_the_matching_notice(): void
    {
        $this->actingAs(User::factory()->create());
        $this->withoutVite();
        $this->getJson('/notices?search[]=bad&min_value=abc&deadline_from=bad')->assertUnprocessable()
            ->assertJsonValidationErrors(['search', 'min_value', 'deadline_from']);
        $this->getJson('/notices?min_value=100&max_value=10&deadline_from=2026-09-20&deadline_to=2026-09-10')
            ->assertUnprocessable()->assertJsonValidationErrors(['max_value', 'deadline_to']);
        $match = Notice::factory()->create(['title' => 'Achizitie calculatoare', 'source' => 'seap', 'cpv_code' => '30200000',
            'county' => 'Cluj', 'estimated_value' => 500, 'submission_deadline' => now()->addDays(5), 'status' => 'Publicat',
            'procedure_type' => 'Procedura simplificata']);
        Notice::factory()->create(['title' => 'Alte produse', 'source' => 'seap', 'county' => 'Bucuresti']);
        $filters = ['search' => 'calculatoare', 'source' => 'seap', 'cpv' => '302', 'county' => 'Cluj', 'min_value' => 100,
            'max_value' => 1000, 'deadline_from' => now()->toDateString(), 'deadline_to' => now()->addDays(7)->toDateString(),
            'procedure_type' => 'Procedura simplificata'];
        $this->get('/notices?'.http_build_query($filters))->assertOk()->assertViewHas('notices',
            fn ($notices) => $notices->total() === 1 && $notices->first()->id === $match->id);
    }
}
