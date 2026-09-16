<?php

namespace Tests\Feature;

use App\Models\Notice;
use App\Models\User;
use App\Models\UserNotice;
use App\Services\SeapBulkSync;
use App\Services\SupplierHistorySearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class DirectPurchaseHistoryTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, string> */
    private function row(string $kind = 'direct_purchases', string $suffix = '40116337'): array
    {
        return [
            'Autoritate contractanta' => 'Autoritate test', 'CUI autoritate contractanta' => '12345',
            $kind === 'direct_purchases' ? 'Numar achizitie directa' : 'Numar notificare' => ($kind === 'direct_purchases' ? 'DA' : 'DAN').$suffix,
            'Data publicare' => '2026-04-01T05:50:49',
            $kind === 'direct_purchases' ? 'Denumire achizitie' : 'Obiectul achizitiei' => 'Calculatoare pentru școală',
            'Cod CPV' => '30213000-5', 'Denumire CPV' => 'Computere personale', 'Tip contract' => 'Furnizare',
            'Finantare prin fonduri comunitare?' => 'Nu', 'Data finalizare' => '2026-04-01T11:41:55',
            'Valoare achizitie (RON)' => '1245.8', 'Ofertant castigator' => 'Furnizor test', 'CUI ofertant castigator' => 'RO 12345678',
        ];
    }

    /** @param array<int, array<string, string>> $rows */
    private function ods(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'direct-test-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $xml = '<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"><table:table>';
        foreach ([array_keys($rows[0]), ...array_map('array_values', $rows)] as $row) {
            $xml .= '<table:table-row>';
            foreach ($row as $value) {
                $xml .= '<table:table-cell><text:p>'.htmlspecialchars($value, ENT_XML1).'</text:p></table:table-cell>';
            }
            $xml .= '</table:table-row>';
        }
        $zip->addFromString('content.xml', $xml.'</table:table></office:document-content>');
        $zip->close();
        $content = file_get_contents($path);
        unlink($path);

        return $content;
    }

    #[TestWith(['direct_purchases', 'DA40116337'])]
    #[TestWith(['direct_awards', 'DAN40116337'])]
    public function test_official_identity_upserts_history_without_creating_or_changing_notices(string $kind, string $number): void
    {
        $this->freezeTime();
        $notice = Notice::factory()->create();
        $personal = UserNotice::factory()->for($notice)->create(['is_saved' => true, 'is_ignored' => true, 'personal_status' => 'analyzing', 'personal_notes' => 'Notițe private']);
        $before = $notice->fresh()->getAttributes();
        $personalBefore = $personal->fresh()->getAttributes();
        $sync = app(SeapBulkSync::class);
        $row = $this->row($kind);
        $sync->directPurchase($row, $kind, 'resource1', 'https://data.gov.ro/dataset/test', 2);
        $id = DB::table('supplier_history')->value('id');

        $sync->directPurchase(array_replace($row, ['Valoare achizitie (RON)' => '1300', 'CUI ofertant castigator' => '87654321', 'Ofertant castigator' => 'Furnizor corectat']), $kind, 'resource2', 'https://data.gov.ro/dataset/test2', 8);

        $this->assertDatabaseCount('supplier_history', 1);
        $this->assertDatabaseHas('supplier_history', ['id' => $id, 'official_number' => $number, 'history_type' => $kind,
            'supplier_cui' => '87654321', 'supplier_name' => 'Furnizor corectat', 'value' => 1300, 'currency' => 'RON',
            'title' => 'Calculatoare pentru școală', 'published_at' => '2026-04-01 05:50:49', 'completed_at' => '2026-04-01 11:41:55',
            'contract_date' => '2026-04-01', 'contract_number' => '', 'notice_number' => '', 'resource_row' => 8]);
        $this->assertDatabaseCount('notices', 1);
        $this->assertSame($before, $notice->fresh()->getAttributes());
        $this->assertSame($personalBefore, $personal->fresh()->getAttributes());
    }

    public function test_da_and_dan_keep_distinct_identities_and_real_excel_dates(): void
    {
        $sync = app(SeapBulkSync::class);
        $sync->directPurchase(array_replace($this->row(), ['Data publicare' => '46023.5', 'Data finalizare' => '46024.75']), 'direct_purchases', 'r1', 'https://data.gov.ro/dataset/test', 2);
        $sync->directPurchase($this->row('direct_awards'), 'direct_awards', 'r2', 'https://data.gov.ro/dataset/test', 2);

        $this->assertDatabaseCount('supplier_history', 2);
        $this->assertDatabaseHas('supplier_history', ['official_number' => 'DA40116337', 'published_at' => '2026-01-01 12:00:00', 'completed_at' => '2026-01-02 18:00:00', 'supplier_cui' => '12345678']);
        $this->assertDatabaseCount('notices', 0);
    }

    #[TestWith(['direct_purchases', 'Achizitii directe TII 2026'])]
    #[TestWith(['direct_awards', 'Notificari de atribuire la cumpararea directa TII 2026'])]
    public function test_bulk_resumes_without_redownload_and_reprocesses_changed_export(string $kind, string $name): void
    {
        Http::preventStrayRequests();
        $row = $this->row($kind);
        $resource = ['id' => fake()->uuid(), 'name' => $name, 'url' => 'https://data.gov.ro/dataset/test/export.ods', 'last_modified' => '2026-07-13'];
        Http::fake(['*package_show*' => Http::response(['success' => true, 'result' => ['resources' => [$resource]]]),
            $resource['url'] => Http::response($this->ods([$row, $this->row($kind, '40116338')]))]);
        $sync = app(SeapBulkSync::class);

        $first = $sync->sync(2026, 1);
        $this->assertSame('paused', $first['status']);
        $this->assertDatabaseHas('seap_bulk_resources', ['resource_id' => $resource['id'], 'row_offset' => 1]);
        $this->assertGreaterThan(0, DB::table('seap_bulk_resources')->value('byte_offset'));
        $sync->sync(2026, 1);
        $this->assertDatabaseCount('supplier_history', 2);
        $this->assertSame(1, Http::recorded(fn ($request) => $request->url() === $resource['url'])->count());

        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*package_show*' => Http::response(['success' => true, 'result' => ['resources' => [array_replace($resource, ['last_modified' => '2026-07-14'])]]]),
            $resource['url'] => Http::response($this->ods([array_replace($row, ['Valoare achizitie (RON)' => '1800'])]))]);
        $sync->sync(2026, 1);
        $this->assertDatabaseCount('supplier_history', 2);
        $this->assertDatabaseHas('supplier_history', ['official_number' => $kind === 'direct_purchases' ? 'DA40116337' : 'DAN40116337', 'value' => 1800]);
        $this->assertDatabaseHas('seap_bulk_resources', ['status' => 'completed', 'row_offset' => 1]);
        $this->assertDatabaseCount('notices', 0);
        Http::assertSentCount(2);
    }

    #[TestWith(['Numar achizitie directa', 'ADV123'])]
    #[TestWith(['Ofertant castigator', ''])]
    #[TestWith(['Valoare achizitie (RON)', 'invalid'])]
    public function test_invalid_row_preserves_previous_import_and_checkpoint(string $field, string $value): void
    {
        Http::preventStrayRequests();
        $resource = ['id' => fake()->uuid(), 'name' => 'Achizitii directe TI 2026', 'url' => 'https://data.gov.ro/dataset/test/invalid.ods'];
        Http::fake(['*package_show*' => Http::response(['success' => true, 'result' => ['resources' => [$resource]]]),
            $resource['url'] => Http::response($this->ods([$this->row(), array_replace($this->row('direct_purchases', '40116338'), [$field => $value])]))]);

        try {
            app(SeapBulkSync::class)->sync(2026, 10);
            $this->fail('Invalid row must stop the resource.');
        } catch (RuntimeException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }

        $this->assertDatabaseCount('supplier_history', 1);
        $this->assertDatabaseHas('seap_bulk_resources', ['resource_id' => $resource['id'], 'status' => 'error', 'row_offset' => 1]);
        $this->assertDatabaseCount('notices', 0);
        Http::assertSentCount(2);
    }

    #[TestWith([403])]
    #[TestWith([429])]
    public function test_download_restriction_stops_without_retry_or_checkpoint_advance(int $status): void
    {
        Http::preventStrayRequests();
        $resource = ['id' => fake()->uuid(), 'name' => 'Achizitii directe TI 2026', 'url' => 'https://data.gov.ro/dataset/test/restricted.xlsx'];
        Http::fake(['*package_show*' => Http::response(['success' => true, 'result' => ['resources' => [$resource]]]), $resource['url'] => Http::response('', $status)]);

        $this->artisan('seap:bulk-sync', ['--year' => 2026])->assertFailed();

        $this->assertDatabaseHas('seap_bulk_resources', ['resource_id' => $resource['id'], 'row_offset' => 0, 'status' => 'error']);
        $this->assertDatabaseCount('supplier_history', 0);
        Http::assertSentCount(2);
    }

    public function test_missing_completion_is_counted_and_skipped_without_blocking_resume(): void
    {
        Http::preventStrayRequests();
        $resource = ['id' => fake()->uuid(), 'name' => 'Achizitii directe TII 2026', 'url' => 'https://data.gov.ro/dataset/test/incomplete.ods'];
        Http::fake(['*package_show*' => Http::response(['success' => true, 'result' => ['resources' => [$resource]]]),
            $resource['url'] => Http::response($this->ods([array_replace($this->row(), ['Data finalizare' => '']), $this->row('direct_purchases', '40116338')]))]);
        $sync = app(SeapBulkSync::class);

        $first = $sync->sync(2026, 1);
        $this->assertSame(1, $first['skipped']);
        $this->assertDatabaseCount('supplier_history', 0);
        $second = $sync->sync(2026, 1);

        $this->assertSame(1, $second['skipped']);
        $this->assertDatabaseHas('seap_bulk_resources', ['row_offset' => 2, 'status' => 'completed', 'skipped_rows' => 1, 'last_skipped_row' => 2]);
        $this->assertDatabaseCount('supplier_history', 1);
        $this->assertDatabaseHas('supplier_history', ['official_number' => 'DA40116338']);
        Http::assertSentCount(3);
    }

    public function test_changed_direct_export_headers_stop_before_any_row_is_imported(): void
    {
        Http::preventStrayRequests();
        $row = $this->row('direct_awards');
        unset($row['Numar notificare']);
        $resource = ['id' => fake()->uuid(), 'name' => 'Notificari de atribuire la cumpararea directa TI 2026', 'url' => 'https://data.gov.ro/dataset/test/changed.ods'];
        Http::fake(['*package_show*' => Http::response(['success' => true, 'result' => ['resources' => [$resource]]]),
            $resource['url'] => Http::response($this->ods([$row]))]);

        $this->artisan('seap:bulk-sync')->assertFailed();

        $this->assertDatabaseCount('supplier_history', 0);
        $this->assertDatabaseHas('seap_bulk_resources', ['row_offset' => 0, 'status' => 'error', 'error' => 'Coloană obligatorie lipsă: Numar notificare']);
        Http::assertSentCount(2);
    }

    public function test_history_is_searchable_by_cpv_or_title_and_is_escaped_in_existing_supplier_page(): void
    {
        $this->withoutVite();
        Http::preventStrayRequests();
        $row = array_replace($this->row(), ['Denumire achizitie' => 'Calculatoare <script>alert(1)</script>']);
        app(SeapBulkSync::class)->directPurchase($row, 'direct_purchases', 'resource', 'https://data.gov.ro/dataset/test', 2);
        $notice = Notice::factory()->create(['cpv_code' => '30213000-5']);
        $search = app(SupplierHistorySearch::class);

        $results = $search->search($notice, '');
        $this->assertSame('Achiziție directă', $results[0]['contracts'][0]['type']);
        $this->assertSame('DA40116337', $results[0]['contracts'][0]['number']);
        $this->assertSame(1, $results[0]['count']);
        $this->assertCount(1, $search->search(Notice::factory()->make(['cpv_code' => null]), 'Calculatoare'));
        $this->get(route('notices.suppliers', $notice))->assertRedirectToRoute('login');
        $this->actingAs(User::factory()->create())->get(route('notices.suppliers', $notice))
            ->assertOk()->assertSee('Achiziție directă')->assertSee('DA40116337')->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        Http::assertNothingSent();
    }
}
