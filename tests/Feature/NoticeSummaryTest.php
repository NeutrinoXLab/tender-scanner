<?php

namespace Tests\Feature;

use App\Models\Notice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NoticeSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_results_show_description_lot_requirements_and_submission_information(): void
    {
        $this->withoutVite();
        Notice::create(['seap_id' => 'summary', 'title' => 'Catering', 'description' => 'Pachete alimentare.', 'raw_data' => [
            'piNoticeV2' => ['section2' => [['section2_2' => ['lots' => ['items' => [[
                'descriptionOfProcurement' => 'Maximum 12.654 porții.',
                'additionalInformation' => 'Depunere în plic sigilat, cu USB.',
            ]]]]]]],
        ]]);
        $this->get('/notices?search=Catering')->assertOk()->assertSee('Sumar anunț')
            ->assertSee('Pachete alimentare.')->assertSee('Maximum 12.654 porții.')
            ->assertSee('Depunere în plic sigilat, cu USB.');
    }

    public function test_missing_data_and_malformed_sections_do_not_break_results(): void
    {
        $this->withoutVite();
        Notice::create(['seap_id' => 'empty', 'title' => 'Empty', 'raw_data' => ['piNoticeV2' => ['section2' => 'invalid']]]);
        $this->get('/notices')->assertOk()->assertSee('Descrierea nu este disponibilă');
    }

    public function test_summary_cleans_markup_deduplicates_and_keeps_long_text_accessible(): void
    {
        $this->withoutVite();
        $description = '<p>Transport</p><p>zilnic</p>';
        $notice = Notice::create(['seap_id' => 'long', 'title' => 'Long', 'description' => $description, 'raw_data' => [
            'piNoticeV2' => ['section2' => [['section2_1' => ['description' => $description], 'section2_2' => ['lots' => ['items' => [[
                'descriptionOfProcurement' => str_repeat('Cerințe. ', 100).'FINAL CERINȚE',
            ]]]]]]],
        ]]);
        $this->assertCount(2, $notice->summaryItems());
        $this->assertSame('Transport zilnic', $notice->summaryItems()[0]['text']);
        $this->get('/notices')->assertOk()->assertSee('Vezi toate informațiile din sumar')->assertSee('FINAL CERINȚE')
            ->assertDontSee($description, false);
    }
}
