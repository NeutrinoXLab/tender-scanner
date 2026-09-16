<?php

namespace Tests\Feature;

use App\Jobs\RunManualScan;
use App\Models\Notice;
use App\Models\ScanRun;
use App\Models\User;
use App\Services\NoticeIngestor;
use App\Services\ScanContext;
use App\Services\TedClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SeapBatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.seap.fetch_procedure_details' => true]);
    }

    private function manual(): ScanRun
    {
        $this->actingAs(User::factory()->create());
        Queue::fake();
        $this->postJson('/scans', ['from' => '2026-09-15', 'to' => '2026-09-15'])->assertAccepted();
        $job = Queue::pushed(RunManualScan::class)->sole();
        $job->handle(app(ScanContext::class));

        return ScanRun::findOrFail($job->runId);
    }

    private function tick(): ScanRun
    {
        Queue::fake();
        $this->artisan('seap:tick')->assertSuccessful();
        $job = Queue::pushed(RunManualScan::class)->sole();
        $this->assertTrue($job->seapOnly);
        $job->handle(app(ScanContext::class));

        return ScanRun::findOrFail($job->runId);
    }

    private function fakeListings(array $counts, array $overrides = []): void
    {
        Http::preventStrayRequests();
        $endpoints = ['PUBLICPINotice' => 'piNoticeId', 'PUBLICCNotice' => 'cNoticeID', 'PublicRFQInvitation' => 'rfqInvitationId'];
        $routes = [];
        foreach ($endpoints as $endpoint => $idField) {
            $total = $counts[$endpoint] ?? 0;
            $routes['*'.$endpoint.'/GetAll/'] = function ($request) use ($total, $idField) {
                $this->assertSame(18, $request['pageSize']);
                $items = collect(range(1, max(1, $total)))->take($total)
                    ->map(fn ($id) => [$idField => $id, 'title' => 'Anunt '.$id, 'contractTitle' => 'Anunt '.$id])->all();

                return Http::response(['total' => $total, 'items' => array_slice($items, $request['pageIndex'] * 18, 18)]);
            };
        }
        Http::fake(array_replace($routes + ['*PUBLICPINotice/getView/*' => Http::response([]), '*ComboPub/*' => Http::response([]), '*NoticeCommon/*' => Http::response([])], $overrides));
    }

    public function test_default_batch_is_100_total_across_families_and_ted_is_unlimited_and_runs_first(): void
    {
        $ted = collect(range(1, 125))->map(fn ($id) => ['publication-number' => $id.'-2026', 'notice-title' => ['ron' => 'TED '.$id]])->all();
        $this->mock(TedClient::class, fn ($mock) => $mock->shouldReceive('search')->once()->andReturn(['totalNoticeCount' => 125, 'notices' => $ted]));
        $this->fakeListings(['PUBLICPINotice' => 17, 'PUBLICCNotice' => 90, 'PublicRFQInvitation' => 5], ['*PUBLICPINotice/getView/*' => function () {
            $this->assertSame(125, Notice::where('source', 'ted')->count());

            return Http::response([]);
        }]);
        $run = $this->manual();
        $this->assertSame('paused', $run->status);
        $this->assertSame(100, $run->counts('seap')['checked']);
        $this->assertSame(125, $run->counts('ted')['checked']);
        $this->assertSame(17, $run->results['seap']['families']['PI']['processed']);
        $this->assertSame(83, $run->results['seap']['families']['CN']['processed']);
        $this->assertSame(0, $run->results['seap']['families']['SCN']['processed']);
        $this->assertSame(4, $run->checkpoint['CN']['cursor']['page']);
        $this->assertCount(18, $run->checkpoint['CN']['cursor']['data']['items']);
        $this->assertSame('completed', $run->results['ted']['status']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'PublicRFQInvitation'));

        Queue::fake();
        $this->artisan('seap:tick')->assertSuccessful();
        Queue::assertNothingPushed();
        $this->postJson('/scans', ['resume_id' => $run->id])->assertAccepted()->assertJsonPath('status', 'paused');
        Queue::assertNothingPushed();

        $this->travel(61)->minutes();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            '*PublicRFQInvitation/GetAll/' => Http::response(['total' => 5, 'items' => collect(range(1, 5))->map(fn ($id) => ['rfqInvitationId' => $id, 'title' => 'SCN '.$id])->all()]),
            '*ComboPub/*' => Http::response([]), '*NoticeCommon/*' => Http::response([]),
        ]);
        $finished = $this->tick();
        $this->assertSame($run->id, $finished->id);
        $this->assertSame('completed', $finished->status);
        $this->assertTrue($finished->seapComplete());
        $this->assertSame(112, $finished->counts('seap')['checked']);
        $this->assertSame(112, $finished->counts('seap')['new']);
        $this->assertDatabaseCount('scan_runs', 1);
        $this->assertDatabaseCount('notices', 237);
        Queue::fake();
        $this->artisan('seap:tick')->assertSuccessful();
        Queue::assertNothingPushed();
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'PUBLICCNotice/GetAll') || str_contains($request->url(), 'PUBLICPINotice'));
    }

    public function test_403_preserves_buffer_and_scheduled_resume_does_not_repeat_listing_or_completed_family(): void
    {
        $this->mock(TedClient::class, fn ($mock) => $mock->shouldReceive('search')->once()->andReturn(['totalNoticeCount' => 0, 'notices' => []]));
        $this->fakeListings(['PUBLICPINotice' => 1, 'PUBLICCNotice' => 2, 'PublicRFQInvitation' => 1], ['*ComboPub/*' => fn ($request) => (string) $request['noticeId'] === '2' ? Http::response('Forbidden', 403) : Http::response([])]);
        $run = $this->manual();
        $this->assertSame('partial', $run->status);
        $this->assertSame(2, $run->counts('seap')['checked']);
        $this->assertSame('not_run', $run->results['seap']['families']['SCN']['status']);
        Http::assertSentCount(8);
        $this->assertCount(2, $run->checkpoint['CN']['cursor']['data']['items']);
        $this->travel(61)->minutes();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            '*ComboPub/*' => Http::response([]), '*NoticeCommon/*' => Http::response([]),
            '*PublicRFQInvitation/GetAll/' => Http::response(['total' => 1, 'items' => [['rfqInvitationId' => 1, 'title' => 'SCN']]]),
        ]);
        $finished = $this->tick();
        $this->assertSame($run->id, $finished->id);
        $this->assertSame('completed', $finished->status);
        $this->assertSame(4, $finished->counts('seap')['checked']);
        Http::assertSentCount(9);
    }

    public function test_configurable_batch_resumes_next_page_without_restarting_the_day(): void
    {
        config(['services.seap.batch_size' => 18]);
        $this->mock(TedClient::class, fn ($mock) => $mock->shouldReceive('search')->once()->andReturn(['totalNoticeCount' => 0, 'notices' => []]));
        $this->fakeListings(['PUBLICPINotice' => 19]);
        $run = $this->manual();
        $this->assertSame(18, $run->counts('seap')['checked']);
        $this->assertSame(1, $run->checkpoint['PI']['cursor']['page']);
        $this->travel(61)->minutes();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            '*PUBLICPINotice/GetAll/' => function ($request) {
                $this->assertSame(1, $request['pageIndex']);

                return Http::response(['total' => 19, 'items' => [['piNoticeId' => 19, 'title' => 'Ultimul']]]);
            },
            '*PUBLICPINotice/getView/19' => Http::response([]),
            '*PUBLICCNotice/GetAll/' => Http::response(['total' => 0, 'items' => []]),
            '*PublicRFQInvitation/GetAll/' => Http::response(['total' => 0, 'items' => []]),
        ]);
        $finished = $this->tick();
        $this->assertSame('completed', $finished->status);
        $this->assertSame(19, $finished->counts('seap')['checked']);
    }

    public function test_new_cycle_uses_last_completed_day_as_overlap_and_tick_does_not_duplicate_active_job(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 17)->setTime(12, 0));
        ScanRun::create(['mode' => 'full', 'status' => 'completed', 'date_from' => '2026-08-16', 'date_to' => '2026-09-15',
            'finished_at' => now(), 'checkpoint' => ['_next_batch_at' => now()->addHour()->toIso8601String()],
            'results' => ['seap' => ['status' => 'completed', 'families' => array_fill_keys(['PI', 'CN', 'SCN'], ['complete' => true])]]]);
        Queue::fake();
        $this->artisan('seap:tick')->assertSuccessful();
        Queue::assertNothingPushed();
        $this->travel(61)->minutes();
        $this->artisan('seap:tick')->assertSuccessful();
        $this->artisan('seap:tick')->assertSuccessful();
        Queue::assertPushed(RunManualScan::class, 1);
        $run = ScanRun::latest('id')->firstOrFail();
        $this->assertSame('2026-09-15', $run->date_from);
        $this->assertSame('2026-09-17', $run->date_to);
        $this->assertNull($run->checkpoint);
    }

    public function test_existing_checkpoint_without_page_cursor_is_resumed_without_losing_completed_items(): void
    {
        $notice = app(NoticeIngestor::class)->ingest('seap', '1', ['title' => 'Importat anterior'], []);
        $run = ScanRun::create(['mode' => 'full', 'status' => 'partial', 'date_from' => '2026-09-14', 'date_to' => '2026-09-15',
            'before_notice_id' => 0, 'checkpoint' => ['PI' => [
                'windows' => ['2026-09-14T00:00:00+03:00'], 'ids' => ['1' => true],
                'outcomes' => ['1' => 'new'], 'totals' => ['2026-09-14T00:00:00+03:00' => 1],
                'stats' => ['processed' => 1, 'new' => 1, 'fetched' => 1, 'reported' => 1],
            ]], 'results' => ['ted' => ['status' => 'completed'], 'seap' => ['status' => 'error']]]);
        app(ScanContext::class)->runId = $run->id;
        app(ScanContext::class)->record('seap', '1', $notice->id, true);
        app(ScanContext::class)->runId = null;
        Http::preventStrayRequests();
        Http::fake([
            '*PUBLICPINotice/GetAll/' => function ($request) {
                $this->assertSame('2026-09-15T00:00:00+03:00', $request['publicationDateFrom']);

                return Http::response(['total' => 2, 'items' => [['piNoticeId' => 1, 'title' => 'Importat anterior'], ['piNoticeId' => 2, 'title' => 'Nou']]]);
            },
            '*PUBLICPINotice/getView/2' => Http::response([]),
            '*PUBLICCNotice/GetAll/' => Http::response(['total' => 0, 'items' => []]),
            '*PublicRFQInvitation/GetAll/' => Http::response(['total' => 0, 'items' => []]),
        ]);
        $finished = $this->tick();
        $this->assertSame($run->id, $finished->id);
        $this->assertSame('completed', $finished->status);
        $this->assertSame(2, $finished->counts('seap')['checked']);
        $this->assertDatabaseCount('notices', 2);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'getView/1'));
    }

    public function test_cancelled_runs_are_not_resumed_and_manual_scan_cannot_replace_an_unfinished_period(): void
    {
        $this->actingAs(User::factory()->create());
        ScanRun::create(['mode' => 'full', 'status' => 'cancelled', 'date_from' => '2026-08-01', 'date_to' => '2026-08-02', 'checkpoint' => ['PI' => ['ids' => []]]]);
        Queue::fake();
        $this->artisan('seap:tick')->assertSuccessful();
        $run = ScanRun::latest('id')->firstOrFail();
        $this->assertSame(2, $run->id);
        Queue::assertPushed(RunManualScan::class, 1);
        $run->update(['status' => 'paused', 'active_key' => null, 'checkpoint' => ['PI' => ['ids' => []]]]);
        Queue::fake();
        $this->postJson('/scans', ['from' => '2026-01-01', 'to' => '2026-01-02'])->assertUnprocessable()->assertJsonValidationErrors('from');
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('scan_runs', 2);
    }

    public function test_empty_history_is_bounded_by_listing_budget_and_keeps_progress(): void
    {
        config(['services.seap.batch_size' => 2]);
        Http::preventStrayRequests();
        Http::fake(['*/GetAll/' => Http::response(['total' => 0, 'items' => []])]);
        $run = $this->tick();
        $this->assertSame('paused', $run->status);
        $this->assertCount(2, $run->checkpoint['PI']['windows']);
        Http::assertSentCount(2);
        $this->assertDatabaseCount('notices', 0);
    }
}
