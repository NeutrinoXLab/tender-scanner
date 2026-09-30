<?php

namespace Tests\Feature;

use App\Models\Notice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class InfoTooltipTest extends TestCase
{
    use RefreshDatabase;

    public function test_component_renders_an_accessible_info_button_and_tooltip(): void
    {
        $html = Blade::render('<x-info-tooltip label="PI">Explicație scurtă.</x-info-tooltip>');

        $this->assertStringContainsString('type="button"', $html);
        $this->assertStringContainsString('aria-label="Mai multe informații despre PI"', $html);
        $this->assertStringContainsString('aria-expanded="false"', $html);
        $this->assertStringContainsString('role="tooltip"', $html);
        $this->assertStringContainsString('Explicație scurtă.', $html);
    }

    public function test_notice_list_explains_sources_families_personal_filters_and_scanning(): void
    {
        $this->withoutVite();
        config(['services.seap_worker.server' => true]);

        $response = $this->actingAs(User::factory()->create())->get(route('notices.index'));

        $response->assertOk()
            ->assertSee('Anunțuri din sursa europeană TED, importate și păstrate distinct de anunțurile SEAP.')
            ->assertSee('Anunțuri de intenție sau informare. Nu înseamnă automat că procedura este deschisă participării.')
            ->assertSee('Tender Scanner preia automat detaliile disponibile din SEAP.')
            ->assertSee('Anunțuri de participare simplificate din SEAP.')
            ->assertSee('Anunțurile pe care le-ai marcat pentru urmărire.')
            ->assertSee('Marcarea este personală și poate fi anulată oricând.')
            ->assertSee('Nouă, Analizez, Cer ofertă, Particip sau Renunț.')
            ->assertSee('Include anunțurile SEAP expirate, inactive sau fără termen confirmat.')
            ->assertSee('PI, CN, SCN și ADV sunt procesate în loturi prin workerul SEAP autorizat.');
    }

    public function test_notice_workspace_explains_personal_notes_and_supplier_search(): void
    {
        $this->withoutVite();
        config(['services.brave_search.key' => null]);
        $user = User::factory()->create();
        $notice = Notice::create(['seap_id' => 'tooltip-notice', 'title' => 'Echipamente IT']);

        $this->actingAs($user)->get(route('notices.show', $notice))->assertOk()
            ->assertSee('Aplicația caută pe web furnizori din România și Uniunea Europeană folosind termenii selectați.')
            ->assertSee('Sunt personale, vizibile doar în contul tău și rămân asociate anunțului.');

        $this->get(route('notices.suppliers', $notice))->assertOk()
            ->assertSee('Sunt generați din informațiile anunțului și pot fi modificați înainte de căutare.')
            ->assertSee('aria-label="Mai multe informații despre Termeni de căutare furnizori"', false);
    }
}
