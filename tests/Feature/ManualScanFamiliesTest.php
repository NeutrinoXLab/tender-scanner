<?php

namespace Tests\Feature;

use App\Jobs\RunManualScan;
use App\Models\ScanRun;
use App\Models\User;
use App\Services\ScanContext;
use App\Services\TedClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ManualScanFamiliesTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINTS = [
        'PI' => 'https://e-licitatie.ro/api-pub/PUBLICPINotice/GetAll/',
        'CN' => 'https://e-licitatie.ro/api-pub/PUBLICCNotice/GetAll/',
        'SCN' => 'https://e-licitatie.ro/api-pub/PublicRFQInvitation/GetAll/',
    ];

    private function fakeFamilies(int $total = 1, array $overrides = []): void
    {
        Http::preventStrayRequests();
        Http::fake(array_replace([
            self::ENDPOINTS['PI'] => Http::response(['total' => $total, 'items' => [['piNoticeId' => 100, 'title' => 'PI']]]),
            self::ENDPOINTS['CN'] => Http::response(['total' => $total, 'items' => [['cNoticeID' => 100, 'contractTitle' => 'CN']]]),
            self::ENDPOINTS['SCN'] => Http::response(['total' => $total, 'items' => [['rfqInvitationId' => 100, 'title' => 'SCN', 'sysNoticeType' => ['id' => 17]]]]),
            'https://e-licitatie.ro/api-pub/PUBLICPINotice/getView/*' => Http::response([]),
            'https://e-licitatie.ro/api-pub/ComboPub/getNoticeGeneralInfo/*' => Http::response([]),
            'https://e-licitatie.ro/api-pub/NoticeCommon/*' => Http::response([]),
        ], $overrides));
    }

    private function dispatchFromButton(array $data): ScanRun
    {
        $this->actingAs(User::factory()->create());
        Queue::fake();
        $this->postJson('/scans', $data)->assertAccepted();
        $run = ScanRun::latest('id')->firstOrFail();
        $queued = Queue::pushed(RunManualScan::class)->sole();
        $this->assertSame($run->id, $queued->runId);
        $queued->handle(app(ScanContext::class));

        return $run->fresh();
    }

    public function test_button_calls_all_three_families_and_transmits_identical_daily_date_windows(): void
    {
        $this->fakeFamilies();
        $this->mock(TedClient::class, fn ($mock) => $mock->shouldReceive('search')->once()->andReturn(['totalNoticeCount' => 0, 'notices' => []]));
        $run = $this->dispatchFromButton(['from' => '2026-09-09', 'to' => '2026-09-10']);

        $this->assertSame('completed', $run->status);
        foreach (self::ENDPOINTS as $family => $endpoint) {
            foreach (['2026-09-09', '2026-09-10'] as $day) {
                Http::assertSent(fn (Request $request) => $request->url() === $endpoint && $request->method() === 'POST'
                    && $request['publicationDateFrom'] === $day.'T00:00:00+03:00'
                    && $request['publicationDateTo'] === $day.'T23:59:59+03:00'
                    && $request['pageSize'] === 18 && $request['pageIndex'] === 0);
            }
            $this->assertSame(1, $run->results['seap']['families'][$family]['processed']);
            $this->assertSame(1, $run->results['seap']['families'][$family]['duplicates']);
            $this->assertTrue($run->results['seap']['families'][$family]['complete']);
        }
        $this->assertSame(3, $run->counts('seap')['checked']);
        Http::assertSent(fn (Request $request) => $request->url() === self::ENDPOINTS['SCN'] && $request['sysNoticeType']['id'] === 17);
        $this->withoutVite();
        $this->get('/notices')->assertSee('PI:')->assertSee('CN:')->assertSee('SCN:')->assertSee('Total SEAP: 3 verificate');
    }

    public function test_probe_from_same_button_is_bounded_to_three_listing_requests_and_never_imports(): void
    {
        $this->fakeFamilies(10000);
        $this->mock(TedClient::class, fn ($mock) => $mock->shouldNotReceive('search'));
        $run = $this->dispatchFromButton(['mode' => 'probe', 'from' => '2026-08-03', 'to' => '2026-09-11']);

        Http::assertSentCount(3);
        foreach (self::ENDPOINTS as $family => $endpoint) {
            Http::assertSent(fn (Request $request) => $request->url() === $endpoint && $request['pageSize'] === 1 && $request['pageIndex'] === 0
                && $request['publicationDateFrom'] === '2026-08-03T00:00:00+03:00' && $request['publicationDateTo'] === '2026-09-11T23:59:59+03:00');
            $this->assertSame('probe_ok', $run->results['seap']['families'][$family]['status']);
            $this->assertFalse($run->results['seap']['families'][$family]['complete']);
        }
        $this->assertSame('tested', $run->status);
        $this->assertSame('not_run', $run->results['ted']['status']);
        $this->assertDatabaseCount('notices', 0);
        $this->assertDatabaseCount('scan_run_items', 0);
    }

    public function test_missing_families_cannot_be_reported_as_completed_even_if_command_returns_zero(): void
    {
        Artisan::shouldReceive('call')->with('seap:scan', \Mockery::on(fn ($options) => $options['--family'] === ['PI', 'CN', 'SCN']), \Mockery::any())
            ->once()->andReturnUsing(function (): int {
                app(ScanContext::class)->families = ['PI' => ['status' => 'completed', 'complete' => true]];

                return 0;
            });
        Artisan::shouldReceive('call')->with('ted:scan', \Mockery::any(), \Mockery::any())->once()->andReturn(0);
        $run = $this->dispatchFromButton(['from' => '2026-09-10', 'to' => '2026-09-10']);

        $this->assertSame('partial', $run->status);
        $this->assertSame('error', $run->results['seap']['status']);
        $this->assertSame('unverified', $run->summary()['results']['seap']['families']['CN']['status']);
        $this->assertSame('unverified', $run->summary()['results']['seap']['families']['SCN']['status']);
    }

    public function test_progress_is_persisted_before_the_next_family_finishes(): void
    {
        $this->fakeFamilies(1, [self::ENDPOINTS['CN'] => function () {
            $current = ScanRun::latest('id')->firstOrFail();
            $this->assertSame('running', $current->status);
            $this->assertSame('completed', $current->results['seap']['families']['PI']['status']);
            $this->assertSame('running', $current->results['seap']['families']['CN']['status']);

            return Http::response(['total' => 0, 'items' => []]);
        }]);
        $this->mock(TedClient::class, fn ($mock) => $mock->shouldReceive('search')->once()->andReturn(['totalNoticeCount' => 0, 'notices' => []]));
        $run = $this->dispatchFromButton(['from' => '2026-09-10', 'to' => '2026-09-10']);
        $this->assertSame('completed', $run->status);
    }

    public function test_failed_probe_still_attempts_other_families_unless_access_is_restricted(): void
    {
        $this->fakeFamilies(1, [self::ENDPOINTS['CN'] => Http::response('Unavailable', 400)]);
        $run = $this->dispatchFromButton(['mode' => 'probe', 'from' => '2026-09-10', 'to' => '2026-09-10']);
        $this->assertSame('failed', $run->status);
        $this->assertSame('error', $run->results['seap']['families']['CN']['status']);
        $this->assertSame('probe_ok', $run->results['seap']['families']['SCN']['status']);
        Http::assertSentCount(3);
    }

    public function test_restricted_access_stops_further_requests_and_marks_unexecuted_families(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::ENDPOINTS['PI'] => Http::response('Acces restricționat', 403)]);
        $run = $this->dispatchFromButton(['mode' => 'probe', 'from' => '2026-09-10', 'to' => '2026-09-10']);
        $this->assertSame('failed', $run->status);
        $this->assertSame('not_run', $run->results['seap']['families']['CN']['status']);
        $this->assertSame('not_run', $run->results['seap']['families']['SCN']['status']);
        Http::assertSentCount(1);
    }

    public function test_empty_family_is_confirmed_and_legacy_reports_remain_unverified(): void
    {
        Http::preventStrayRequests();
        Http::fake(array_fill_keys(array_values(self::ENDPOINTS), Http::response(['total' => 0, 'items' => []])));
        $run = $this->dispatchFromButton(['mode' => 'probe', 'from' => '2026-09-10', 'to' => '2026-09-10']);
        Http::assertSentCount(3);
        $this->assertSame('tested', $run->status);
        $this->assertSame(0, $run->results['seap']['families']['SCN']['reported']);
        $legacy = ScanRun::create(['status' => 'completed', 'date_from' => '2026-08-03', 'date_to' => '2026-09-11',
            'results' => ['seap' => ['status' => 'completed']]]);
        $this->assertSame('unverified', $legacy->summary()['status']);
        $this->assertSame('unverified', $legacy->summary()['results']['seap']['families']['CN']['status']);
        $this->assertNull($legacy->summary()['results']['seap']['families']['CN']['processed']);
    }

    public function test_invalid_or_incomplete_period_never_queues_a_scan(): void
    {
        $this->actingAs(User::factory()->create());
        Queue::fake();
        $this->postJson('/scans', ['from' => '2026-09-11', 'to' => '2026-08-03'])->assertUnprocessable();
        $this->postJson('/scans', ['from' => '2026-09-11'])->assertUnprocessable();
        $this->postJson('/scans', ['mode' => 'anything'])->assertUnprocessable();
        Queue::assertNothingPushed();
    }
}
