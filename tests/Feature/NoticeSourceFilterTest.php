<?php

namespace Tests\Feature;

use App\Models\Notice;
use App\Models\ScanRun;
use App\Models\User;
use App\Models\UserNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NoticeSourceFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_source_options_separate_catalog_records_without_changing_all_results(): void
    {
        $this->actingAs(User::factory()->create());
        $this->withoutVite();
        $seap = Notice::factory()->create(['source' => 'seap', 'title' => 'Achizitie SEAP', 'status' => 'Publicat', 'ted_id' => '123456-2026']);
        $ted = Notice::factory()->create(['source' => 'ted', 'title' => 'Achizitie TED', 'ted_id' => '654321-2026']);
        $this->get('/notices')->assertOk()->assertViewHas('notices', fn ($rows) => $rows->total() === 2)
            ->assertSee('Filtrare după sursă');
        $this->get('/notices?source=seap')->assertOk()->assertViewHas('notices', fn ($rows) => $rows->modelKeys() === [$seap->id]);
        $this->get('/notices?source=ted')->assertOk()->assertViewHas('notices', fn ($rows) => $rows->modelKeys() === [$ted->id]);
        $this->get('/notices?source=')->assertOk()->assertViewHas('notices', fn ($rows) => $rows->total() === 2);
    }

    public function test_source_links_preserve_filters_reset_page_and_form_preserves_source(): void
    {
        $this->actingAs(User::factory()->create());
        $this->withoutVite();
        $filters = ['collection' => 'saved', 'personal_status' => 'analyzing', 'search' => 'Calculatoare', 'county' => 'Cluj', 'cpv' => '302', 'min_value' => 10];
        $response = $this->get('/notices?'.http_build_query($filters + ['source' => 'seap', 'page' => 3]))->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new \DOMXPath($document);
        $links = $xpath->query('//nav[@aria-label="Filtrare după sursă"]/a');
        $this->assertCount(3, $links);
        foreach ($links as $index => $link) {
            parse_str(parse_url($link->getAttribute('href'), PHP_URL_QUERY) ?? '', $query);
            $this->assertSame(array_map('strval', $filters), array_intersect_key($query, $filters));
            $this->assertArrayNotHasKey('page', $query);
            $this->assertSame(['', 'seap', 'ted'][$index], $query['source'] ?? '');
            $this->assertSame($index === 1 ? 'page' : '', $link->getAttribute('aria-current'));
        }
        $this->assertSame('seap', $xpath->query('//form[@method="GET"]//input[@name="source"]')->item(0)->getAttribute('value'));
    }

    public function test_source_combines_with_personal_collection_and_survives_pagination(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->withoutVite();
        $notices = Notice::factory()->count(21)->create(['source' => 'ted', 'title' => 'Calculatoare', 'county' => 'Cluj', 'cpv_code' => '30200000']);
        foreach ($notices as $notice) {
            UserNotice::factory()->for($user)->for($notice)->create(['is_saved' => true, 'personal_status' => 'analyzing']);
        }
        Notice::factory()->create(['source' => 'ted', 'title' => 'Calculatoare', 'county' => 'Cluj', 'cpv_code' => '30200000']);
        $this->get('/notices?source=ted&collection=saved&personal_status=analyzing&county=Cluj&cpv=302&search=Calculatoare')
            ->assertOk()->assertViewHas('notices', fn ($rows) => $rows->total() === 21
                && str_contains($rows->nextPageUrl(), 'source=ted') && str_contains($rows->nextPageUrl(), 'collection=saved')
                && str_contains($rows->nextPageUrl(), 'personal_status=analyzing'));
    }

    public function test_cancelled_scan_is_displayed_and_cannot_be_resumed(): void
    {
        $this->actingAs(User::factory()->create());
        $this->withoutVite();
        $run = ScanRun::create(['status' => 'cancelled', 'mode' => 'full', 'date_from' => '2026-08-13', 'date_to' => '2026-09-12']);
        $this->assertFalse($run->summary()['can_resume']);
        $this->get('/notices')->assertOk()->assertSee('Anulată');
        $this->getJson('/scans/latest')->assertJsonPath('status', 'cancelled')->assertJsonPath('can_resume', false);
    }
}
