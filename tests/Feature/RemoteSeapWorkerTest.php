<?php

namespace Tests\Feature;

use App\Jobs\RunManualScan;
use App\Models\Notice;
use App\Models\ScanRun;
use App\Models\SeapWorkerBatch;
use App\Models\User;
use App\Services\NoticeIngestor;
use App\Services\ScanContext;
use App\Services\SeapClient;
use App\Services\SeapWorkerClient;
use App\Services\TedClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class RemoteSeapWorkerTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = str_repeat('test-only-', 5);
        config(['services.seap_worker.server' => true, 'services.seap_worker.token' => $this->token]);
        Http::preventStrayRequests();
        $this->travelTo(now()->setDate(2026, 9, 17)->setTime(12, 0));
    }

    private function runFor(array $families = ['ADV']): ScanRun
    {
        return ScanRun::create(['mode' => 'full', 'status' => 'queued', 'active_key' => 'manual',
            'date_from' => '2026-09-16', 'date_to' => '2026-09-16', 'before_notice_id' => Notice::max('id') ?? 0,
            'checkpoint' => ['_worker_ready' => true, '_families' => $families]]);
    }

    private function claim(): array
    {
        return $this->withToken($this->token)->postJson('https://localhost/api/seap-worker/claim')->assertOk()->json();
    }

    private function upload(array $batch, array $payload): TestResponse
    {
        return $this->withToken($this->token)->postJson('https://localhost/api/seap-worker/batches/'.$batch['batch_id'], $payload);
    }

    private function adv(string $title = 'Titlu test', int $id = 1): array
    {
        return ['advNoticeId' => $id, 'noticeNo' => 'ADV'.$id, 'contractObject' => $title,
            'sysNoticeState' => ['id' => 2, 'text' => 'Publicat'], 'sysAdvertisingNotice' => 'Achizitii directe',
            'publicationDate' => '2026-09-16T10:00:00+03:00', 'tenderReceiptDeadline' => '2026-09-25T10:00:00+03:00'];
    }

    private function listing(array $items, string $family = 'ADV', ?int $total = null): array
    {
        return ['family' => $family, 'status' => 'ok', 'data' => ['total' => $total ?? count($items), 'items' => $items]];
    }

    private function cnDetails(int $id = 1, string $noticeNumber = 'CN1', string $description = 'Descriere completă'): array
    {
        return ['family' => 'CN', 'status' => 'ok', 'data' => [(string) $id => [
            'data' => [
                'general' => ['noticeId' => $id],
                'section1' => ['contractingAuthority' => ['name' => 'Autoritate']],
                'section21' => ['noticeNo' => $noticeNumber, 'shortDescription' => $description],
                'section4' => ['procedureType' => ['text' => 'Procedură deschisă']],
            ],
            'errors' => [],
        ]]];
    }

    public function test_endpoint_requires_dedicated_token_https_and_enabled_server(): void
    {
        $this->postJson('https://localhost/api/seap-worker/claim')->assertUnauthorized();
        $this->withToken('wrong')->postJson('https://localhost/api/seap-worker/claim')->assertUnauthorized();
        $this->withToken($this->token)->postJson('http://localhost/api/seap-worker/claim')->assertForbidden();
        $this->actingAs(User::factory()->create())->withToken('')->postJson('https://localhost/api/seap-worker/claim')->assertUnauthorized();
        config(['services.seap_worker.server' => false]);
        $this->withToken($this->token)->getJson('https://localhost/api/seap-worker/ping')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_web_routes_still_require_a_web_login(): void
    {
        $this->withToken($this->token)->getJson('/notices')->assertUnauthorized();
        $this->withToken($this->token)->postJson('/scans')->assertUnauthorized();
    }

    public function test_valid_adv_batch_is_atomic_idempotent_and_checkpoint_stays_central(): void
    {
        $run = $this->runFor();
        $batch = $this->claim();
        $this->assertSame($batch, $this->claim());
        $this->assertDatabaseCount('notices', 0);
        $this->assertSame([], $run->fresh()->checkpoint['ADV']['windows']);
        $payload = $this->listing([$this->adv()]);
        $receipt = $this->upload($batch, $payload)->assertOk()->assertJsonPath('accepted', true)->json();
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame('not_run', $run->fresh()->results['ted']['status']);
        $this->assertSame($receipt, $this->upload($batch, $payload)->assertOk()->json());
        $this->assertDatabaseCount('notices', 1);
        $this->assertDatabaseCount('notice_revisions', 1);
        $this->assertDatabaseCount('supplier_history', 0);
        $this->assertCount(1, $run->fresh()->checkpoint['ADV']['windows']);
        $this->upload($batch, $this->listing([$this->adv('Alt titlu')]))->assertConflict();
        Http::assertNothingSent();
    }

    #[TestWith(['TED'])]
    #[TestWith(['OTHER'])]
    #[TestWith(['CN'])]
    public function test_invalid_or_mismatched_family_cannot_be_uploaded(string $family): void
    {
        $this->runFor();
        $batch = $this->claim();
        $this->upload($batch, $this->listing([], $family))->assertUnprocessable();
        $this->assertDatabaseCount('notices', 0);
        $this->assertNull(SeapWorkerBatch::sole()->response_hash);
    }

    public function test_payload_rejects_arbitrary_attributes_and_oversized_pages(): void
    {
        $this->runFor();
        $batch = $this->claim();
        $this->upload($batch, $this->listing([]) + ['checkpoint' => ['ADV' => 'finished']])->assertUnprocessable();
        $this->upload($batch, $this->listing([['advNoticeId' => 1]]))->assertUnprocessable();
        $this->upload($batch, $this->listing(array_fill(0, 101, $this->adv())))->assertUnprocessable();
        config(['services.seap_worker.max_bytes' => 1024]);
        $this->upload($batch, $this->listing([$this->adv(str_repeat('a', 2000))]))->assertStatus(413);
        $this->assertDatabaseCount('notices', 0);
    }

    public function test_same_notice_is_updated_without_touching_personal_state(): void
    {
        $this->runFor();
        $this->upload($this->claim(), $this->listing([$this->adv()]))->assertOk();
        $notice = Notice::sole();
        $personal = $notice->personalEntries()->create(['user_id' => User::factory()->create()->id,
            'is_saved' => true, 'is_ignored' => true, 'personal_status' => 'analyzing', 'personal_notes' => 'Nota mea']);
        $before = $personal->fresh()->toArray();
        $this->runFor();
        $this->upload($this->claim(), $this->listing([$this->adv('Modificat')]))->assertOk();
        $this->assertSame($notice->id, Notice::sole()->id);
        $this->assertSame('Modificat', Notice::sole()->title);
        $this->assertSame($before, $personal->fresh()->toArray());
        $this->assertDatabaseCount('notice_revisions', 2);
        $this->runFor();
        $this->upload($this->claim(), $this->listing([$this->adv('Modificat')]))->assertOk();
        $this->assertDatabaseCount('notice_revisions', 2);
    }

    public function test_original_importer_handles_pi_cn_scn_and_adv_without_server_http(): void
    {
        $run = $this->runFor(['PI', 'CN', 'SCN', 'ADV']);
        for ($index = 0; $index < 6; $index++) {
            $batch = $this->claim();
            $plan = $batch['request'];
            $payload = match ($plan['kind']) {
                'pi_detail' => ['family' => 'PI', 'status' => 'ok', 'data' => []],
                'procedure_details' => $this->cnDetails(),
                default => match ($plan['family']) {
                    'PI' => $this->listing([['piNoticeId' => 1, 'title' => 'PI']], 'PI'),
                    'CN' => $this->listing([['cNoticeID' => 1, 'contractTitle' => 'CN']], 'CN'),
                    'SCN' => $this->listing([['rfqInvitationId' => 1, 'title' => 'SCN']], 'SCN'),
                    'ADV' => $this->listing([$this->adv()]),
                },
            };
            $this->upload($batch, $payload)->assertOk();
        }
        $this->assertTrue($run->fresh()->seapComplete());
        $this->assertDatabaseCount('notices', 4);
        $this->assertDatabaseCount('notice_revisions', 4);
        $this->assertEqualsCanonicalizing(['1', 'CN:1', 'SCN:1', 'ADV:1'], Notice::pluck('seap_id')->all());
        $this->assertSame('Descriere completă', Notice::where('seap_id', 'CN:1')->sole()->description);
        Http::assertNothingSent();
    }

    public function test_cn_worker_retries_incomplete_details_without_duplicate_or_checkpoint_loss(): void
    {
        $run = $this->runFor(['CN']);
        $listing = [['cNoticeID' => 100, 'contractTitle' => 'Contract nou']];
        $this->upload($this->claim(), $this->listing($listing, 'CN'))->assertOk();

        $detailBatch = $this->claim();
        $this->assertSame('procedure_details', $detailBatch['request']['kind']);
        $this->assertSame($listing, $detailBatch['request']['items']);
        $this->upload($detailBatch, ['family' => 'CN', 'status' => 'ok', 'data' => ['100' => [
            'data' => ['general' => ['noticeId' => 100]],
            'errors' => ['CN 100 section1: indisponibil temporar'],
        ]]])->assertOk();

        $notice = Notice::sole();
        $this->assertFalse($notice->raw_data['_details_complete']);
        $this->assertNull($notice->description);
        $this->assertSame(['100' => true], $run->fresh()->checkpoint['CN']['pending']);
        $this->assertSame([], $run->fresh()->checkpoint['CN']['ids']);
        $personal = $notice->personalEntries()->create(['user_id' => User::factory()->create()->id,
            'is_saved' => true, 'personal_status' => 'analyzing', 'personal_notes' => 'Păstrează']);
        $personalBefore = $personal->fresh()->toArray();

        $this->travel(361)->minutes();
        $retry = $this->claim();
        $this->assertSame('procedure_details', $retry['request']['kind']);
        $this->assertSame($listing, $retry['request']['items']);
        $this->upload($retry, $this->cnDetails(100, 'CN100'))->assertOk();

        $notice->refresh();
        $source = $notice->sources()->where('source', 'seap')->sole();
        $this->assertSame('Descriere completă', $notice->description);
        $this->assertTrue($notice->raw_data['_details_complete']);
        $this->assertTrue($source->payload['_details_complete']);
        $this->assertEqualsCanonicalizing(['general', 'section1', 'section21', 'section4'], array_values(array_intersect(array_keys($notice->raw_data), ['general', 'section1', 'section21', 'section4'])));
        $this->assertSame(hash('sha256', json_encode($source->normalized_data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)), $source->fingerprint);
        $this->assertSame($personalBefore, $personal->fresh()->toArray());
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertDatabaseCount('notices', 1);
        $this->assertDatabaseCount('notice_sources', 1);
    }

    #[TestWith(['403', 60, 360])]
    #[TestWith(['429', 360, 360])]
    #[TestWith(['unavailable', 720, 720])]
    public function test_restriction_preserves_progress_and_resume_waits_for_cooldown(string $status, int $legacyInterval, int $cooldown): void
    {
        config(['services.seap.batch_interval_minutes' => $legacyInterval]);
        $run = $this->runFor(['PI', 'ADV']);
        $this->upload($this->claim(), $this->listing([['piNoticeId' => 1, 'title' => 'PI']], 'PI'))->assertOk();
        $batch = $this->claim();
        $this->upload($batch, ['family' => 'PI', 'status' => $status, 'data' => []])->assertOk();
        $this->assertSame('partial', $run->fresh()->status);
        $this->assertCount(1, $run->fresh()->checkpoint['PI']['cursor']['data']['items']);
        $this->withToken($this->token)->postJson('https://localhost/api/seap-worker/claim')->assertNoContent();
        $this->travel(61)->minutes();
        if ($status === 'unavailable') {
            $otherFamily = $this->claim();
            $this->assertSame('listing', $otherFamily['request']['kind']);
            $this->assertSame('ADV', $otherFamily['request']['family']);
            $this->upload($otherFamily, $this->listing([], 'ADV'))->assertOk();
            $this->assertSame('PI', $run->fresh()->checkpoint['_next_batch_family']);
            $this->withToken($this->token)->postJson('https://localhost/api/seap-worker/claim')->assertNoContent();
            $this->travel(61)->minutes();
            $this->travel($cooldown - 122)->minutes();
        } else {
            $this->withToken($this->token)->postJson('https://localhost/api/seap-worker/claim')->assertNoContent();
            $this->travel($cooldown - 60)->minutes();
        }
        $next = $this->claim();
        $this->assertSame('pi_detail', $next['request']['kind']);
        $this->assertSame(1, $next['request']['id']);
        Http::assertNothingSent();
    }

    public function test_server_enforces_configured_budget_and_retains_remaining_buffer(): void
    {
        config(['services.seap.batch_size' => 2]);
        $run = $this->runFor();
        $this->upload($this->claim(), $this->listing([$this->adv(id: 1), $this->adv(id: 2), $this->adv(id: 3)]))->assertOk();
        $this->assertDatabaseCount('notices', 2);
        $this->assertSame('paused', $run->fresh()->status);
        $this->travel(61)->minutes();
        $this->withToken($this->token)->postJson('https://localhost/api/seap-worker/claim')->assertNoContent();
        $this->assertDatabaseCount('notices', 3);
        $this->assertTrue($run->fresh()->seapComplete());
        Http::assertNothingSent();
    }

    public function test_remote_interval_is_separate_and_existing_deadline_survives_laptop_downtime(): void
    {
        config(['services.seap.batch_size' => 1, 'services.seap.batch_interval_minutes' => 360,
            'services.seap_worker.batch_interval_minutes' => 90]);
        $run = $this->runFor();
        $this->upload($this->claim(), $this->listing([$this->adv(), $this->adv('Second', 2)]))->assertOk();
        $deadline = now()->addMinutes(90)->toIso8601String();
        $checkpoint = $run->fresh()->checkpoint;
        $this->assertSame($deadline, $checkpoint['_next_batch_at']);
        config(['services.seap_worker.batch_interval_minutes' => 60]);
        $this->travel(61)->minutes();
        $this->withToken($this->token)->postJson('https://localhost/api/seap-worker/claim')->assertNoContent();
        $this->assertSame($checkpoint, $run->fresh()->checkpoint);
        $this->travel(12)->hours();
        $this->withToken($this->token)->postJson('https://localhost/api/seap-worker/claim')->assertNoContent();
        $this->assertTrue($run->fresh()->seapComplete());
        $this->assertDatabaseCount('notices', 2);
        $this->assertDatabaseCount('scan_runs', 1);
        Http::assertNothingSent();
    }

    public function test_worker_no_work_and_overlap_exit_cleanly_without_seap(): void
    {
        $url = 'https://worker-test.invalid/'.Str::uuid();
        config(['services.seap_worker.server' => false, 'services.seap_worker.url' => $url]);
        Http::fake([$url.'/api/seap-worker/claim' => Http::response('', 204)]);
        $directory = storage_path('app/private/seap-worker');
        File::ensureDirectoryExists($directory);
        $lock = fopen($directory.'/worker.lock', 'c');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try {
            $this->artisan('seap:worker')->assertSuccessful();
            Http::assertNothingSent();
        } finally {
            fclose($lock);
        }
        $this->artisan('seap:worker')->assertSuccessful();
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === $url.'/api/seap-worker/claim');
    }

    public function test_manual_flow_runs_ted_but_does_not_query_seap_on_central_server(): void
    {
        Queue::fake();
        $this->mock(TedClient::class, fn ($mock) => $mock->shouldReceive('search')->once()->andReturn(['totalNoticeCount' => 0, 'notices' => []]));
        $this->actingAs(User::factory()->create())->postJson('/scans', ['from' => '2026-09-16', 'to' => '2026-09-16'])->assertAccepted();
        Queue::pushed(RunManualScan::class)->sole()->handle(app(ScanContext::class));
        $this->assertSame('completed', ScanRun::sole()->results['ted']['status']);
        $this->assertTrue(ScanRun::sole()->checkpoint['_worker_ready']);
        Http::assertNothingSent();
        $this->assertSame('PI', $this->claim()['request']['family']);
    }

    public function test_direct_seap_calls_are_blocked_on_server(): void
    {
        try {
            app(SeapClient::class)->getOpenNotices();
            $this->fail('Server must not connect to SEAP.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('dezactivat', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_worker_outbox_retries_failed_upload_without_reading_seap_again(): void
    {
        $url = 'https://worker-test.invalid/'.Str::uuid();
        config(['services.seap_worker.server' => false, 'services.seap_worker.url' => $url, 'services.seap_worker.request_limit' => 1]);
        $id = (string) Str::uuid();
        $plan = ['batch_id' => $id, 'request' => ['kind' => 'listing', 'family' => 'ADV', 'page_size' => 100, 'page' => 0, 'from' => '2026-09-16', 'to' => '2026-09-16']];
        $path = storage_path('app/private/seap-worker/'.hash('sha256', $url).'.json');
        Http::fake([$url.'/api/seap-worker/claim' => Http::response($plan),
            '*AdvNoticeCommon/GetAdvNoticeList/' => Http::response($this->listing([$this->adv()])['data']),
            $url.'/api/seap-worker/batches/'.$id => Http::response([], 503)]);
        try {
            $this->artisan('seap:worker')->assertFailed();
            $this->assertFileExists($path);
            $saved = json_decode(File::get($path), true);
            $this->assertSame('ok', $saved['response']['status']);
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake([$url.'/api/seap-worker/batches/'.$id => Http::response(['batch_id' => $id, 'accepted' => true])]);
            $this->artisan('seap:worker')->assertSuccessful();
            Http::assertSentCount(1);
            $this->assertFileDoesNotExist($path);
        } finally {
            File::delete($path);
        }
    }

    public function test_worker_connection_check_never_scans_and_rejects_plain_http(): void
    {
        config(['services.seap_worker.server' => false, 'services.seap_worker.url' => 'https://worker-test.invalid']);
        Http::fake(['https://worker-test.invalid/api/seap-worker/ping' => Http::response(['protocol' => 1, 'ready' => true])]);
        $this->artisan('seap:worker --check')->assertSuccessful();
        Http::assertSentCount(1);
        config(['services.seap_worker.url' => 'http://worker-test.invalid']);
        $this->artisan('seap:worker --check')->assertFailed();
        Http::assertSentCount(1);
    }

    public function test_truncated_listing_cannot_advance_checkpoint(): void
    {
        $run = $this->runFor();
        $payload = $this->listing([$this->adv()]);
        $payload['data']['searchTooLong'] = true;
        $this->upload($this->claim(), $payload)->assertOk();
        $this->assertSame('partial', $run->fresh()->status);
        $this->assertSame([], $run->fresh()->checkpoint['ADV']['windows']);
        $this->assertDatabaseCount('notices', 0);
    }

    public function test_ingestion_failure_preserves_buffer_and_does_not_advance_day(): void
    {
        $this->runFor();
        $batch = $this->claim();
        $this->mock(NoticeIngestor::class, fn ($mock) => $mock->shouldReceive('ingest')->once()->andThrow(new \RuntimeException('Database unavailable')));
        $this->upload($batch, $this->listing([$this->adv()]))->assertOk();
        $this->assertSame([], ScanRun::sole()->checkpoint['ADV']['windows']);
        $this->assertDatabaseCount('notices', 0);
        $this->assertSame('partial', ScanRun::sole()->status);
    }

    public function test_receipt_failure_rolls_back_notices_and_checkpoint_before_safe_retry(): void
    {
        $run = $this->runFor();
        $batch = $this->claim();
        SeapWorkerBatch::updating(fn () => throw new \RuntimeException('Internal sensitive failure'));
        try {
            $this->upload($batch, $this->listing([$this->adv()]))->assertStatus(503)->assertDontSee('sensitive');
            $this->assertDatabaseCount('notices', 0);
            $this->assertDatabaseCount('notice_revisions', 0);
            $this->assertSame([], $run->fresh()->checkpoint['ADV']['windows']);
            $this->assertNull(SeapWorkerBatch::sole()->response_hash);
        } finally {
            SeapWorkerBatch::flushEventListeners();
        }
        $this->upload($batch, $this->listing([$this->adv()]))->assertOk();
        $this->assertDatabaseCount('notices', 1);
        $this->assertDatabaseCount('notice_revisions', 1);
    }

    #[TestWith([403])]
    #[TestWith([429])]
    public function test_worker_reports_restriction_and_stops_without_another_claim(int $code): void
    {
        $url = 'https://worker-test.invalid/'.Str::uuid();
        $id = (string) Str::uuid();
        config(['services.seap_worker.server' => false, 'services.seap_worker.url' => $url]);
        $path = storage_path('app/private/seap-worker/'.hash('sha256', $url).'.json');
        Http::fake([$url.'/api/seap-worker/claim' => Http::response(['batch_id' => $id,
            'request' => ['kind' => 'listing', 'family' => 'ADV', 'page_size' => 100, 'page' => 0, 'from' => '2026-09-16', 'to' => '2026-09-16']]),
            '*AdvNoticeCommon/GetAdvNoticeList/' => Http::response('', $code),
            $url.'/api/seap-worker/batches/'.$id => Http::response(['batch_id' => $id, 'accepted' => true])]);
        try {
            $this->artisan('seap:worker')->assertFailed();
            Http::assertSentCount(3);
            Http::assertSent(fn ($request) => str_contains($request->url(), '/batches/') && $request['status'] === (string) $code);
            $this->assertFileDoesNotExist($path);
        } finally {
            File::delete($path);
        }
    }

    public function test_authenticated_endpoint_is_rate_limited(): void
    {
        for ($index = 0; $index < 120; $index++) {
            $this->withToken($this->token)->getJson('https://localhost/api/seap-worker/ping')->assertOk();
        }
        $this->withToken($this->token)->getJson('https://localhost/api/seap-worker/ping')->assertStatus(429);
        Http::assertNothingSent();
    }

    public function test_sql_errors_do_not_log_worker_payloads_or_tokens(): void
    {
        $run = $this->runFor();
        Log::shouldReceive('warning')->once()->with('Eroare la import', ['scan_id' => $run->id]);
        app(ScanContext::class)->runId = $run->id;
        app(ScanContext::class)->error('SQLSTATE: private payload '.$this->token);
        $this->assertStringNotContainsString($this->token, implode(' ', app(ScanContext::class)->errors));
        app(ScanContext::class)->runId = null;
    }

    public function test_finishing_remote_seap_does_not_hide_a_previous_ted_error(): void
    {
        $run = $this->runFor();
        $run->update(['results' => ['ted' => ['status' => 'error', 'errors' => ['TED indisponibil']]]]);
        $this->upload($this->claim(), $this->listing([$this->adv()]))->assertOk();
        $this->assertTrue($run->fresh()->seapComplete());
        $this->assertSame('partial', $run->fresh()->status);
        $this->assertSame(['TED indisponibil'], $run->fresh()->results['ted']['errors']);
    }

    public function test_legacy_partial_cn_resumes_through_tick_claim_laptop_upload_and_next_claim(): void
    {
        Queue::fake();
        $done = array_fill_keys(range(1, 178), true);
        $outcomes = array_fill_keys(range(1, 178), 'new');
        $items = array_map(fn ($id) => ['cNoticeID' => $id, 'noticeNo' => 'CN'.$id, 'contractTitle' => 'CN '.$id], range(163, 180));
        $windows = array_map(fn ($day) => '2026-08-'.$day.'T00:00:00+03:00', range(16, 19));
        $pi = ['status' => 'completed', 'complete' => true, 'processed' => 17, 'errors' => []];
        $cn = ['status' => 'error', 'complete' => false, 'processed' => 178, 'reported' => 189, 'fetched' => 180,
            'listing_requests' => 10, 'errors' => ['SEAP HTTP 403']];
        $scn = ['status' => 'not_run', 'complete' => false, 'errors' => ['Familie neinterogata: acces restrictionat.']];
        $run = ScanRun::create(['mode' => 'full', 'status' => 'partial', 'active_key' => null,
            'date_from' => '2026-08-16', 'date_to' => '2026-09-15',
            'results' => ['seap' => ['status' => 'error', 'families' => ['PI' => $pi, 'CN' => $cn, 'SCN' => $scn]],
                'ted' => ['status' => 'completed', 'errors' => []]],
            'checkpoint' => ['_next_batch_at' => now()->subMinute()->toIso8601String(),
                'PI' => ['stats' => $pi, 'ids' => array_fill_keys(range(1, 17), true)],
                'CN' => ['stats' => $cn, 'ids' => $done, 'outcomes' => $outcomes, 'windows' => $windows,
                    'totals' => ['2026-08-20T00:00:00+03:00' => 189], 'pending' => [179 => true, 180 => true],
                    'cursor' => ['day' => '2026-08-20T00:00:00+03:00', 'page' => 9, 'received' => 180,
                        'data' => ['total' => 189, 'items' => $items]]],
                'SCN' => ['stats' => $scn]]]);
        $piBefore = $run->checkpoint['PI'];
        $tedBefore = $run->results['ted'];
        $this->artisan('seap:tick')->expectsOutputToContain('pregătit pentru workerul SEAP (fără job Laravel)')->assertSuccessful();
        Queue::assertNothingPushed();
        $batch = $this->claim();
        $this->assertSame('CN', $batch['request']['family']);
        $this->assertSame('procedure_details', $batch['request']['kind']);
        $this->assertSame([['cNoticeID' => 179, 'noticeNo' => 'CN179', 'contractTitle' => 'CN 179']], $batch['request']['items']);
        $this->assertSame('running', $run->fresh()->status);
        Http::assertNothingSent();

        config(['services.seap_worker.server' => false]);
        Http::fake([
            '*ComboPub/getNoticeGeneralInfo/*' => Http::response(['noticeId' => 179]),
            '*NoticeCommon/GetSection1View/*' => Http::response(['contractingAuthority' => ['name' => 'Autoritate']]),
            '*NoticeCommon/GetSection21View/*' => Http::response(['noticeNo' => 'CN179', 'shortDescription' => 'Descriere 179']),
            '*NoticeCommon/GetSection4View/*' => Http::response(['procedureType' => ['text' => 'Procedură deschisă']]),
        ]);
        $response = SeapWorkerClient::execute(app(SeapClient::class), $batch['request']);
        Http::assertSentCount(4);
        config(['services.seap_worker.server' => true]);
        $payload = ['family' => 'CN', 'status' => 'ok', 'data' => $response];
        $receipt = $this->upload($batch, $payload)->assertOk()->json();
        $checkpoint = $run->fresh()->checkpoint;
        $this->assertSame(179, $checkpoint['CN']['stats']['processed']);
        $this->assertNotContains('2026-08-20T00:00:00+03:00', $checkpoint['CN']['windows']);
        $this->assertSame(9, $checkpoint['CN']['cursor']['page']);
        $this->assertSame($piBefore, $checkpoint['PI']);
        $this->assertSame($tedBefore, $run->fresh()->results['ted']);
        $this->assertDatabaseCount('notices', 1);
        $this->assertSame('Descriere 179', Notice::sole()->description);
        $this->assertSame($receipt, $this->upload($batch, $payload)->assertOk()->json());
        $this->assertSame($checkpoint, $run->fresh()->checkpoint);
        $next = $this->claim();
        $this->assertNotSame($batch['batch_id'], $next['batch_id']);
        $this->assertSame('procedure_details', $next['request']['kind']);
        $this->assertSame(180, $next['request']['items'][0]['cNoticeID']);
        $this->assertDatabaseCount('scan_runs', 1);
        Http::assertSentCount(4);
    }
}
