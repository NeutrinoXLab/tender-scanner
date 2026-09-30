<?php

namespace Tests\Feature;

use App\Models\Notice;
use App\Models\ScanRun;
use App\Services\ScanContext;
use App\Services\SeapImporter;
use App\Services\SeapNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ScnRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_scans_consecutive_scn_days_with_exclusive_midnight_and_paginates_over_18(): void
    {
        Http::preventStrayRequests();
        $requests = [];
        Http::fake(['*PublicRFQInvitation/GetAll/' => function ($request) use (&$requests) {
            $requests[] = $request->data();
            $day = substr($request['publicationDateFrom'], 0, 10);
            $page = $request['pageIndex'];
            $firstId = $day === '2026-09-24' ? 2400 : 2500;
            $ids = $page === 0 ? range($firstId, $firstId + 17) : [$firstId + 18];

            return Http::response(['total' => 19, 'items' => array_map(fn (int $id): array => [
                'rfqInvitationId' => $id, 'noticeNo' => 'SCN'.$id, 'title' => 'SCN '.$id,
            ], $ids)]);
        }]);

        $result = app(SeapImporter::class)->scan('2026-09-24', '2026-09-25', ['SCN'], fn () => null)['SCN'];

        $this->assertSame(38, $result['processed']);
        $this->assertSame(38, Notice::count());
        $this->assertCount(4, $requests);
        $this->assertSame(['2026-09-24 00:00:00', '2026-09-25 00:00:00'], [
            $requests[0]['publicationDateFrom'], $requests[0]['publicationDateTo'],
        ]);
        $this->assertSame(['2026-09-25 00:00:00', '2026-09-26 00:00:00'], [
            $requests[2]['publicationDateFrom'], $requests[2]['publicationDateTo'],
        ]);
        $this->assertSame([0, 1, 0, 1], array_column($requests, 'pageIndex'));
        $this->assertSame(18, $requests[0]['pageSize']);
    }

    public function test_legitimate_empty_scn_listing_completes_without_error(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*PublicRFQInvitation/GetAll/' => Http::response(['total' => 0, 'items' => []])]);

        $result = app(SeapImporter::class)->scan('2026-09-24', '2026-09-24', ['SCN'], fn () => null)['SCN'];

        $this->assertTrue($result['complete']);
        $this->assertSame('completed', $result['status']);
        $this->assertSame(0, $result['reported']);
        $this->assertSame([], $result['errors']);
    }

    public function test_scn_checkpoint_resume_does_not_duplicate_imports(): void
    {
        config(['services.seap.batch_size' => 10, 'services.seap.fetch_procedure_details' => false]);
        Http::preventStrayRequests();
        Http::fake(['*PublicRFQInvitation/GetAll/' => function ($request) {
            $ids = $request['pageIndex'] === 0 ? range(1, 18) : [19];

            return Http::response(['total' => 19, 'items' => array_map(fn (int $id): array => [
                'rfqInvitationId' => $id, 'noticeNo' => 'SCN'.$id, 'title' => 'SCN '.$id,
            ], $ids)]);
        }]);
        $run = ScanRun::create(['mode' => 'backfill', 'status' => 'pending', 'date_from' => '2026-09-24', 'date_to' => '2026-09-24',
            'before_notice_id' => 0, 'checkpoint' => ['_families' => ['SCN'], '_ranges' => ['SCN' => ['from' => '2026-09-24', 'to' => '2026-09-24']]]]);
        $context = app(ScanContext::class);
        $context->runId = $run->id;
        $importer = app(SeapImporter::class);

        $first = $importer->scan($run->date_from, $run->date_to, ['SCN'], fn () => null)['SCN'];
        $this->assertSame('paused', $first['status']);
        $second = $importer->scan($run->date_from, $run->date_to, ['SCN'], fn () => null)['SCN'];

        $this->assertSame(19, $second['processed']);
        $this->assertSame(19, Notice::count());
        $this->assertSame(19, $run->fresh()->checkpoint['SCN']['stats']['processed']);
        $this->assertSame(1, Notice::where('seap_id', 'SCN:19')->count());
        $context->runId = null;
        $context->reset();
    }

    public function test_scn_empty_status_text_uses_only_official_state_keys(): void
    {
        $normalizer = app(SeapNormalizer::class);
        $inProgress = $normalizer->normalize('SCN', ['rfqInvitationId' => 1,
            'sysProcedureState' => ['id' => 2, 'text' => '', 'localeKey' => 'Common.Clasiff.SysProcedureState.InProgress']], []);
        $published = $normalizer->normalize('SCN', ['rfqInvitationId' => 2,
            'sysNoticeState' => ['id' => 2, 'text' => '', 'localeKey' => 'Common.Clasiff.SysNoticeState.Published']], []);
        $unknown = $normalizer->normalize('SCN', ['rfqInvitationId' => 3,
            'sysProcedureState' => ['id' => 999, 'text' => '', 'localeKey' => 'Common.Clasiff.SysProcedureState.Unknown']], []);

        $this->assertSame('În desfășurare', $inProgress['status']);
        $this->assertSame('Publicat', $published['status']);
        $this->assertNull($unknown['status']);
    }

    public function test_scn_recovery_run_is_separate_and_leaves_historical_checkpoints_untouched(): void
    {
        config(['services.seap_worker.server' => true]);
        $first = ScanRun::create(['mode' => 'full', 'status' => 'completed', 'date_from' => '2026-08-01', 'date_to' => '2026-08-02',
            'checkpoint' => ['_families' => ['PI', 'CN', 'SCN', 'ADV'], 'PI' => ['cursor' => ['page' => 3]],
                'CN' => ['windows' => ['historic-cn']], 'SCN' => ['windows' => ['historic-scn']], 'ADV' => ['windows' => ['historic-adv']],
                '_ted_from' => '2026-08-01']]);
        $second = ScanRun::create(['mode' => 'full', 'status' => 'paused', 'date_from' => '2026-08-03', 'date_to' => '2026-08-04',
            'checkpoint' => ['_families' => ['PI', 'CN', 'SCN', 'ADV'], 'PI' => ['cursor' => ['page' => 5]],
                'CN' => ['windows' => ['historic-cn-2']], 'SCN' => ['windows' => ['historic-scn-2']],
                'ADV' => ['windows' => ['historic-adv-2']], '_ted_from' => '2026-08-03']]);
        $firstBefore = $first->fresh()->checkpoint;
        $secondBefore = $second->fresh()->checkpoint;

        $this->artisan('seap:backfill', ['family' => 'SCN', '--from' => '2026-09-24', '--to' => '2026-09-24', '--create' => true])->assertSuccessful();

        $recovery = ScanRun::where('mode', 'backfill')->sole();
        $this->assertSame(['SCN'], $recovery->families());
        $this->assertSame(['from' => '2026-09-24', 'to' => '2026-09-24'], $recovery->checkpoint['_ranges']['SCN']);
        $this->assertSame(0, $recovery->checkpoint['_scn_recovery_baseline']);
        $this->assertSame($firstBefore, $first->fresh()->checkpoint);
        $this->assertSame($secondBefore, $second->fresh()->checkpoint);
        $this->artisan('seap:backfill', ['--status' => $recovery->id])->expectsOutputToContain('SCN existente la începutul recuperării: 0; acum în interval: 0.')
            ->assertSuccessful();
    }
}
