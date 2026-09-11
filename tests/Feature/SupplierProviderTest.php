<?php

namespace Tests\Feature;

use App\Services\PriceScannerSupplierProvider;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SupplierProviderTest extends TestCase
{
    private function context(): array
    {
        return ['notice_id' => 1, 'cpv' => '30200000-1', 'ean' => '4006381333931', 'quantity' => ['value' => 2.0, 'unit' => 'buc'], 'specifications' => '16 GB'];
    }

    public function test_disabled_provider_performs_no_http_requests(): void
    {
        config(['services.price_scanner.search_url' => null]);
        Http::preventStrayRequests();

        $result = app(PriceScannerSupplierProvider::class)->search('Laptop', $this->context());

        $this->assertSame('not_configured', $result->status);
        $this->assertSame([], $result->items);
        Http::assertNothingSent();
    }

    public function test_adapter_sends_documented_context_and_token_and_normalizes_results(): void
    {
        config(['services.price_scanner.search_url' => 'https://scanner.example/search', 'services.price_scanner.token' => 'test-token']);
        Http::preventStrayRequests();
        Http::fake(['https://scanner.example/search' => Http::response(['items' => [
            ['supplier' => 'Test A', 'product' => 'Laptop A', 'price' => '1000.50', 'currency' => 'RON', 'match_score' => 0.75, 'url' => 'https://supplier.example/a'],
            ['product' => 'Laptop B', 'price' => 0, 'url' => 'javascript:alert(1)'],
        ]])]);

        $result = app(PriceScannerSupplierProvider::class)->search('Laptop', $this->context());

        $this->assertSame('ok', $result->status);
        $this->assertCount(2, $result->items);
        $this->assertSame('1000.50', $result->items[0]['price']);
        $this->assertSame(0.75, $result->items[0]['match_score']);
        $this->assertNull($result->items[0]['availability']);
        $this->assertSame('0', $result->items[1]['price']);
        $this->assertNull($result->items[1]['url']);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request['query'] === 'Laptop' && $request['context'] === $this->context());
    }

    public static function failures(): array
    {
        return ['HTTP 503' => [[], 503], 'redirect' => [[], 302], 'HTML' => ['<html>error</html>', 200],
            'missing schema' => [[], 200], 'invalid price' => [['items' => [['price' => -1]]], 200],
            'invalid score' => [['items' => [['match_score' => 90]]], 200], 'not a list' => [['items' => ['product' => 'Invalid']], 200]];
    }

    #[DataProvider('failures')]
    public function test_adapter_reports_http_and_schema_errors_without_fake_results(array|string $body, int $status): void
    {
        config(['services.price_scanner.search_url' => 'https://scanner.example/search']);
        Http::preventStrayRequests();
        Http::fake(['https://scanner.example/search' => Http::response($body, $status)]);

        $result = app(PriceScannerSupplierProvider::class)->search('Laptop', $this->context());

        $this->assertSame('error', $result->status);
        $this->assertSame([], $result->items);
        Http::assertSentCount(1);
    }

    public function test_handles_empty_results_and_connection_failure(): void
    {
        config(['services.price_scanner.search_url' => 'https://scanner.example/search']);
        Http::preventStrayRequests();
        Http::fake(['https://scanner.example/search' => Http::response(['items' => []])]);
        $result = app(PriceScannerSupplierProvider::class)->search('Laptop', $this->context());
        $this->assertSame('ok', $result->status);
        $this->assertSame([], $result->items);
        Http::fake(['https://scanner.example/search' => Http::failedConnection()]);
        $this->assertSame('error', app(PriceScannerSupplierProvider::class)->search('Laptop', $this->context())->status);
    }
}
