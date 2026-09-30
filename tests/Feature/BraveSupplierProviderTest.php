<?php

namespace Tests\Feature;

use App\Services\BraveSupplierProvider;
use App\Services\SupplierSearchProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BraveSupplierProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::clear();
        config([
            'services.brave_search.key' => 'brave-test-key',
            'services.brave_search.endpoint' => 'https://api.search.brave.com/res/v1/web/search',
            'services.brave_search.results_per_market' => 10,
            'services.brave_search.cache_ttl' => 3600,
        ]);
        Http::preventStrayRequests();
    }

    public function test_unconfigured_provider_performs_no_requests(): void
    {
        config(['services.brave_search.key' => null]);

        $result = app(BraveSupplierProvider::class)->search('Laptop', $this->privateContext());

        $this->assertSame('not_configured', $result->status);
        $this->assertSame([], $result->items);
        Http::assertNothingSent();
    }

    public function test_it_searches_romania_and_europe_normalizes_and_deduplicates_results(): void
    {
        Http::fakeSequence()
            ->push(['web' => ['results' => [[
                'title' => '<b>Laptop profesional</b>',
                'url' => 'https://www.furnizor.example/laptop?utm_source=brave&id=2',
                'description' => 'Laptop pentru companii',
                'extra_snippets' => ['Livrare rapidă', 'Laptop pentru companii'],
                'profile' => ['long_name' => 'Furnizor România'],
            ]]]])
            ->push(['web' => ['results' => [
                ['title' => 'Same supplier', 'url' => 'https://furnizor.example/english'],
                ['title' => 'EU equipment', 'url' => 'https://eu-supplier.example/catalog', 'description' => 'European distributor'],
                ['title' => 'Unsafe', 'url' => 'javascript:alert(1)'],
            ]]]);

        $result = app(BraveSupplierProvider::class)->search('echipamente IT', $this->privateContext());

        $this->assertSame('ok', $result->status);
        $this->assertNull($result->message);
        $this->assertCount(2, $result->items);
        $this->assertSame('Furnizor România', $result->items[0]['supplier']);
        $this->assertSame('Laptop profesional', $result->items[0]['product']);
        $this->assertNull($result->items[0]['country']);
        $this->assertSame('Laptop pentru companii Livrare rapidă', $result->items[0]['snippet']);
        $this->assertSame('Brave Search · România', $result->items[0]['source']);
        $this->assertSame('https://www.furnizor.example/laptop?id=2', $result->items[0]['url']);
        $this->assertSame('eu-supplier.example', $result->items[1]['supplier']);
        $this->assertSame('Brave Search · Uniunea Europeană', $result->items[1]['source']);

        Http::assertSentCount(2);
        Http::assertSent(function ($request): bool {
            $url = urldecode($request->url());

            return $request->hasHeader('X-Subscription-Token', 'brave-test-key')
                && str_contains($url, 'country=RO')
                && str_contains($url, 'search_lang=ro')
                && str_contains($url, 'furnizor')
                && ! str_contains($url, 'notiță personală');
        });
        Http::assertSent(function ($request): bool {
            $url = urldecode($request->url());

            return ! str_contains($url, 'country=')
                && str_contains($url, 'search_lang=en')
                && str_contains($url, 'equipment IT supplier distributor manufacturer Europe EU');
        });
    }

    public function test_successful_searches_are_cached_per_market(): void
    {
        Http::fake(['*' => Http::response(['web' => ['results' => []]])]);
        $provider = app(BraveSupplierProvider::class);

        $provider->search('Laptop', $this->privateContext());
        $provider->search('Laptop', $this->privateContext());

        Http::assertSentCount(2);
    }

    public function test_one_failed_market_returns_available_results_and_a_controlled_message(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'country=RO')) {
                return Http::response([], 429);
            }

            return Http::response(['web' => ['results' => [[
                'title' => 'European supplier',
                'url' => 'https://supplier.eu/product',
                'country' => 'Germany',
            ]]]]);
        });

        $result = app(BraveSupplierProvider::class)->search('Laptop', $this->privateContext());

        $this->assertSame('ok', $result->status);
        $this->assertCount(1, $result->items);
        $this->assertSame('Germany', $result->items[0]['country']);
        $this->assertStringContainsString('România', $result->message);
    }

    public function test_total_http_or_schema_failure_returns_no_results(): void
    {
        Http::fakeSequence()
            ->push([], 503)
            ->push(['unexpected' => true]);

        $result = app(BraveSupplierProvider::class)->search('Laptop', $this->privateContext());

        $this->assertSame('error', $result->status);
        $this->assertSame([], $result->items);
        $this->assertStringNotContainsString('brave-test-key', $result->message);
    }

    public function test_application_binds_the_brave_provider(): void
    {
        $this->assertInstanceOf(BraveSupplierProvider::class, app(SupplierSearchProvider::class));
    }

    private function privateContext(): array
    {
        return [
            'notice_id' => 1,
            'cpv' => '30200000-1',
            'ean' => null,
            'quantity' => null,
            'specifications' => 'notiță personală care nu trebuie trimisă',
        ];
    }
}
