<?php

namespace Tests\Feature;

use App\Models\ScanRun;
use App\Models\User;
use App\Services\ScanDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ScanPanelSelectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_panel_and_poll_follow_dispatcher_backlog_then_active_run(): void
    {
        $this->withoutVite();
        $this->actingAs(User::factory()->create());
        Http::preventStrayRequests();
        $old = ScanRun::create(['mode' => 'full', 'status' => 'partial', 'date_from' => '2026-08-01', 'date_to' => '2026-08-31',
            'checkpoint' => ['_families' => ['SCN'], '_next_batch_at' => now()->addHours(6)->toIso8601String()]]);
        $new = ScanRun::create(['mode' => 'probe', 'status' => 'completed', 'date_from' => '2026-09-01', 'date_to' => '2026-09-01']);
        $this->assertSame($old->id, app(ScanDispatcher::class)->dispatch(seapOnly: true)->id);
        $this->get('/notices')->assertOk()->assertViewHas('latestScan', fn ($scan) => $scan->id === $old->id)
            ->assertSee('cea mai veche sincronizare SEAP restantă');
        $this->getJson('/scans/latest')->assertOk()->assertJsonPath('id', $old->id);
        $new->update(['active_key' => 'manual', 'status' => 'running']);
        $this->get('/notices')->assertOk()->assertViewHas('latestScan', fn ($scan) => $scan->id === $new->id);
        $this->getJson('/scans/latest')->assertOk()->assertJsonPath('id', $new->id);
        $new->update(['active_key' => null, 'status' => 'completed']);
        $old->update(['status' => 'cancelled']);
        $this->getJson('/scans/latest')->assertOk()->assertJsonPath('id', $new->id);
        Http::assertNothingSent();
    }

    public function test_refresh_link_is_relative_and_preserves_filters_despite_local_root(): void
    {
        $this->withoutVite();
        $this->actingAs(User::factory()->create());
        URL::forceRootUrl('http://seap-scanner.test');
        $filters = ['source' => 'seap', 'search' => 'A & B', 'county' => 'Cluj', 'page' => '2'];
        $response = $this->get('/notices?'.http_build_query($filters))->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $link = (new \DOMXPath($document))->query('//a[@id="manual-scan-refresh"]')->item(0)->getAttribute('href');
        $this->assertStringStartsWith('/notices?', $link);
        $this->assertNull(parse_url($link, PHP_URL_HOST));
        parse_str(parse_url($link, PHP_URL_QUERY), $query);
        $this->assertSame($filters, $query);
    }
}
