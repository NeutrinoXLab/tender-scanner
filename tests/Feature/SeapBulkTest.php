<?php

namespace Tests\Feature;

use App\Jobs\RunManualScan;
use App\Models\Notice;
use App\Models\User;
use App\Models\UserNotice;
use App\Services\BulkSpreadsheetReader;
use App\Services\NoticeIngestor;
use App\Services\ScanContext;
use App\Services\ScanDispatcher;
use App\Services\SeapBulkSync;
use App\Services\SeapImporter;
use App\Services\SupplierHistorySearch;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use ZipArchive;

class SeapBulkTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, string> */
    private function noticeRow(string $number = 'CN1234567'): array
    {
        return ['Autoritate contractanta' => 'Autoritate', 'CUI autoritate contractanta' => '12345',
            'Numar anunt initiere' => $number, 'Stare procedura' => 'In desfasurare',
            'Denumire procedura' => 'Calculatoare pentru școală', 'Cod CPV' => '30213000-5',
            'Denumire CPV' => 'Computere personale', 'Data publicare' => '01/04/2026',
            'Valoare estimata procedura (RON)' => '120000.50', 'Moneda' => 'RON'];
    }

    /** @return array<string, string> */
    private function contractRow(): array
    {
        return ['Autoritate contractanta' => 'Autoritate', 'CUI autoritate contractanta' => '12345',
            'Numar anunt initiere' => 'CN1234567', 'Numar anunt atribuire' => 'CAN1234567',
            'Numar lot' => '1', 'Numar contract' => '12/2026', 'Data contract' => '12/04/2026',
            'Incheiat prin' => 'Fara acord cadru', 'Cod CPV' => '30213000-5',
            'Denumire CPV' => 'Computere personale', 'Ofertant castigator' => 'Exemplu SRL',
            'CUI ofertant castigator' => 'RO 12345678', 'Valoare contract (RON)' => '100000.50'];
    }

    private function ods(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'seap-test-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $xml = '<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"><table:table>';
        foreach ($rows as $row) {
            $xml .= '<table:table-row>';
            foreach ($row as $value) {
                $xml .= '<table:table-cell><text:p>'.htmlspecialchars($value, ENT_XML1).'</text:p></table:table-cell>';
            }
            $xml .= '</table:table-row>';
        }
        $zip->addFromString('content.xml', $xml.'</table:table></office:document-content>');
        $zip->close();

        return $path;
    }

    public function test_bulk_notices_are_idempotent_and_api_joins_exact_number_preserving_personal_data(): void
    {
        $sync = app(SeapBulkSync::class);
        $sync->notice($this->noticeRow(), 'resource', 'https://data.gov.ro/dataset/example');
        $sync->notice($this->noticeRow('SCN1234567'), 'resource', 'https://data.gov.ro/dataset/example');
        $notice = Notice::where('notice_number', 'CN1234567')->firstOrFail();
        $personal = UserNotice::factory()->for($notice)->create(['is_saved' => true]);
        $sync->notice($this->noticeRow(), 'resource', 'https://data.gov.ro/dataset/example');
        $this->assertDatabaseCount('notices', 2);
        $this->assertSame('120000.50', $notice->estimated_value);
        $this->assertSame('2026-04-01', $notice->publication_date->toDateString());
        $this->assertSame(0, Notice::seapOpportunities()->count());
        $api = app(NoticeIngestor::class)->ingest('seap', 'CN:42', ['notice_number' => 'CN1234567',
            'title' => 'Actualizat', 'status' => 'Publicat', 'submission_deadline' => now()->addWeek(),
            'seap_metadata' => ['family' => 'CN', 'category' => 'opportunity', 'bulk_only' => false]], []);
        $this->assertSame($notice->id, $api->id);
        $this->assertDatabaseCount('notices', 2);
        $this->assertTrue($personal->fresh()->is_saved);
        $sync->notice($this->noticeRow(), 'resource2', 'https://data.gov.ro/dataset/example');
        $this->assertSame('Actualizat', $api->fresh()->title);
        $this->assertSame(1, Notice::seapOpportunities()->count());
    }

    public function test_contracts_keep_joint_winners_and_lots_separate_and_never_create_notices(): void
    {
        $sync = app(SeapBulkSync::class);
        $row = $this->contractRow();
        $sync->contract($row, 'resource', 'https://data.gov.ro/dataset/example', 2);
        $sync->contract(array_replace($row, ['CUI ofertant castigator' => '12345678']), 'other-resource', 'https://data.gov.ro/dataset/example', 9);
        $sync->contract(array_replace($row, ['Ofertant castigator' => 'Asociat', 'CUI ofertant castigator' => '87654321']), 'resource', 'https://data.gov.ro/dataset/example', 3);
        $sync->contract(array_replace($row, ['Numar lot' => '2']), 'resource', 'https://data.gov.ro/dataset/example', 4);
        $this->assertDatabaseCount('supplier_history', 3);
        $this->assertDatabaseCount('notices', 0);
        $this->assertDatabaseHas('supplier_history', ['supplier_cui' => '12345678', 'value' => 100000.50, 'contract_date' => '2026-04-12']);
        $results = app(SupplierHistorySearch::class)->search(Notice::factory()->make(['cpv_code' => '30213000-5']), '');
        $this->assertCount(2, $results);
        $this->assertSame(1, $results[0]['count']);
        $this->assertSame(1, $results[1]['count']);
    }

    public function test_sync_resumes_without_redownload_and_detects_changed_official_resource(): void
    {
        Http::preventStrayRequests();
        $row = $this->noticeRow();
        $path = $this->ods([array_keys($row), array_values($row), array_values($this->noticeRow('SCN1234567'))]);
        $id = fake()->uuid();
        $resource = ['id' => $id, 'name' => 'Anunturi de intiere publicate TII 2026', 'url' => 'https://data.gov.ro/dataset/test/'.$id.'/download/export.ods', 'last_modified' => '2026-07-13'];
        Http::fake(['*package_show*' => Http::response(['success' => true, 'result' => ['resources' => [$resource]]]),
            $resource['url'] => Http::response(file_get_contents($path))]);
        try {
            $sync = app(SeapBulkSync::class);
            $first = $sync->sync(2026, 1);
            $this->assertSame('paused', $first['status']);
            $this->assertDatabaseCount('notices', 1);
            $state = DB::table('seap_bulk_resources')->where('resource_id', $id)->first();
            $spool = storage_path('app/private/seap-bulk/'.hash('sha256', $id.$state->version).'.zip.rows');
            $handle = fopen($spool, 'r+b');
            fgets($handle);
            fwrite($handle, '!');
            fclose($handle);
            $second = $sync->sync(2026, 1);
            $this->assertSame(1, $second['processed']);
            $this->assertDatabaseCount('notices', 2);
            $third = $sync->sync(2026, 1);
            $this->assertSame(0, $third['processed']);
            $this->assertSame(1, Http::recorded(fn ($request) => $request->url() === $resource['url'])->count());
            $offset = DB::table('seap_bulk_resources')->where('resource_id', $id)->value('byte_offset');
            $this->assertGreaterThan(0, $offset);
            $changed = $this->ods([array_keys($row), array_values(array_replace($row, ['Denumire procedura' => 'Titlu corectat']))]);
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake(['*package_show*' => Http::response(['success' => true, 'result' => ['resources' => [array_replace($resource, ['last_modified' => '2026-07-14'])]]]),
                $resource['url'] => Http::response(file_get_contents($changed))]);
            try {
                $sync->sync(2026, 10);
                $this->assertDatabaseCount('notices', 2);
                $this->assertDatabaseHas('notices', ['notice_number' => 'CN1234567', 'title' => 'Titlu corectat']);
                $this->assertDatabaseHas('seap_bulk_resources', ['resource_id' => $id, 'row_offset' => 1, 'status' => 'completed']);
                Http::assertSentCount(2);
            } finally {
                unlink($changed);
            }
        } finally {
            unlink($path);
        }
    }

    public function test_http_failure_does_not_advance_checkpoint_or_query_seap(): void
    {
        Http::preventStrayRequests();
        $id = fake()->uuid();
        $url = 'https://data.gov.ro/dataset/test/'.$id.'/download/file.xlsx';
        Http::fake(['*package_show*' => Http::response(['success' => true, 'result' => ['resources' => [
            ['id' => $id, 'name' => 'Contracte TI 2026', 'url' => $url],
        ]]]), $url => Http::response('', 403)]);
        $this->artisan('seap:bulk-sync')->assertFailed();
        $this->assertDatabaseHas('seap_bulk_resources', ['resource_id' => $id, 'row_offset' => 0, 'status' => 'error']);
        Http::assertSentCount(2);
        $this->assertDatabaseCount('supplier_history', 0);
    }

    public function test_default_api_mode_imports_cn_and_scn_without_detail_requests(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*PUBLICCNotice/GetAll/' => Http::response(['total' => 1, 'items' => [['cNoticeID' => 1, 'noticeNo' => 'CN1234567', 'contractTitle' => 'CN', 'tenderReceiptDeadline' => now()->addWeek()->toIso8601String(), 'sysProcedureState' => ['text' => 'In desfasurare']]]]),
            '*PublicRFQInvitation/GetAll/' => Http::response(['total' => 1, 'items' => [['rfqInvitationId' => 2, 'noticeNo' => 'SCN1234567', 'contractTitle' => 'SCN']]])]);
        $result = app(SeapImporter::class)->scan('2026-09-15', '2026-09-15', ['CN', 'SCN'], fn () => null);
        $this->assertSame(1, $result['CN']['processed']);
        $this->assertSame(1, $result['SCN']['processed']);
        Http::assertSentCount(2);
        $this->assertDatabaseCount('notices', 2);
    }

    public function test_active_catalog_rejects_unknown_expired_cancelled_and_closed_legacy_records(): void
    {
        $this->actingAs(User::factory()->create());
        $this->withoutVite();
        $active = Notice::factory()->create();
        Notice::factory()->create(['submission_deadline' => null]);
        Notice::factory()->create(['submission_deadline' => now()->subDay()]);
        Notice::factory()->create(['status' => 'Atribuita']);
        Notice::factory()->create(['status' => 'Anulata']);
        $this->get('/notices?source=seap')->assertViewHas('notices', fn ($rows) => $rows->modelKeys() === [$active->id]);
        $this->get('/notices?source=seap&seap_history=1')->assertViewHas('notices', fn ($rows) => $rows->total() === 5);
    }

    public function test_supplier_history_is_available_without_commercial_provider_and_escapes_names(): void
    {
        $notice = Notice::factory()->create(['cpv_code' => '30213000-5']);
        $row = array_replace($this->contractRow(), ['Ofertant castigator' => '<script>alert(1)</script>']);
        app(SeapBulkSync::class)->contract($row, 'resource', 'https://data.gov.ro/dataset/example', 2);
        $this->get('/notices/'.$notice->id.'/suppliers')->assertRedirect('/login');
        $this->actingAs(User::factory()->create());
        $this->withoutVite();
        $this->get('/notices/'.$notice->id.'/suppliers')->assertOk()->assertSee('Experiență publică documentată')
            ->assertSee('12345678')->assertSee('CPV identic')->assertDontSee('<script>alert(1)</script>', false);
        $unrelated = Notice::factory()->make(['cpv_code' => '45000000-7']);
        $this->assertSame([], app(SupplierHistorySearch::class)->search($unrelated, ''));
    }

    public function test_reader_streams_xlsx_shared_strings_sparse_columns_and_all_rows(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'seap-xlsx-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('xl/sharedStrings.xml', '<sst><si><t>Antet</t></si><si><r><t>Firmă </t></r><r><t>SRL</t></r></si></sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet><sheetData><row><c r="A1" t="s"><v>0</v></c></row><row><c r="A2" t="s"><v>1</v></c><c r="C2"><v>46024</v></c></row><row><c r="A3" t="inlineStr"><is><t>Ultimul</t></is></c></row></sheetData></worksheet>');
        $zip->close();
        try {
            $this->assertSame([['Antet'], ['Firmă SRL', '', '46024'], ['Ultimul']], iterator_to_array(app(BulkSpreadsheetReader::class)->rows($path)));
            $this->assertSame('2026-01-02 00:00:00', app(SeapBulkSync::class)->date('46024'));
        } finally {
            unlink($path);
        }
    }

    public function test_bulk_coverage_skips_history_but_pending_opportunities_get_one_complementary_day(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-16'));
        DB::table('seap_bulk_resources')->insert(['resource_id' => 'finished', 'kind' => 'notices',
            'name' => 'Anunturi de intiere publicate TII 2026', 'url' => 'https://data.gov.ro/dataset/test',
            'version' => '1', 'status' => 'completed']);
        $sync = app(SeapBulkSync::class);
        $sync->notice($this->noticeRow(), 'finished', 'https://data.gov.ro/dataset/test');
        $this->assertFalse($sync->covers('2026-04-01'));
        $this->assertTrue($sync->covers('2026-04-02'));
        $this->assertFalse($sync->covers('2026-07-01'));
        Queue::fake();
        $run = app(ScanDispatcher::class)->dispatch([], true);
        $this->assertSame('2026-04-01', $run->checkpoint['_refresh_day']);
        Queue::assertPushed(RunManualScan::class);
        Http::preventStrayRequests();
        Http::fake(['*PUBLICCNotice/GetAll/' => Http::response(['total' => 0, 'items' => []])]);
        $result = app(SeapImporter::class)->scan('2026-04-01', '2026-04-02', ['CN'], fn () => null);
        $this->assertTrue($result['CN']['complete']);
        $this->assertSame(1, $result['CN']['bulk_days']);
        Http::assertSentCount(1);
        $this->assertNull($sync->verificationDay());
        $this->assertSame(0, Notice::seapOpportunities()->count());
        $this->travel(8)->days();
        $this->assertSame('2026-04-01', $sync->verificationDay());
    }

    public function test_unsupported_initiation_families_are_not_imported_and_do_not_block_cn_scn(): void
    {
        $sync = app(SeapBulkSync::class);
        foreach (['PC100', 'RFQ100', 'DC100', 'CN1234567', 'SCN1234567'] as $number) {
            $sync->notice($this->noticeRow($number), 'resource', 'https://data.gov.ro/dataset/test');
        }
        $this->assertDatabaseCount('notices', 2);
    }

    public function test_missing_headers_fail_without_advancing_or_importing(): void
    {
        Http::preventStrayRequests();
        $path = $this->ods([['Unexpected header'], ['value']]);
        $url = 'https://data.gov.ro/dataset/test/'.fake()->uuid().'/bad.ods';
        Http::fake(['*package_show*' => Http::response(['success' => true, 'result' => ['resources' => [
            ['id' => fake()->uuid(), 'name' => 'Contracte TI 2026', 'url' => $url],
        ]]]), $url => Http::response(file_get_contents($path))]);
        try {
            $this->artisan('seap:bulk-sync')->assertFailed();
            $this->assertDatabaseHas('seap_bulk_resources', ['row_offset' => 0, 'status' => 'error']);
            $this->assertDatabaseCount('supplier_history', 0);
            Http::assertSentCount(2);
        } finally {
            unlink($path);
        }
    }

    public function test_default_listing_403_preserves_checkpoint_and_resumes_without_touching_ted(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-16'));
        Http::preventStrayRequests();
        Http::fake(['*PUBLICPINotice/GetAll/' => Http::response(['total' => 0, 'items' => []]),
            '*PUBLICCNotice/GetAll/' => Http::response('', 403)]);
        Queue::fake();
        $run = app(ScanDispatcher::class)->dispatch(['from' => '2026-09-15', 'to' => '2026-09-15'], true);
        (new RunManualScan($run->id, true))->handle(app(ScanContext::class));
        $this->assertSame('partial', $run->fresh()->status);
        $this->assertTrue($run->fresh()->checkpoint['PI']['stats']['complete']);
        Http::assertSentCount(2);
        $this->travel(61)->minutes();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*PUBLICCNotice/GetAll/' => Http::response(['total' => 0, 'items' => []]),
            '*PublicRFQInvitation/GetAll/' => Http::response(['total' => 0, 'items' => []])]);
        app(ScanDispatcher::class)->dispatch([], true);
        (new RunManualScan($run->id, true))->handle(app(ScanContext::class));
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame('not_run', $run->fresh()->results['ted']['status']);
        Http::assertSentCount(2);
    }

    public function test_invalid_row_preserves_previous_row_and_checkpoint_without_reimporting_it(): void
    {
        $row = $this->noticeRow();
        $path = $this->ods([array_keys($row), array_values($row), array_values(array_replace($row,
            ['Numar anunt initiere' => 'SCN1234567', 'Valoare estimata procedura (RON)' => 'invalid']))]);
        $id = fake()->uuid();
        $url = 'https://data.gov.ro/dataset/test/'.$id.'/file.ods';
        Http::preventStrayRequests();
        Http::fake(['*package_show*' => Http::response(['success' => true, 'result' => ['resources' => [
            ['id' => $id, 'name' => 'Anunturi de initiere publicate TI 2026', 'url' => $url],
        ]]]), $url => Http::response(file_get_contents($path))]);
        try {
            $this->artisan('seap:bulk-sync')->assertFailed();
            $this->assertDatabaseCount('notices', 1);
            $this->assertDatabaseHas('seap_bulk_resources', ['row_offset' => 1, 'status' => 'error']);
            $this->artisan('seap:bulk-sync')->assertFailed();
            $this->assertDatabaseCount('notice_revisions', 1);
            $this->assertSame(1, Http::recorded(fn ($request) => $request->url() === $url)->count());
        } finally {
            unlink($path);
        }
    }

    public function test_reader_rejects_truncated_xml_without_publishing_prepared_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'seap-broken-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet><sheetData><row><c r="A1"><v>1</v></c></row>');
        $zip->close();
        try {
            try {
                app(BulkSpreadsheetReader::class)->spool($path);
                $this->fail('Truncated XML must not be accepted.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('XML', $exception->getMessage());
                $this->assertFileDoesNotExist($path.'.rows');
            }
        } finally {
            unlink($path);
            if (is_file($path.'.rows.part')) {
                unlink($path.'.rows.part');
            }
        }
    }

    public function test_empty_catalog_does_not_call_seap_and_download_links_cannot_escape_official_host(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*package_show*' => Http::response(['success' => true, 'result' => ['resources' => []]])]);
        $this->artisan('seap:bulk-sync')->assertSuccessful();
        Http::assertSentCount(1);
        $this->assertDatabaseCount('seap_bulk_resources', 0);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*package_show*' => Http::response(['success' => true, 'result' => ['resources' => [
            ['id' => 'bad', 'name' => 'Contracte TI 2026', 'url' => 'http://127.0.0.1/private'],
        ]]])]);
        $this->artisan('seap:bulk-sync')->assertFailed();
        Http::assertSentCount(1);
        $this->assertDatabaseCount('seap_bulk_resources', 0);
    }

    public function test_scheduled_scan_confirms_bulk_opportunity_from_one_old_day_without_details(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-16'));
        app(SeapBulkSync::class)->notice($this->noticeRow(), 'resource', 'https://data.gov.ro/dataset/test');
        $originalId = Notice::sole()->id;
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake(['*PUBLICPINotice/GetAll/' => Http::response(['total' => 0, 'items' => []]),
            '*PublicRFQInvitation/GetAll/' => Http::response(['total' => 0, 'items' => []]),
            '*PUBLICCNotice/GetAll/' => function ($request) {
                $items = str_starts_with($request['publicationDateFrom'], '2026-04-01') ? [[
                    'cNoticeID' => 42, 'noticeNo' => 'CN1234567', 'contractTitle' => 'Confirmat',
                    'tenderReceiptDeadline' => '2026-09-25T12:00:00+03:00', 'sysProcedureState' => ['text' => 'In desfasurare'],
                ]] : [];

                return Http::response(['total' => count($items), 'items' => $items]);
            }]);
        $run = app(ScanDispatcher::class)->dispatch(['from' => '2026-09-15', 'to' => '2026-09-15'], true);
        Queue::pushed(RunManualScan::class)->sole()->handle(app(ScanContext::class));
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame('not_run', $run->fresh()->results['ted']['status']);
        $this->assertDatabaseCount('notices', 1);
        $this->assertSame($originalId, Notice::seapOpportunities()->sole()->id);
        $this->assertSame('CN:42', Notice::sole()->seap_id);
        $this->assertCount(2, $run->fresh()->checkpoint['CN']['windows']);
        Http::assertSentCount(5);
    }
}
