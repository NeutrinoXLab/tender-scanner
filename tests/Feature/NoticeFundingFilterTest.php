<?php

namespace Tests\Feature;

use App\Models\Notice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NoticeFundingFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_funding_filter_distinguishes_yes_no_and_missing_data(): void
    {
        $this->actingAs(User::factory()->create());
        foreach ([true, false, null] as $index => $value) {
            Notice::create(['seap_id' => (string) $index, 'title' => 'Notice '.$index, 'is_eu_funded' => $value]);
        }

        foreach (['1' => '0', '0' => '1', 'unknown' => '2'] as $filter => $expected) {
            $this->get('/notices?is_eu_funded='.$filter)->assertOk()
                ->assertViewHas('notices', fn ($notices) => $notices->total() === 1 && $notices->first()->seap_id === $expected);
        }
        $this->get('/notices?is_eu_funded=')->assertOk()
            ->assertViewHas('notices', fn ($notices) => $notices->total() === 3);
    }

    public function test_funding_combines_with_existing_filters(): void
    {
        $this->actingAs(User::factory()->create());
        $attributes = [
            'title' => 'Echipamente', 'county' => 'Cluj', 'cpv_code' => '12345',
            'status' => 'Activ', 'contract_type' => 'Furnizare', 'procedure_type' => 'Deschisa',
            'contract_assignment_type' => 'Contract', 'estimated_value' => 150,
            'submission_deadline' => '2026-10-15', 'is_eu_funded' => true,
            'funding_program' => 'Program Regional 2026',
        ];
        Notice::create(['seap_id' => 'match'] + $attributes);
        Notice::create(['seap_id' => 'wrong-county', 'county' => 'Iasi'] + $attributes);
        Notice::create(['seap_id' => 'wrong-program', 'funding_program' => 'Alt program'] + $attributes);
        Notice::create(['seap_id' => 'wrong-funding', 'is_eu_funded' => false] + $attributes);

        $this->get('/notices?'.http_build_query([
            'search' => 'Echipamente', 'county' => 'Cluj', 'cpv' => '123', 'status' => 'Activ',
            'contract_type' => 'Furnizare', 'procedure_type' => 'Deschisa', 'assignment_type' => 'Contract',
            'min_value' => 100, 'max_value' => 200, 'deadline_from' => '2026-10-01',
            'deadline_to' => '2026-10-31', 'is_eu_funded' => '1', 'funding_program' => 'Regional',
        ]))->assertOk()->assertViewHas('notices', fn ($notices) => $notices->total() === 1 && $notices->first()->seap_id === 'match');

        $this->get('/notices?funding_program=Regional')->assertOk()
            ->assertViewHas('notices', fn ($notices) => $notices->total() === 3);
        $this->get('/notices/'.Notice::first()->id)->assertOk();
    }

    public function test_pagination_preserves_funding_filters(): void
    {
        $this->actingAs(User::factory()->create());
        for ($i = 0; $i < 21; $i++) {
            Notice::create(['seap_id' => (string) $i, 'title' => 'Notice', 'is_eu_funded' => false, 'funding_program' => 'Regional']);
        }
        $this->get('/notices?is_eu_funded=0&funding_program=Regional')->assertOk()
            ->assertViewHas('notices', fn ($notices) => $notices->total() === 21
                && str_contains($notices->nextPageUrl(), 'is_eu_funded=0')
                && str_contains($notices->nextPageUrl(), 'funding_program=Regional'));
    }

    public function test_invalid_funding_filters_are_rejected(): void
    {
        $this->actingAs(User::factory()->create());
        $this->getJson('/notices?is_eu_funded=invalid')->assertUnprocessable()->assertJsonValidationErrors('is_eu_funded');
        $this->getJson('/notices?funding_program[]=invalid')->assertUnprocessable()->assertJsonValidationErrors('funding_program');
    }

    public function test_funding_input_is_escaped_and_cannot_change_the_query(): void
    {
        $this->actingAs(User::factory()->create());
        Notice::create(['seap_id' => 'safe', 'title' => 'Notice', 'funding_program' => 'Regional']);
        $input = '<script>alert(1)</script>';
        $this->get('/notices?'.http_build_query(['funding_program' => $input]))
            ->assertSee($input)->assertDontSee($input, false);
        $this->get('/notices?'.http_build_query(['funding_program' => "' OR 1=1 --"]))
            ->assertOk()->assertViewHas('notices', fn ($notices) => $notices->total() === 0);
    }

    public function test_returns_422_for_array_funding_status_and_oversized_program(): void
    {
        $this->actingAs(User::factory()->create());
        $this->getJson('/notices?is_eu_funded[]=1')->assertUnprocessable()->assertJsonValidationErrors('is_eu_funded');
        $this->getJson('/notices?funding_program='.str_repeat('a', 256))->assertUnprocessable()->assertJsonValidationErrors('funding_program');
    }
}
