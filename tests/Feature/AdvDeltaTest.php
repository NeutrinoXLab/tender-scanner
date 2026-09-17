<?php

namespace Tests\Feature;

use App\Jobs\RunManualScan;
use App\Models\Notice;
use App\Models\ScanRun;
use App\Models\User;
use App\Services\ScanContext;
use App\Services\ScanDispatcher;
use App\Services\SeapImporter;
use App\Services\TedClient;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AdvDeltaTest extends TestCase
{
    use RefreshDatabase;

    private const LIST_URL = 'https://e-licitatie.ro/api-pub/AdvNoticeCommon/GetAdvNoticeList/';

    private function item(int $id = 100734971): array
    {
        return ['advNoticeId' => $id, 'contractObject' => 'Camere de supraveghere pentru test', 'noticeNo' => 'ADV'.$id,
            'sysNoticeState' => ['id' => 2, 'text' => 'Publicat'], 'sysAcquisitionContractType' => 'Furnizare',
            'sysAdvertisingNotice' => 'Achizitii directe', 'contractingAuthority' => '123456 Autoritate test',
            'cpvCode' => '35125300-2 - Camere video de securitate (Rev.2)', 'publicationDate' => '2026-09-16T20:52:31+03:00',
            'createDate' => '2026-09-16T20:52:22+03:00', 'tenderReceiptDeadline' => '2026-09-21T14:00:00+03:00',
            'estimatedValue' => 25242.42, 'minEstimatedValue' => 18242.94, 'maxEstimatedValue' => 25242.42];
    }

    private function scan(): array
    {
        return app(SeapImporter::class)->scan('2026-09-16', '2026-09-16', ['ADV'], fn () => null)['ADV'];
    }

    private function fakeListing(array $items): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([self::LIST_URL => Http::response(['total' => count($items), 'items' => $items, 'searchTooLong' => false])]);
    }

    public function test_daily_listing_supplies_confirmed_deadline_without_detail_or_document_requests(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 17));
        $this->fakeListing([$this->item()]);
        $this->assertSame('completed', $this->scan()['status']);
        $notice = Notice::sole();
        $this->assertSame('ADV100734971', $notice->seap_official_number);
        $this->assertSame('35125300-2', $notice->cpv_code);
        $this->assertNull($notice->currency);
        $this->assertSame(1, Notice::seapOpportunities()->count());
        $this->assertSame('2026-09-21 14:00', $notice->submission_deadline->format('Y-m-d H:i'));
        Http::assertSent(fn ($request) => $request->url() === self::LIST_URL && $request->method() === 'POST'
            && $request->hasHeader('Referer', 'https://e-licitatie.ro/pub/notices/adv-notices/')
            && $request['pageSize'] === 100 && $request['pageIndex'] === 0
            && $request['publicationDateStart'] === '2026-09-16T00:00:00+03:00'
            && $request['publicationDateEnd'] === '2026-09-16T23:59:59+03:00');
        Http::assertSentCount(1);
        $this->assertSame(1, $this->scan()['unchanged']);
        $this->assertDatabaseCount('notice_revisions', 1);
    }

    public function test_optional_detail_preserves_document_reference_and_is_cached_without_downloading(): void
    {
        config(['services.seap.fetch_adv_details' => true]);
        $this->fakeListing([$this->item()]);
        Http::fake(['*PUBLICAdvNotice/getForView/*' => Http::response([
            'advertisingNoticeId' => 100734971, 'noticeNo' => 'ADV100734971', 'contractAuthorityName' => 'Autoritate test',
            'currency' => ['text' => 'RON'], 'contractDescription' => 'Descriere oficiala test',
            'noticeEntityAddress' => ['nationalIDNumber' => '123456', 'county' => ['text' => 'Iasi'], 'city' => 'Iasi'],
            'documentID' => 42, 'documentName' => 'cerinte.pdf', 'documentUrl' => 'api-pub/files/advnot/test',
        ])]);
        $this->assertSame('completed', $this->scan()['status']);
        $this->assertSame(1, $this->scan()['cached']);
        $notice = Notice::sole();
        $this->assertSame('RON', $notice->currency);
        $this->assertSame('123456', $notice->seap_metadata['authority_cui']);
        $this->assertSame(42, $notice->seap_metadata['document']['id']);
        $this->assertSame('api-pub/files/advnot/test', $notice->seap_metadata['document']['reference']);
        Http::assertSentCount(3);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/files/'));
    }

    public function test_upsert_tracks_changes_and_preserves_personal_state_across_official_identity(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 17));
        $item = $this->item();
        $this->fakeListing([$item, $item]);
        $this->assertSame(1, $this->scan()['duplicates']);
        $notice = Notice::sole();
        $user = User::factory()->create();
        $personal = $notice->personalEntries()->create(['user_id' => $user->id, 'is_saved' => true, 'is_ignored' => true, 'personal_status' => 'analyzing', 'personal_notes' => 'Nu pierde nota']);
        $before = $personal->fresh()->toArray();
        $item['advNoticeId']++;
        $item['tenderReceiptDeadline'] = '2026-09-22T15:00:00+03:00';
        $this->fakeListing([$item]);
        $this->scan();
        $this->assertSame($notice->id, Notice::sole()->id);
        $this->assertSame('2026-09-22', $notice->fresh()->submission_deadline->toDateString());
        $item['sysNoticeState'] = ['id' => 99, 'text' => 'Anulat'];
        $this->fakeListing([$item]);
        $this->scan();
        $this->assertSame(0, Notice::seapOpportunities()->count());
        $this->assertSame($before, $personal->fresh()->toArray());
        $this->assertDatabaseCount('notice_revisions', 3);
        $this->assertDatabaseCount('supplier_history', 0);
    }

    #[TestWith([null])]
    #[TestWith(['2026-09-21'])]
    #[TestWith(['2026-02-30T14:00:00+03:00'])]
    #[TestWith(['2026-09-10T14:00:00+03:00'])]
    public function test_unknown_invalid_or_expired_deadline_removes_adv_from_active_list(?string $deadline): void
    {
        $this->travelTo(now()->setDate(2026, 9, 17));
        $item = $this->item();
        $this->fakeListing([$item]);
        $this->scan();
        $this->assertSame(1, Notice::seapOpportunities()->count());
        $item['tenderReceiptDeadline'] = $deadline;
        $item['finalizationDate'] = '2026-12-31T14:00:00+03:00';
        $this->fakeListing([$item]);
        $this->scan();
        $this->assertSame(0, Notice::seapOpportunities()->count());
        $this->assertDatabaseCount('notices', 1);
        $this->assertDatabaseCount('notice_revisions', 2);
    }

    public function test_pagination_and_batch_checkpoint_do_not_refetch_buffered_pages(): void
    {
        config(['services.seap.batch_size' => 60]);
        $run = ScanRun::create(['mode' => 'full', 'status' => 'running', 'date_from' => '2026-09-16', 'date_to' => '2026-09-16']);
        app(ScanContext::class)->runId = $run->id;
        $items = array_map(fn ($id) => $this->item($id), range(1, 205));
        Http::preventStrayRequests();
        Http::fake([self::LIST_URL => fn ($request) => Http::response(['total' => 205, 'items' => array_slice($items, $request['pageIndex'] * 100, 100)])]);
        foreach ([60, 120, 180, 205] as $count) {
            $stats = $this->scan();
            $this->assertSame($count, $stats['processed']);
            $this->assertSame($count === 205 ? 'completed' : 'paused', $stats['status']);
        }
        Http::assertSentCount(3);
        $this->assertDatabaseCount('notices', 205);
        $this->assertDatabaseCount('notice_revisions', 205);
    }

    #[TestWith([403])]
    #[TestWith([429])]
    public function test_access_restriction_stops_other_families_and_resume_uses_saved_page(int $status): void
    {
        config(['services.seap.fetch_adv_details' => true]);
        $run = ScanRun::create(['mode' => 'full', 'status' => 'running', 'date_from' => '2026-09-16', 'date_to' => '2026-09-16']);
        app(ScanContext::class)->runId = $run->id;
        $this->fakeListing([$this->item(1), $this->item(2)]);
        Http::fake(['*PUBLICAdvNotice/getForView/1' => Http::response(['advertisingNoticeId' => 1, 'noticeNo' => 'ADV1']),
            '*PUBLICAdvNotice/getForView/2' => Http::response('Restricted', $status)]);
        $report = app(SeapImporter::class)->scan('2026-09-16', '2026-09-16', ['ADV', 'CN'], fn () => null);
        $this->assertSame('error', $report['ADV']['status']);
        $this->assertSame('not_run', $report['CN']['status']);
        $this->assertDatabaseCount('notices', 1);
        Http::assertSentCount(3);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*PUBLICAdvNotice/getForView/2' => Http::response(['advertisingNoticeId' => 2, 'noticeNo' => 'ADV2'])]);
        $this->assertSame('completed', $this->scan()['status']);
        $this->assertDatabaseCount('notices', 2);
        Http::assertSentCount(1);
    }

    #[TestWith(['invalid'])]
    #[TestWith([['total' => 1, 'items' => [], 'searchTooLong' => true]])]
    #[TestWith([['total' => 1, 'items' => []]])]
    public function test_invalid_or_truncated_response_never_advances_checkpoint(mixed $response): void
    {
        $run = ScanRun::create(['mode' => 'full', 'status' => 'running', 'date_from' => '2026-09-16', 'date_to' => '2026-09-16']);
        app(ScanContext::class)->runId = $run->id;
        Http::preventStrayRequests();
        Http::fake([self::LIST_URL => Http::response($response)]);
        $this->assertFalse($this->scan()['complete']);
        $this->assertSame([], $run->fresh()->checkpoint['ADV']['windows']);
        $this->assertDatabaseCount('notices', 0);
        Http::assertSentCount(1);
    }

    public function test_new_family_bootstraps_yesterday_and_each_family_keeps_its_own_overlap(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 17)->setTime(12, 0));
        Queue::fake();
        ScanRun::create(['mode' => 'full', 'status' => 'completed', 'date_from' => '2026-08-01', 'date_to' => '2026-09-15',
            'results' => ['seap' => ['status' => 'completed', 'families' => array_fill_keys(['PI', 'CN', 'SCN'], ['complete' => true])]]]);
        $run = app(ScanDispatcher::class)->dispatch([], true);
        $this->assertSame(['from' => '2026-09-15', 'to' => '2026-09-17'], $run->checkpoint['_ranges']['PI']);
        $this->assertSame(['from' => '2026-09-16', 'to' => '2026-09-17'], $run->checkpoint['_ranges']['ADV']);
        $this->assertSame($run->id, app(ScanDispatcher::class)->dispatch([], true)->id);
        Queue::assertPushed(RunManualScan::class, 1);
        $run->update(['status' => 'completed', 'active_key' => null,
            'results' => ['seap' => ['status' => 'completed', 'families' => array_fill_keys($run->families(), ['complete' => true])]]]);
        $this->travel(2)->days();
        $next = app(ScanDispatcher::class)->dispatch([], true);
        $this->assertSame(['from' => '2026-09-17', 'to' => '2026-09-19'], $next->checkpoint['_ranges']['ADV']);
    }

    public function test_manual_probe_calls_adv_alongside_original_families_with_same_period(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake(['*/GetAll/' => Http::response(['total' => 0, 'items' => []]),
            self::LIST_URL => Http::response(['total' => 212, 'items' => [$this->item()]])]);
        $this->actingAs(User::factory()->create())->postJson('/scans', ['mode' => 'probe', 'from' => '2026-09-16', 'to' => '2026-09-17'])->assertAccepted();
        Queue::pushed(RunManualScan::class)->sole()->handle(app(ScanContext::class));
        $run = ScanRun::sole();
        $this->assertSame('tested', $run->status);
        $this->assertSame('probe_ok', $run->results['seap']['families']['ADV']['status']);
        $this->assertSame('not_run', $run->results['ted']['status']);
        Http::assertSent(fn ($request) => $request->url() === self::LIST_URL && $request['pageSize'] === 5
            && $request['publicationDateStart'] === '2026-09-16T00:00:00+03:00'
            && $request['publicationDateEnd'] === '2026-09-17T23:59:59+03:00');
        Http::assertSentCount(4);
        $this->assertDatabaseCount('notices', 0);

    }

    public function test_started_or_cancelled_family_cannot_advance_delta_watermark(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 17)->setTime(12, 0));
        Queue::fake();
        foreach (['failed', 'cancelled'] as $status) {
            ScanRun::create(['mode' => 'full', 'status' => $status, 'date_from' => '2026-09-17', 'date_to' => '2026-09-17',
                'results' => ['seap' => ['status' => 'error', 'families' => ['ADV' => ['complete' => true, 'status' => $status === 'failed' ? 'running' : 'completed']]]]]);
        }
        $run = app(ScanDispatcher::class)->dispatch([], true);
        $this->assertSame('2026-09-16', $run->checkpoint['_ranges']['ADV']['from']);
    }

    #[TestWith([['id' => 0, 'text' => 'Publicat'], 'Achizitii directe'])]
    #[TestWith([['id' => 2, 'text' => 'Publicat'], 'Tip neconfirmat'])]
    public function test_unconfirmed_state_or_advertising_type_is_not_an_active_opportunity(array $state, string $type): void
    {
        $this->travelTo(now()->setDate(2026, 9, 17));
        $item = array_replace($this->item(), ['sysNoticeState' => $state, 'sysAdvertisingNotice' => $type]);
        $this->fakeListing([$item]);
        $this->scan();
        $this->assertSame(0, Notice::seapOpportunities()->count());
        $this->assertSame(1, Notice::seapOpportunities(true)->count());
    }

    public function test_empty_day_is_complete_without_detail_requests(): void
    {
        $this->fakeListing([]);
        $stats = $this->scan();
        $this->assertTrue($stats['complete']);
        $this->assertSame(0, $stats['processed']);
        Http::assertSentCount(1);
    }

    public function test_deadline_offset_is_normalized_to_bucharest_and_exact_expiry_is_inactive(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-21T14:00:00+03:00'));
        $item = array_replace($this->item(), ['tenderReceiptDeadline' => '2026-09-21T11:00:00Z']);
        $this->fakeListing([$item]);
        $this->scan();
        $this->assertSame('2026-09-21 14:00:00', Notice::sole()->getRawOriginal('submission_deadline'));
        $this->assertSame(0, Notice::seapOpportunities()->count());
    }

    public function test_manual_full_scan_imports_four_distinct_families_with_colliding_internal_ids(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake([
            '*PUBLICPINotice/GetAll/' => Http::response(['total' => 1, 'items' => [['piNoticeId' => 1, 'title' => 'PI test']]]),
            '*PUBLICPINotice/getView/1' => Http::response([]),
            '*PUBLICCNotice/GetAll/' => Http::response(['total' => 1, 'items' => [['cNoticeID' => 1, 'contractTitle' => 'CN test']]]),
            '*PublicRFQInvitation/GetAll/' => Http::response(['total' => 1, 'items' => [['rfqInvitationId' => 1, 'title' => 'SCN test']]]),
            self::LIST_URL => Http::response(['total' => 1, 'items' => [$this->item(1)]]),
        ]);
        $this->mock(TedClient::class, fn ($mock) => $mock->shouldReceive('search')->once()->andReturn(['totalNoticeCount' => 0, 'notices' => []]));
        $this->actingAs(User::factory()->create())->postJson('/scans', ['from' => '2026-09-16', 'to' => '2026-09-16'])->assertAccepted();
        Queue::pushed(RunManualScan::class)->sole()->handle(app(ScanContext::class));
        $run = ScanRun::sole();
        $this->assertSame('completed', $run->status);
        $this->assertTrue($run->seapComplete());
        foreach (['PI', 'CN', 'SCN', 'ADV'] as $family) {
            $this->assertSame(1, $run->results['seap']['families'][$family]['processed']);
        }
        $this->assertEqualsCanonicalizing(['1', 'CN:1', 'SCN:1', 'ADV:1'], Notice::pluck('seap_id')->all());
        Http::assertSentCount(5);
        $this->withoutVite();
        $this->get('/notices')->assertSee('ADV:')->assertSee('Total SEAP: 4 verificate');
    }
}
