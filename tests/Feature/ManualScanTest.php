<?php

namespace Tests\Feature;

use App\Jobs\RunManualScan;
use App\Models\Notice;
use App\Models\ScanRun;
use App\Models\User;
use App\Services\NoticeIngestor;
use App\Services\ScanContext;
use App\Services\SeapClient;
use App\Services\TedClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ManualScanTest extends TestCase
{
    use RefreshDatabase;

    public function test_button_queues_one_run_and_double_click_does_not_start_another(): void
    {
        $this->actingAs(User::factory()->create());
        $this->withoutVite();
        Queue::fake();
        $this->get('/notices')->assertOk()->assertSee('Scanează acum');
        $this->postJson('/scans')->assertAccepted()->assertJsonPath('status', 'queued');
        $this->postJson('/scans')->assertAccepted();
        Queue::assertPushed(RunManualScan::class, 1);
        $this->assertDatabaseCount('scan_runs', 1);
        $this->getJson('/scans/latest')->assertOk()->assertJsonPath('status', 'queued');
    }

    public function test_job_reuses_both_commands_counts_real_changes_and_reimport_is_unchanged(): void
    {
        $this->actingAs(User::factory()->create());
        $this->mock(SeapClient::class, function ($mock) {
            $mock->shouldReceive('getFamilyNotices')->andReturn(['total' => 0, 'items' => []]);
            $mock->shouldReceive('getOpenNotices')->andReturn(['total' => 1, 'items' => [['piNoticeId' => 100, 'title' => 'SEAP']]]);
            $mock->shouldReceive('getNoticeDetails')->once()->andReturn([]);
        });
        $this->mock(TedClient::class, function ($mock) {
            $mock->shouldReceive('search')->twice()->andReturn(['totalNoticeCount' => 1, 'notices' => [[
                'publication-number' => '123456-2026', 'notice-title' => ['ron' => 'TED'],
            ]]]);
        });
        $first = $this->executeScan();
        $this->assertSame('completed', $first->status);
        $this->assertSame(2, $first->counts()['new']);
        $second = $this->executeScan();
        $this->assertSame(0, $second->counts()['new']);
        $this->assertSame(0, $second->counts()['updated']);
        $this->assertSame(2, $second->counts()['unchanged']);
        $this->assertDatabaseCount('notices', 2);
    }

    public function test_seap_failure_does_not_prevent_ted_and_errors_are_visible(): void
    {
        $this->actingAs(User::factory()->create());
        $this->mock(SeapClient::class, function ($mock) {
            $mock->shouldReceive('getFamilyNotices')->andReturn(['total' => 0, 'items' => []]);
            $mock->shouldReceive('getOpenNotices')->once()->andThrow(new \RuntimeException('SEAP indisponibil'));
        });
        $this->mock(TedClient::class, fn ($mock) => $mock->shouldReceive('search')->once()->andReturn(['totalNoticeCount' => 0, 'notices' => []]));
        $run = $this->executeScan();
        $this->assertSame('partial', $run->status);
        $this->assertSame('error', $run->results['seap']['status']);
        $this->assertSame('completed', $run->results['ted']['status']);
        $this->assertNull($run->active_key);
        $this->getJson('/scans/latest')->assertJsonPath('results.seap.errors.0', 'SEAP indisponibil');
    }

    public function test_updated_counts_exclude_identical_reimports_and_numeric_format_changes(): void
    {
        $this->actingAs(User::factory()->create());
        $service = app(NoticeIngestor::class);
        $notice = $service->ingest('seap', '100', ['title' => 'Titlu', 'estimated_value' => '100.00'], []);
        $run = ScanRun::create(['active_key' => 'manual', 'status' => 'running', 'date_from' => '2026-09-01', 'date_to' => '2026-09-10', 'before_notice_id' => $notice->id]);
        $context = app(ScanContext::class);
        $context->runId = $run->id;
        $service->ingest('seap', '100', ['title' => 'Titlu', 'estimated_value' => 100], []);
        $this->assertSame(0, $run->counts()['updated']);
        $service->ingest('seap', '100', ['title' => 'Titlu nou', 'estimated_value' => 100], []);
        $this->assertSame(1, $run->counts()['updated']);
        $context->runId = null;
    }

    public function test_completed_job_is_not_executed_twice_and_failed_job_releases_button(): void
    {
        $this->actingAs(User::factory()->create());
        $run = ScanRun::create(['status' => 'completed', 'date_from' => '2026-09-01', 'date_to' => '2026-09-10']);
        $this->mock(SeapClient::class, fn ($mock) => $mock->shouldNotReceive('getOpenNotices'));
        (new RunManualScan($run->id))->handle(app(ScanContext::class));
        $run->update(['status' => 'running', 'active_key' => 'manual']);
        (new RunManualScan($run->id))->failed(new \RuntimeException('Worker stopped'));
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertNull($run->fresh()->active_key);
    }

    private function executeScan(): ScanRun
    {
        $run = ScanRun::create(['before_notice_id' => Notice::max('id') ?? 0, 'active_key' => 'manual', 'date_from' => '2026-09-01', 'date_to' => '2026-09-10']);
        (new RunManualScan($run->id))->handle(app(ScanContext::class));

        return $run->fresh();
    }
}
