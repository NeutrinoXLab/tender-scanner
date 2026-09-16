<?php

namespace Tests\Feature;

use App\Models\Notice;
use App\Models\User;
use App\Services\TedImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TedImportTest extends TestCase
{
    use RefreshDatabase;

    private function item(string $id = '623177-2026'): array
    {
        return ['publication-number' => $id, 'notice-title' => ['eng' => 'Fuel', 'ron' => 'Carburanți'],
            'buyer-name' => ['ron' => ['Institut']], 'publication-date' => '2026-09-10+02:00',
            'description-proc' => ['ron' => 'Motorină 116.500 litri.'], 'classification-cpv' => ['09134200', '09134200'],
            'estimated-value-proc' => '1311000', 'estimated-value-cur-proc' => 'RON',
            'deadline-receipt-tender-date-lot' => ['2026-09-25+03:00'], 'deadline-receipt-tender-time-lot' => ['15:00:00+03:00'],
            'contract-nature' => ['supplies'], 'procedure-type' => 'open', 'notice-type' => 'cn-standard'];
    }

    public function test_import_maps_romanian_data_and_reimport_updates_without_duplicates(): void
    {
        $this->actingAs(User::factory()->create());
        Http::preventStrayRequests();
        $importer = app(TedImporter::class);
        $notice = $importer->import($this->item());
        $this->assertSame('Carburanți', $notice->title);
        $this->assertSame('1311000.00', $notice->estimated_value);
        $this->assertSame('2026-09-25 15:00', $notice->submission_deadline->format('Y-m-d H:i'));
        $this->assertNull($notice->seap_id);
        $this->assertNull($notice->is_eu_funded);
        $this->assertSame('09134200', $notice->cpv_code);
        $importer->import(array_replace($this->item(), ['description-proc' => ['ron' => 'Actualizat']]));
        $this->assertDatabaseCount('notices', 1);
        $this->assertSame('Actualizat', $notice->fresh()->description);
    }

    public function test_exact_seap_link_links_sources_without_overwriting_seap_data(): void
    {
        $this->actingAs(User::factory()->create());
        $seap = Notice::create(['seap_id' => '100073686', 'title' => 'Titlu SEAP', 'description' => 'Detalii SEAP']);
        $item = $this->item();
        $item['submission-url-lot'] = ['https://www.e-licitatie.ro/pub/notices/pi-notice/view/v2/100073686'];
        app(TedImporter::class)->import($item);
        $this->assertDatabaseCount('notices', 1);
        $this->assertSame('Titlu SEAP', $seap->fresh()->title);
        $this->assertSame('623177-2026', $seap->fresh()->ted_id);
        $this->assertSame('Detalii SEAP', $seap->fresh()->description);
    }

    public function test_similar_titles_are_not_merged_and_filters_and_details_render(): void
    {
        $this->actingAs(User::factory()->create());
        $this->withoutVite();
        Notice::factory()->create(['seap_id' => '123', 'title' => 'Carburanți']);
        $ted = app(TedImporter::class)->import($this->item());
        $this->assertDatabaseCount('notices', 2);
        $this->get('/notices?source=ted&search=Carburan')->assertOk()->assertSee('Vezi anunțul pe TED')
            ->assertSee('Motorină 116.500 litri.')->assertViewHas('notices', fn ($rows) => $rows->total() === 1);
        $this->get('/notices?source=seap')->assertOk()->assertViewHas('notices', fn ($rows) => $rows->total() === 1);
        $this->get('/notices/'.$ted->id)->assertOk()->assertSee($ted->ted_url)->assertDontSee('Vezi anunțul pe SEAP');
    }

    public function test_command_paginates_and_filters_romania_and_dates(): void
    {
        $this->actingAs(User::factory()->create());
        Http::preventStrayRequests();
        Http::fakeSequence()->push(['notices' => [$this->item()], 'totalNoticeCount' => 2, 'iterationNextToken' => 'next'])
            ->push(['notices' => [$this->item('623178-2026')], 'totalNoticeCount' => 2, 'iterationNextToken' => null]);
        $this->artisan('ted:scan', ['--from' => '2026-09-01', '--to' => '2026-09-10'])->assertSuccessful();
        $this->assertDatabaseCount('notices', 2);
        Http::assertSent(fn ($request) => str_contains($request['query'], 'buyer-country = ROU')
            && str_contains($request['query'], '20260901') && $request['page'] === 2 && $request['paginationMode'] === 'PAGE_NUMBER');
    }

    public function test_invalid_dates_do_not_call_ted_and_partial_import_reports_failure(): void
    {
        $this->actingAs(User::factory()->create());
        Http::preventStrayRequests();
        $this->artisan('ted:scan', ['--from' => 'invalid'])->assertFailed();
        Http::assertNothingSent();
        Http::fakeSequence()->push(['notices' => [$this->item()], 'totalNoticeCount' => 2, 'iterationNextToken' => 'next']);
        $this->artisan('ted:scan', ['--max-pages' => 1])->assertFailed();
        $this->assertDatabaseCount('notices', 1);
    }

    public function test_malformed_api_response_is_not_reported_as_success(): void
    {
        $this->actingAs(User::factory()->create());
        Http::fake(['*' => Http::response(['error' => 'Invalid'])]);
        $this->artisan('ted:scan')->assertFailed();
        $this->assertDatabaseCount('notices', 0);
    }

    public function test_empty_page_before_reported_total_is_a_failure(): void
    {
        $this->actingAs(User::factory()->create());
        Http::fakeSequence()->push(['notices' => [$this->item()], 'totalNoticeCount' => 2])
            ->push(['notices' => [], 'totalNoticeCount' => 2]);
        $this->artisan('ted:scan')->assertFailed();
        $this->assertDatabaseCount('notices', 1);
    }

    public function test_repeated_page_is_rejected_instead_of_claiming_complete_import(): void
    {
        $this->actingAs(User::factory()->create());
        Http::fakeSequence()->push(['notices' => [$this->item()], 'totalNoticeCount' => 2])
            ->push(['notices' => [$this->item()], 'totalNoticeCount' => 2]);
        $this->artisan('ted:scan')->assertFailed();
        $this->assertDatabaseCount('notices', 1);
    }

    public function test_reference_to_an_older_notice_is_not_merged(): void
    {
        $this->actingAs(User::factory()->create());
        Notice::create(['seap_id' => '999', 'title' => 'Nou', 'raw_data' => ['previousNotice' => '623177-2026']]);
        app(TedImporter::class)->import($this->item());
        $this->assertDatabaseCount('notices', 2);
    }
}
