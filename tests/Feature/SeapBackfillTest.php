<?php

namespace Tests\Feature;

use App\Jobs\RunManualScan;
use App\Models\Notice;
use App\Models\ScanRun;
use App\Models\SeapWorkerBatch;
use App\Services\ScanContext;
use App\Services\SeapBulkSync;
use App\Services\SeapClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SeapBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.seap_worker.server' => true, 'services.seap_worker.token' => str_repeat('test', 10),
            'services.seap_worker.batch_interval_minutes' => 60, 'services.seap.batch_interval_minutes' => 360,
            'services.seap.batch_size' => 10, 'services.seap.fetch_procedure_details' => false, 'services.seap.fetch_adv_details' => false]);
        $this->travelTo(now()->setDate(2026, 9, 20)->setTime(12, 0));
        Http::preventStrayRequests();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        Queue::assertNothingPushed();
        parent::tearDown();
    }

    private function createBackfill(string $family = 'SCN', string $to = '2026-08-01'): ScanRun
    {
        $this->artisan('seap:backfill', ['family' => $family, '--from' => '2026-08-01', '--to' => $to, '--create' => true])->assertSuccessful();

        return ScanRun::where('mode', 'backfill')->latest('id')->firstOrFail();
    }

    private function claim(): array
    {
        return $this->withToken(config('services.seap_worker.token'))->postJson('https://localhost/api/seap-worker/claim')->assertOk()->json();
    }

    private function noWork(): void
    {
        $this->withToken(config('services.seap_worker.token'))->postJson('https://localhost/api/seap-worker/claim')->assertNoContent();
    }

    private function normalRunWithOnlyAdvIncomplete(): ScanRun
    {
        return ScanRun::create(['mode' => 'full', 'status' => 'partial', 'date_from' => '2026-08-01', 'date_to' => '2026-08-01',
            'checkpoint' => ['_worker_ready' => true, '_families' => ['PI', 'CN', 'SCN', 'ADV'],
                '_ranges' => ['PI' => ['from' => '2026-08-01', 'to' => '2026-08-01'],
                    'CN' => ['from' => '2026-08-01', 'to' => '2026-08-01'], 'SCN' => ['from' => '2026-08-01', 'to' => '2026-08-01'],
                    'ADV' => ['from' => '2026-08-01', 'to' => '2026-08-01']]],
            'results' => ['seap' => ['status' => 'error', 'errors' => ['ADV indisponibil'], 'families' => [
                'PI' => ['status' => 'completed', 'complete' => true, 'errors' => []],
                'CN' => ['status' => 'completed', 'complete' => true, 'errors' => []],
                'SCN' => ['status' => 'completed', 'complete' => true, 'errors' => []],
                'ADV' => ['status' => 'error', 'complete' => false, 'errors' => ['Workerul SEAP s-a oprit: unavailable.']],
            ]]]]);
    }

    private function upload(array $batch, array $items = [], string $status = 'ok', ?int $total = null): array
    {
        return $this->withToken(config('services.seap_worker.token'))->postJson('https://localhost/api/seap-worker/batches/'.$batch['batch_id'],
            ['family' => $batch['request']['family'], 'status' => $status, 'data' => $status === 'ok' ? ['total' => $total ?? count($items), 'items' => $items] : []])
            ->assertOk()->json();
    }

    private function scn(int $id, string $title = 'Licitatie SCN'): array
    {
        return ['rfqInvitationId' => $id, 'title' => $title, 'noticeNo' => 'SCN'.$id, 'publicationDate' => '2026-08-01T10:00:00+03:00'];
    }

    public function test_default_preview_is_read_only_and_reports_normal_backlog(): void
    {
        $normal = ScanRun::create(['mode' => 'full', 'status' => 'paused', 'date_from' => '2026-08-16', 'date_to' => '2026-09-15',
            'checkpoint' => ['_next_batch_at' => now()->addHours(6)->toIso8601String()]]);
        $before = $normal->fresh()->toArray();
        $this->artisan('seap:backfill SCN --from=2026-08-01 --to=2026-08-31')->expectsOutputToContain('31 zile calendaristice')
            ->expectsOutputToContain('#'.$normal->id.' paused')->expectsOutputToContain('PREVIEW: nimic creat')->assertSuccessful();
        $this->assertDatabaseCount('scan_runs', 1);
        $this->assertSame($before, $normal->fresh()->toArray());
        $this->assertDatabaseCount('seap_worker_batches', 0);
    }

    #[TestWith(['SCN'])]
    #[TestWith(['PI'])]
    #[TestWith(['CN'])]
    #[TestWith(['ADV'])]
    public function test_explicit_creation_is_idempotent_and_each_family_completes_remotely(string $family): void
    {
        $run = $this->createBackfill($family);
        $this->assertSame('pending', $run->status);
        $this->assertSame([$family], $run->families());
        $this->assertSame($run->id, $this->createBackfill($family)->id);
        $this->assertDatabaseCount('scan_runs', 1);
        $batch = $this->claim();
        $this->assertSame($family, $batch['request']['family']);
        $this->assertSame('2026-08-01', substr($batch['request']['from'], 0, 10));
        $this->assertSame($family === 'ADV' ? 100 : 18, $batch['request']['page_size']);
        $this->upload($batch);
        $this->assertTrue($run->fresh()->seapComplete());
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame('not_run', $run->fresh()->results['ted']['status']);
        $completed = $run->fresh()->toArray();
        $this->assertSame($run->id, $this->createBackfill($family)->id);
        $this->assertSame($completed, $run->fresh()->toArray());
        $this->noWork();
    }

    public function test_pagination_resumes_same_page_after_pause_without_issuing_another_batch(): void
    {
        config(['services.seap.batch_size' => 100]);
        $run = $this->createBackfill();
        $first = $this->claim();
        $this->upload($first, array_map(fn (int $id): array => $this->scn($id), range(1, 18)), total: 20);
        $next = $this->claim();
        $this->assertSame(1, $next['request']['page']);
        $this->artisan('seap:backfill', ['--pause' => $run->id])->assertSuccessful();
        $checkpoint = $run->fresh()->checkpoint;
        $this->travel(12)->hours();
        $this->noWork();
        $this->assertSame($checkpoint, $run->fresh()->checkpoint);
        $this->artisan('seap:backfill', ['--status' => $run->id])->expectsOutputToContain('/1')->assertSuccessful();
        $this->artisan('seap:backfill', ['--resume' => $run->id])->assertSuccessful();
        $this->assertSame($next, $this->claim());
        $this->upload($next, [$this->scn(19), $this->scn(20)], total: 20);
        $this->assertTrue($run->fresh()->seapComplete());
        $this->assertDatabaseCount('notices', 20);
        $this->assertDatabaseCount('seap_worker_batches', 2);
    }

    public function test_empty_days_obey_request_budget_and_resume_next_day(): void
    {
        config(['services.seap.batch_size' => 1]);
        $run = $this->createBackfill(to: '2026-08-02');
        $this->upload($this->claim());
        $this->assertSame('paused', $run->fresh()->status);
        $this->assertSame(1, $run->fresh()->checkpoint['_worker_requests']);
        $this->noWork();
        $this->travel(61)->minutes();
        $next = $this->claim();
        $this->assertSame('2026-08-02', substr($next['request']['from'], 0, 10));
        $this->upload($next);
        $this->assertTrue($run->fresh()->seapComplete());
    }

    public function test_backfills_are_fifo_and_share_cooldown_across_runs(): void
    {
        $first = $this->createBackfill();
        $second = $this->createBackfill('CN');
        $batch = $this->claim();
        $this->assertSame('SCN', $batch['request']['family']);
        $this->upload($batch);
        $this->assertTrue($first->fresh()->seapComplete());
        $this->noWork();
        $this->assertSame('pending', $second->fresh()->status);
        $this->travel(61)->minutes();
        $this->assertSame('CN', $this->claim()['request']['family']);
    }

    public function test_held_backfill_without_outstanding_request_does_not_block_normal_sync(): void
    {
        $run = $this->createBackfill();
        $this->artisan('seap:backfill', ['--pause' => $run->id])->assertSuccessful();
        $held = $run->fresh()->checkpoint;
        $this->claim();
        $normal = ScanRun::where('active_key', 'manual')->sole();
        $this->assertSame('full', $normal->mode);
        $this->assertSame($held, $run->fresh()->checkpoint);
        $this->artisan('seap:backfill', ['--resume' => $run->id])->assertSuccessful();
        $this->assertSame($normal->id, ScanRun::where('active_key', 'manual')->sole()->id);
        $this->assertNull($run->fresh()->active_key);
    }

    public function test_backfill_cannot_run_in_direct_job_even_if_server_mode_is_disabled(): void
    {
        $run = $this->createBackfill();
        $run->update(['status' => 'queued']);
        $before = $run->fresh()->toArray();
        config(['services.seap_worker.server' => false]);
        (new RunManualScan($run->id))->handle(app(ScanContext::class));
        $this->assertSame($before, $run->fresh()->toArray());
    }

    #[TestWith(['TED', '2026-08-01', '2026-08-02'])]
    #[TestWith(['SCN', '2026-08-02', '2026-08-01'])]
    #[TestWith(['SCN', '2026-02-30', '2026-08-01'])]
    #[TestWith(['SCN', '2026-08-01', '2027-01-01'])]
    #[TestWith(['SCN', '', '2026-08-01'])]
    public function test_invalid_creation_cannot_write(string $family, string $from, string $to): void
    {
        $this->artisan('seap:backfill', ['family' => $family, '--from' => $from, '--to' => $to, '--create' => true])->assertFailed();
        $this->assertDatabaseCount('scan_runs', 0);
    }

    public function test_normal_run_and_its_saved_pause_have_priority_without_changes(): void
    {
        $normal = ScanRun::create(['mode' => 'full', 'status' => 'paused', 'date_from' => '2026-08-16', 'date_to' => '2026-09-15',
            'checkpoint' => ['_worker_ready' => true, '_families' => ['SCN'], '_next_batch_at' => now()->addHours(6)->toIso8601String()]]);
        $before = $normal->fresh()->toArray();
        $backfill = $this->createBackfill();
        $this->noWork();
        $this->assertSame($before, $normal->fresh()->toArray());
        $this->assertSame('pending', $backfill->fresh()->status);
        $this->travel(7)->hours();
        $batch = $this->claim();
        $this->assertSame($normal->id, SeapWorkerBatch::findOrFail($batch['batch_id'])->scan_run_id);
        $this->assertSame('pending', $backfill->fresh()->status);
        $this->assertSame($batch, $this->claim());
    }

    public function test_adv_unavailable_cooldown_allows_independent_scn_backfill_after_global_interval(): void
    {
        $normal = $this->normalRunWithOnlyAdvIncomplete();
        $advBatch = $this->claim();
        $this->assertSame($normal->id, SeapWorkerBatch::findOrFail($advBatch['batch_id'])->scan_run_id);
        $this->assertSame('ADV', $advBatch['request']['family']);
        $this->upload($advBatch, status: 'unavailable');

        $normalAfterFailure = $normal->fresh();
        $advErrors = $normalAfterFailure->results['seap']['families']['ADV']['errors'];
        $this->assertSame('partial', $normalAfterFailure->status);
        $this->assertSame('error', $normalAfterFailure->results['seap']['families']['ADV']['status']);
        $this->assertFalse($normalAfterFailure->results['seap']['families']['ADV']['complete']);
        $this->assertSame('ADV', $normalAfterFailure->checkpoint['_next_batch_family']);
        $this->assertSame(now()->addHour()->toIso8601String(), $normalAfterFailure->checkpoint['_next_batch_at']);
        $this->assertSame(now()->addHours(6)->toIso8601String(), $normalAfterFailure->checkpoint['_next_family_batch_at']);

        $backfill = $this->createBackfill();
        $this->noWork();
        $this->assertSame('pending', $backfill->fresh()->status);
        $this->travel(61)->minutes();

        $scnBatch = $this->claim();
        $this->assertSame('SCN', $scnBatch['request']['family']);
        $this->assertSame($backfill->id, SeapWorkerBatch::findOrFail($scnBatch['batch_id'])->scan_run_id);
        $this->upload($scnBatch, [$this->scn(9001)]);

        $this->assertTrue($backfill->fresh()->seapComplete());
        $this->assertSame('partial', $normal->fresh()->status);
        $this->assertSame('error', $normal->fresh()->results['seap']['families']['ADV']['status']);
        $this->assertSame($advErrors, $normal->fresh()->results['seap']['families']['ADV']['errors']);
        $this->assertSame(2, ScanRun::count());
        $this->assertSame(1, Notice::where('seap_id', 'SCN:9001')->count());
        $this->assertDatabaseCount('notice_sources', 1);
    }

    public function test_adv_rate_limit_cooldown_remains_global_for_scn_backfill(): void
    {
        $normal = $this->normalRunWithOnlyAdvIncomplete();
        $backfill = $this->createBackfill();
        $advBatch = $this->claim();
        $this->assertSame('ADV', $advBatch['request']['family']);
        $this->upload($advBatch, status: '429');

        $normalAfterFailure = $normal->fresh();
        $this->assertArrayNotHasKey('_next_batch_family', $normalAfterFailure->checkpoint);
        $this->assertSame(now()->addHours(6)->toIso8601String(), $normalAfterFailure->checkpoint['_next_batch_at']);
        $this->travel(61)->minutes();
        $this->noWork();
        $this->assertSame('pending', $backfill->fresh()->status);
        $this->travel(300)->minutes();

        $retry = $this->claim();
        $this->assertSame('ADV', $retry['request']['family']);
        $this->assertSame($normal->id, SeapWorkerBatch::findOrFail($retry['batch_id'])->scan_run_id);
        $this->assertSame('pending', $backfill->fresh()->status);
        $this->assertNotSame('completed', $normal->fresh()->results['seap']['families']['ADV']['status']);
    }

    public function test_completed_normal_cooldown_then_backfill_and_no_delta_watermark_change(): void
    {
        $normal = ScanRun::create(['mode' => 'full', 'status' => 'completed', 'date_from' => '2026-07-01', 'date_to' => '2026-07-01',
            'checkpoint' => ['_families' => ['SCN'], '_next_batch_at' => now()->addHour()->toIso8601String()],
            'results' => ['seap' => ['status' => 'completed', 'families' => ['SCN' => ['status' => 'completed', 'complete' => true]]]]]);
        $backfill = $this->createBackfill();
        $this->noWork();
        $this->travel(61)->minutes();
        $this->upload($this->claim());
        $this->assertTrue($backfill->fresh()->seapComplete());
        $this->noWork();
        $this->travel(61)->minutes();
        $this->claim();
        $delta = ScanRun::latest('id')->firstOrFail();
        $this->assertSame('full', $delta->mode);
        $this->assertSame($normal->date_to, $delta->checkpoint['_ranges']['SCN']['from']);
    }

    public function test_checkpoint_buffer_and_upload_receipt_survive_offline_laptop(): void
    {
        config(['services.seap.batch_size' => 1]);
        $run = $this->createBackfill();
        $batch = $this->claim();
        $this->travel(12)->hours();
        $this->assertSame($batch, $this->claim());
        $items = [$this->scn(1), $this->scn(2)];
        $receipt = $this->upload($batch, $items);
        $checkpoint = $run->fresh()->checkpoint;
        $this->assertSame(1, $checkpoint['_worker_used']);
        $this->assertSame(now()->addHour()->toIso8601String(), $checkpoint['_next_batch_at']);
        $this->assertSame($receipt, $this->upload($batch, $items));
        $this->assertSame($checkpoint, $run->fresh()->checkpoint);
        $this->noWork();
        $this->travel(12)->hours();
        $this->noWork();
        $this->assertTrue($run->fresh()->seapComplete());
        $this->assertDatabaseCount('notices', 2);
        $this->assertDatabaseCount('notice_revisions', 2);
        $this->assertDatabaseCount('scan_runs', 1);
        $this->artisan('seap:backfill', ['--status' => $run->id])->expectsOutputToContain('completed')
            ->expectsOutputToContain('Verificate: 2; noi: 2; actualizate: 0')->assertSuccessful();
    }

    #[TestWith(['403'])]
    #[TestWith(['429'])]
    #[TestWith(['unavailable'])]
    public function test_remote_errors_preserve_cursor_and_enforce_six_hours(string $status): void
    {
        $run = $this->createBackfill();
        $batch = $this->claim();
        $this->upload($batch, status: $status);
        $this->assertSame('partial', $run->fresh()->status);
        $expectedGlobalCooldown = $status === 'unavailable' ? now()->addHour()->toIso8601String() : now()->addHours(6)->toIso8601String();
        $this->assertSame($expectedGlobalCooldown, $run->fresh()->checkpoint['_next_batch_at']);
        if ($status === 'unavailable') {
            $this->assertSame('SCN', $run->fresh()->checkpoint['_next_batch_family']);
            $this->assertSame(now()->addHours(6)->toIso8601String(), $run->fresh()->checkpoint['_next_family_batch_at']);
        } else {
            $this->assertArrayNotHasKey('_next_batch_family', $run->fresh()->checkpoint);
        }
        $this->artisan('seap:backfill', ['--pause' => $run->id])->assertSuccessful();
        $this->artisan('seap:backfill', ['--resume' => $run->id])->assertSuccessful();
        $this->travel(61)->minutes();
        $this->noWork();
        $this->travel(300)->minutes();
        $this->assertSame($batch['request'], $this->claim()['request']);
    }

    public function test_pause_preserves_outstanding_request_and_accepts_inflight_response(): void
    {
        $run = $this->createBackfill(to: '2026-08-02');
        $batch = $this->claim();
        $this->artisan('seap:backfill', ['--pause' => $run->id])->assertSuccessful();
        $this->noWork();
        $this->assertSame('manual', $run->fresh()->active_key);
        $this->upload($batch, [$this->scn(1)]);
        $this->assertSame('paused', $run->fresh()->status);
        $this->assertTrue($run->fresh()->checkpoint['_backfill_hold']);
        $this->assertNull($run->fresh()->active_key);
        $this->artisan('seap:backfill', ['--resume' => $run->id])->assertSuccessful();
        $this->noWork();
        $this->travel(61)->minutes();
        $next = $this->claim();
        $this->assertSame('2026-08-02', substr($next['request']['from'], 0, 10));
        $this->upload($next);
        $this->assertTrue($run->fresh()->seapComplete());
    }

    public function test_overlapping_intervals_update_existing_notice_without_duplicates(): void
    {
        $this->createBackfill();
        $this->upload($this->claim(), [$this->scn(1)]);
        $noticeId = Notice::sole()->id;
        $this->travel(61)->minutes();
        $second = $this->createBackfill(to: '2026-08-02');
        $this->upload($this->claim(), [$this->scn(1, 'Actualizat')]);
        $this->upload($this->claim());
        $this->assertSame($noticeId, Notice::sole()->id);
        $this->assertSame('Actualizat', Notice::sole()->title);
        $this->assertSame(1, $second->fresh()->checkpoint['SCN']['stats']['updated']);
        $this->assertDatabaseCount('notice_sources', 1);
        $this->assertDatabaseCount('notice_revisions', 2);
    }

    public function test_explicit_backfill_does_not_skip_days_based_on_bulk_coverage(): void
    {
        $this->mock(SeapBulkSync::class, fn ($mock) => $mock->shouldNotReceive('covers'));
        $this->createBackfill();
        $this->assertSame('SCN', $this->claim()['request']['family']);
    }

    public function test_normal_runs_cannot_be_managed_and_nonserver_cannot_create_backfill(): void
    {
        $normal = ScanRun::create(['mode' => 'full', 'status' => 'paused', 'date_from' => '2026-08-01', 'date_to' => '2026-08-01']);
        $before = $normal->fresh()->toArray();
        foreach (['--pause', '--resume', '--status'] as $option) {
            $this->artisan('seap:backfill', [$option => $normal->id])->assertFailed();
        }
        $this->assertSame($before, $normal->fresh()->toArray());
        config(['services.seap_worker.server' => false]);
        $this->artisan('seap:backfill SCN --from=2026-08-01 --to=2026-08-01 --create')->assertFailed();
        $this->assertDatabaseCount('scan_runs', 1);
    }

    public function test_server_direct_seap_access_remains_impossible(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('dezactivat');
        app(SeapClient::class)->getFamilyNotices('SCN', 18, 0, '2026-08-01', '2026-08-01');
    }
}
