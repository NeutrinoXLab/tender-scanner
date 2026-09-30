<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class BraveSupplierProvider implements SupplierSearchProvider
{
    public function isConfigured(): bool
    {
        $key = config('services.brave_search.key');

        return is_string($key) && trim($key) !== '';
    }

    public function search(string $query, array $context): SupplierSearchResult
    {
        if (! $this->isConfigured()) {
            return new SupplierSearchResult('not_configured', message: 'Brave Search API nu este configurat. Adaugă cheia API pentru a activa rezultatele live.');
        }

        $endpoint = config('services.brave_search.endpoint');
        if (! is_string($endpoint) || ! filter_var($endpoint, FILTER_VALIDATE_URL) || parse_url($endpoint, PHP_URL_SCHEME) !== 'https') {
            return new SupplierSearchResult('error', message: 'Brave Search API nu este configurat corect.');
        }

        $markets = [
            ['label' => 'România', 'query' => $this->romanianQuery($query), 'country' => 'RO', 'language' => 'ro'],
            ['label' => 'Uniunea Europeană', 'query' => $this->europeanQuery($query), 'country' => null, 'language' => 'en'],
        ];
        $items = [];
        $failedMarkets = [];

        foreach ($markets as $market) {
            try {
                $items = array_merge($items, $this->searchMarket($endpoint, $market));
            } catch (Throwable) {
                $failedMarkets[] = $market['label'];
            }
        }

        if (count($failedMarkets) === count($markets)) {
            return new SupplierSearchResult('error', message: 'Brave Search este momentan indisponibil. Încearcă din nou mai târziu.');
        }

        $message = $failedMarkets === []
            ? null
            : 'Rezultatele pentru '.implode(' și ', $failedMarkets).' nu au putut fi obținute momentan.';

        return new SupplierSearchResult('ok', $this->deduplicate($items), $message);
    }

    /**
     * @param  array{label: string, query: string, country: ?string, language: string}  $market
     * @return array<int, array{supplier: ?string, product: ?string, country: ?string, snippet: ?string, source: string, price: null, currency: null, availability: null, match_score: null, url: string}>
     */
    private function searchMarket(string $endpoint, array $market): array
    {
        $count = max(1, min(20, (int) config('services.brave_search.results_per_market', 10)));
        $parameters = array_filter([
            'q' => $market['query'],
            'count' => $count,
            'country' => $market['country'],
            'search_lang' => $market['language'],
            'safesearch' => 'moderate',
            'spellcheck' => true,
            'extra_snippets' => true,
        ], fn (mixed $value): bool => $value !== null);
        $cacheKey = 'supplier-search:brave:v1:'.hash('sha256', json_encode($parameters, JSON_THROW_ON_ERROR));
        $ttl = max(60, (int) config('services.brave_search.cache_ttl', 86400));

        $data = Cache::remember($cacheKey, $ttl, function () use ($endpoint, $parameters): array {
            $response = $this->request()->get($endpoint, $parameters);
            if (! $response->successful()) {
                throw new RuntimeException('Brave Search request failed.');
            }

            $data = $response->json();
            if (! is_array($data) || ! is_array(data_get($data, 'web.results'))) {
                throw new RuntimeException('Brave Search response is invalid.');
            }

            return $data;
        });

        $items = [];
        foreach (data_get($data, 'web.results', []) as $result) {
            if (! is_array($result)) {
                continue;
            }
            $url = $this->normalizeUrl($result['url'] ?? null);
            if ($url === null) {
                continue;
            }
            $host = $this->domain($url);
            $supplier = $this->cleanText(data_get($result, 'profile.long_name')) ?? $host;
            $snippets = array_merge([$result['description'] ?? null], is_array($result['extra_snippets'] ?? null) ? $result['extra_snippets'] : []);

            $items[] = [
                'supplier' => $supplier,
                'product' => $this->cleanText($result['title'] ?? null),
                'country' => $this->cleanText($result['country'] ?? null),
                'snippet' => $this->joinSnippets($snippets),
                'source' => 'Brave Search · '.$market['label'],
                'price' => null,
                'currency' => null,
                'availability' => null,
                'match_score' => null,
                'url' => $url,
            ];
        }

        return $items;
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()
            ->withHeaders(['X-Subscription-Token' => trim((string) config('services.brave_search.key'))])
            ->connectTimeout(max(1, (int) config('services.brave_search.connect_timeout', 3)))
            ->timeout(max(1, (int) config('services.brave_search.timeout', 10)))
            ->withOptions(['allow_redirects' => false]);
    }

    private function romanianQuery(string $query): string
    {
        return mb_substr(trim($query).' furnizor distribuitor producător România', 0, 400);
    }

    private function europeanQuery(string $query): string
    {
        $translated = str_ireplace(
            ['echipamente', 'mobilier', 'scaune', 'imprimante', 'calculatoare', 'consumabile medicale', 'panouri fotovoltaice', 'anvelope', 'autovehicule', 'servicii'],
            ['equipment', 'furniture', 'chairs', 'printers', 'computers', 'medical supplies', 'solar panels', 'tyres', 'vehicles', 'services'],
            $query,
        );

        return mb_substr(trim($translated).' supplier distributor manufacturer Europe EU', 0, 400);
    }

    /** @param array<int, mixed> $snippets */
    private function joinSnippets(array $snippets): ?string
    {
        $parts = [];
        foreach ($snippets as $snippet) {
            $clean = $this->cleanText($snippet);
            if ($clean !== null) {
                $parts[mb_strtolower($clean)] = $clean;
            }
        }

        return $parts === [] ? null : mb_substr(implode(' ', array_values($parts)), 0, 1000);
    }

    private function cleanText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');

        return $value === '' ? null : $value;
    }

    private function normalizeUrl(mixed $value): ?string
    {
        if (! is_string($value) || ! filter_var($value, FILTER_VALIDATE_URL) || ! in_array(parse_url($value, PHP_URL_SCHEME), ['http', 'https'], true)) {
            return null;
        }
        $parts = parse_url($value);
        if (! is_array($parts) || empty($parts['host'])) {
            return null;
        }
        parse_str($parts['query'] ?? '', $query);
        foreach (array_keys($query) as $key) {
            if (preg_match('/^(?:utm_.+|gclid|fbclid)$/i', (string) $key)) {
                unset($query[$key]);
            }
        }
        ksort($query);
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = $parts['path'] ?? '';

        return strtolower($parts['scheme']).'://'.strtolower($parts['host']).$port.$path.($query === [] ? '' : '?'.http_build_query($query));
    }

    /** @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function deduplicate(array $items): array
    {
        $unique = [];
        foreach ($items as $item) {
            $domain = $this->domain((string) $item['url']);
            $key = $domain !== null ? 'domain:'.$domain : 'url:'.$item['url'];
            $unique[$key] ??= $item;
        }

        return array_values($unique);
    }

    private function domain(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }

        return preg_replace('/^www\./i', '', strtolower($host));
    }
}
