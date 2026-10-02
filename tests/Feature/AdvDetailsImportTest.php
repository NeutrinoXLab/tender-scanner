<?php

namespace Tests\Feature;

use App\Models\Notice;
use App\Models\ScanRun;
use App\Models\User;
use App\Services\NoticeIngestor;
use App\Services\SeapClient;
use App\Services\SeapNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AdvDetailsImportTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = str_repeat('adv-test-', 5);
        config(['services.seap_worker.server' => true, 'services.seap_worker.token' => $this->token,
            'services.seap.fetch_adv_details' => false]);
        Http::preventStrayRequests();
        $this->travelTo(now()->setDate(2026, 9, 17)->setTime(12, 0));
    }

    private function listingItem(int $id = 42): array
    {
        return ['advNoticeId' => $id, 'noticeNo' => 'ADV'.$id, 'contractObject' => 'Servicii de test',
            'contractingAuthority' => 'Autoritate test', 'publicationDate' => '2026-09-16T10:00:00+03:00',
            'tenderReceiptDeadline' => '2026-09-25T10:00:00+03:00'];
    }

    private function createRun(string $mode = 'full'): ScanRun
    {
        return ScanRun::create(['mode' => $mode, 'status' => $mode === 'backfill' ? 'pending' : 'queued', 'active_key' => 'manual',
            'date_from' => '2026-09-16', 'date_to' => '2026-09-16', 'before_notice_id' => Notice::max('id') ?? 0,
            'checkpoint' => ['_worker_ready' => true, '_families' => ['ADV'],
                '_ranges' => ['ADV' => ['from' => '2026-09-16', 'to' => '2026-09-16']]]]);
    }

    private function claim(): array
    {
        return $this->withToken($this->token)->postJson('https://localhost/api/seap-worker/claim')->assertOk()->json();
    }

    /** @param array<string, mixed> $payload */
    private function upload(array $batch, array $payload): TestResponse
    {
        return $this->withToken($this->token)->postJson('https://localhost/api/seap-worker/batches/'.$batch['batch_id'], $payload);
    }

    /** @param array<int, array<string, mixed>> $items */
    private function listingPayload(array $items): array
    {
        return ['family' => 'ADV', 'status' => 'ok', 'data' => ['total' => count($items), 'items' => $items]];
    }

    /** @param array<string, mixed> $data */
    private function detailPayload(array $data): array
    {
        return ['family' => 'ADV', 'status' => 'ok', 'data' => $data];
    }

    public function test_listing_and_details_use_the_expected_endpoints_and_one_referer_each(): void
    {
        config(['services.seap_worker.server' => false]);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            'https://e-licitatie.ro/api-pub/AdvNoticeCommon/GetAdvNoticeList/' => Http::response(['total' => 0, 'items' => []]),
            'https://e-licitatie.ro/api-pub/PUBLICAdvNotice/getForView/42' => Http::response([
                'advertisingNoticeId' => 42, 'noticeNo' => 'ADV42', 'contractDescription' => 'Descriere din endpoint',
            ]),
        ]);

        app(SeapClient::class)->getFamilyNotices('ADV', 5, 0, null, null);
        $details = app(SeapClient::class)->getAdvNoticeDetails(42);

        $this->assertSame('Descriere din endpoint', $details['contractDescription']);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://e-licitatie.ro/api-pub/AdvNoticeCommon/GetAdvNoticeList/'
            && $request->hasHeader('Referer', 'https://e-licitatie.ro/pub/notices/adv-notices/')
            && ($request->headers()['Referer'] ?? []) === ['https://e-licitatie.ro/pub/notices/adv-notices/']);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://e-licitatie.ro/api-pub/PUBLICAdvNotice/getForView/42'
            && $request->method() === 'GET'
            && $request->hasHeader('Referer', 'https://e-licitatie.ro/pub/notices/adv-notices/')
            && ($request->headers()['Referer'] ?? []) === ['https://e-licitatie.ro/pub/notices/adv-notices/']);
    }

    public function test_worker_fetches_adv_details_by_default_and_enriches_existing_notice_idempotently(): void
    {
        $item = $this->listingItem();
        $attributes = app(SeapNormalizer::class)->normalize('ADV', $item, []);
        $attributes['currency'] = 'RON';
        $attributes['county'] = 'Iași';
        app(NoticeIngestor::class)->ingest('seap', 'ADV:42', $attributes,
            ['listing' => $item, '_details_complete' => false]);
        $notice = Notice::sole();
        $personal = $notice->personalEntries()->create(['user_id' => User::factory()->create()->id,
            'is_saved' => true, 'is_ignored' => true, 'personal_status' => 'analyzing', 'personal_notes' => 'Păstrează notița']);
        $personalBefore = $personal->fresh()->toArray();
        $run = $this->createRun();

        $listingBatch = $this->claim();
        $this->assertSame('listing', $listingBatch['request']['kind']);
        $this->upload($listingBatch, $this->listingPayload([$item]))->assertOk();

        $detailsBatch = $this->claim();
        $this->assertSame('adv_detail', $detailsBatch['request']['kind']);
        $this->assertSame(42, $detailsBatch['request']['id']);
        $this->upload($detailsBatch, $this->detailPayload(['advertisingNoticeId' => 42, 'noticeNo' => 'ADV42',
            'contractDescription' => 'Descriere completă ADV']))->assertOk();

        $notice->refresh();
        $this->assertSame('ADV:42', $notice->seap_id);
        $this->assertSame('Descriere completă ADV', $notice->description);
        $this->assertSame('RON', $notice->currency);
        $this->assertSame('Iași', $notice->county);
        $this->assertTrue($notice->raw_data['_details_complete']);
        $this->assertSame('Descriere completă ADV', $notice->sources()->sole()->normalized_data['description']);
        $this->assertSame($personalBefore, $personal->fresh()->toArray());
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertDatabaseCount('notices', 1);
        $this->assertDatabaseCount('notice_sources', 1);

        $revisionCount = $notice->revisions()->count();
        $repeat = $this->createRun();
        $againListing = $this->claim();
        $this->upload($againListing, $this->listingPayload([$item]))->assertOk();
        $this->assertSame('completed', $repeat->fresh()->status);
        $this->assertDatabaseCount('notices', 1);
        $this->assertSame($revisionCount, $notice->revisions()->count());
        $this->assertSame($personalBefore, $personal->fresh()->toArray());
    }

    public function test_failed_adv_details_keep_checkpoint_incomplete_and_resume_after_cooldown(): void
    {
        $run = $this->createRun('backfill');
        $listing = $this->listingItem(77);
        $listingBatch = $this->claim();
        $this->upload($listingBatch, $this->listingPayload([$listing]))->assertOk();
        $detailsBatch = $this->claim();
        $this->assertSame('adv_detail', $detailsBatch['request']['kind']);

        $this->upload($detailsBatch, ['family' => 'ADV', 'status' => 'unavailable', 'data' => []])->assertOk();
        $failed = $run->fresh();
        $this->assertSame([], $failed->checkpoint['ADV']['ids']);
        $this->assertSame(['77' => true], $failed->checkpoint['ADV']['pending']);
        $this->assertSame('ADV', $failed->checkpoint['_next_batch_family']);
        $this->assertSame([$listing], $failed->checkpoint['ADV']['cursor']['data']['items']);
        $this->assertDatabaseCount('notices', 0);

        $this->travel(361)->minutes();
        $retry = $this->claim();
        $this->assertSame('adv_detail', $retry['request']['kind']);
        $this->assertSame(77, $retry['request']['id']);
        $this->upload($retry, $this->detailPayload(['advertisingNoticeId' => 77, 'noticeNo' => 'ADV77',
            'contractDescription' => 'Descriere recuperată după pauză']))->assertOk();

        $this->assertSame('Descriere recuperată după pauză', Notice::sole()->description);
        $this->assertSame('ADV:77', Notice::sole()->seap_id);
        $this->assertTrue(Notice::sole()->raw_data['_details_complete']);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertDatabaseCount('notices', 1);
        $this->assertDatabaseCount('notice_sources', 1);
    }
}
