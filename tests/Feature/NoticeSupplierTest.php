<?php

namespace Tests\Feature;

use App\Models\Notice;
use App\Services\SupplierSearchProvider;
use App\Services\SupplierSearchResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NoticeSupplierTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_page_explains_unconfigured_source_and_keeps_search_editable(): void
    {
        $this->withoutVite();
        Http::preventStrayRequests();
        config(['services.price_scanner.search_url' => null]);
        $notice = Notice::create(['seap_id' => 'suppliers', 'title' => 'Contract de furnizare echipamente IT pentru dotarea laboratorului',
            'cpv_code' => '30200000-1', 'submission_deadline' => '2026-09-18']);

        $this->get(route('notices.show', $notice))->assertSee('Caută furnizori')->assertSee('Notițele mele')->assertSee('Salvează');
        $this->get(route('notices.suppliers', $notice))->assertOk()->assertSee('echipamente IT')->assertSee('30200000-1')->assertSee('18.09.2026')
            ->assertSee('Nu este configurată încă o sursă live');
        $this->get(route('notices.suppliers', ['notice' => $notice, 'q' => 'Laptop 16 GB']))->assertOk()->assertSee('Laptop 16 GB')->assertSee('Nu este configurată încă o sursă live');
        Http::assertNothingSent();
    }

    public function test_sends_manually_edited_query_and_renders_multiple_real_provider_results_with_missing_values(): void
    {
        $this->withoutVite();
        $notice = Notice::create(['seap_id' => 'suppliers', 'title' => 'Echipamente IT', 'cpv_code' => '30200000-1']);
        $this->mock(SupplierSearchProvider::class, function ($mock) use ($notice): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            $mock->shouldReceive('search')->once()->with('Laptop 16 GB', \Mockery::on(fn ($context) => $context['notice_id'] === $notice->id && $context['cpv'] === '30200000-1'))
                ->andReturn(new SupplierSearchResult('ok', [
                    ['supplier' => 'Furnizor de test A', 'product' => 'Produs de test A', 'price' => '1234.50', 'currency' => 'RON', 'availability' => 'În stoc', 'match_score' => 0.9, 'url' => 'https://supplier.example/product'],
                    ['supplier' => 'Furnizor de test B', 'product' => 'Produs de test B', 'price' => null, 'currency' => null, 'availability' => null, 'match_score' => null, 'url' => null],
                ]));
        });

        $this->get(route('notices.suppliers', ['notice' => $notice, 'q' => 'Laptop 16 GB']))->assertOk()
            ->assertSee('Furnizor de test A')->assertSee('Furnizor de test B')->assertSee('1234.50')->assertSee('90%')->assertSee('Link produs: —');
        $this->assertDatabaseCount('notices', 1);
        $this->assertDatabaseCount('notice_revisions', 0);
    }

    public function test_configured_provider_is_not_called_until_search_is_submitted(): void
    {
        $this->withoutVite();
        $notice = Notice::create(['seap_id' => 'suppliers', 'title' => 'Laptop', 'description' => 'Imprimante']);
        $this->mock(SupplierSearchProvider::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturn(true);
            $mock->shouldNotReceive('search');
        });

        $this->get(route('notices.suppliers', ['notice' => $notice, 'term' => 1]))->assertOk()->assertViewHas('query', 'Imprimante');
    }

    public function test_empty_provider_result_and_provider_exception_have_clear_messages(): void
    {
        $this->withoutVite();
        $notice = Notice::create(['seap_id' => 'suppliers', 'title' => 'Laptop']);
        $this->mock(SupplierSearchProvider::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->twice()->andReturn(true);
            $mock->shouldReceive('search')->once()->andReturn(new SupplierSearchResult('ok'));
            $mock->shouldReceive('search')->once()->andThrow(new \RuntimeException('secret-token internal error'));
        });

        $url = route('notices.suppliers', ['notice' => $notice, 'q' => 'Laptop']);
        $this->get($url)->assertSee('Nu au fost găsiți furnizori');
        $this->get($url)->assertSee('momentan indisponibilă')->assertDontSee('secret-token');
    }

    public function test_rejects_invalid_query_and_unknown_notices_without_calling_provider(): void
    {
        $notice = Notice::create(['seap_id' => 'suppliers', 'title' => 'Laptop']);
        $this->mock(SupplierSearchProvider::class, fn ($mock) => $mock->shouldNotReceive('search'));
        $url = route('notices.suppliers', $notice);

        $this->getJson($url.'?q=')->assertUnprocessable();
        $this->getJson($url.'?q[]=%20')->assertUnprocessable();
        $this->getJson($url.'?q='.str_repeat('x', 241))->assertUnprocessable();
        $this->getJson($url.'?term=5')->assertUnprocessable();
        $this->getJson('/notices/9999/suppliers')->assertNotFound();
    }

    public function test_escapes_query_and_redirects_merged_notice_to_canonical_supplier_page(): void
    {
        $this->withoutVite();
        config(['services.price_scanner.search_url' => null]);
        $notice = Notice::create(['seap_id' => 'suppliers', 'title' => 'Laptop']);
        $old = Notice::create(['seap_id' => 'old', 'title' => 'Old']);
        $old->forceFill(['canonical_notice_id' => $notice->id])->save();

        $this->get(route('notices.suppliers', ['notice' => $old, 'q' => 'Laptop']))->assertRedirect(route('notices.suppliers', ['notice' => $notice, 'q' => 'Laptop']));
        $this->get(route('notices.suppliers', ['notice' => $notice, 'q' => '<script>alert(1)</script>']))->assertSee('<script>alert(1)</script>')->assertDontSee('<script>alert(1)</script>', false);
    }
}
