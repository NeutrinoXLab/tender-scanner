<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Throwable;

class PriceScannerSupplierProvider implements SupplierSearchProvider
{
    public function isConfigured(): bool
    {
        return is_string(config('services.price_scanner.search_url')) && trim(config('services.price_scanner.search_url')) !== '';
    }

    public function search(string $query, array $context): SupplierSearchResult
    {
        if (! $this->isConfigured()) {
            return new SupplierSearchResult('not_configured', message: 'Nu este configurată încă o sursă live de furnizori. Poți pregăti și modifica termenii de căutare.');
        }
        $endpoint = config('services.price_scanner.search_url');
        if (! filter_var($endpoint, FILTER_VALIDATE_URL) || ! in_array(parse_url($endpoint, PHP_URL_SCHEME), ['http', 'https'], true)) {
            return new SupplierSearchResult('error', message: 'Sursa de furnizori nu este configurată corect.');
        }
        try {
            $request = Http::acceptJson()->asJson()->connectTimeout(3)->timeout(10)->withOptions(['allow_redirects' => false]);
            if ($token = config('services.price_scanner.token')) {
                $request = $request->withToken($token);
            }
            $response = $request->post($endpoint, ['query' => $query, 'context' => $context]);
            if (! $response->successful()) {
                return new SupplierSearchResult('error', message: 'Sursa de furnizori este momentan indisponibilă. Încearcă din nou mai târziu.');
            }
            $data = $response->json();
            if (! is_array($data) || ! isset($data['items']) || ! is_array($data['items']) || ! array_is_list($data['items'])) {
                return new SupplierSearchResult('error', message: 'Sursa de furnizori a trimis un răspuns nevalid.');
            }
            $validator = Validator::make($data, [
                'items' => ['array', 'max:100'],
                'items.*' => ['array'],
                'items.*.supplier' => ['nullable', 'string', 'max:300'],
                'items.*.product' => ['nullable', 'string', 'max:500'],
                'items.*.price' => ['nullable', 'numeric', 'min:0'],
                'items.*.currency' => ['nullable', 'string', 'regex:/^[A-Z]{3}$/'],
                'items.*.availability' => ['nullable', 'string', 'max:200'],
                'items.*.match_score' => ['nullable', 'numeric', 'between:0,1'],
                'items.*.url' => ['nullable', 'string', 'max:2048'],
            ]);
            if ($validator->fails()) {
                return new SupplierSearchResult('error', message: 'Sursa de furnizori a trimis rezultate într-un format nevalid.');
            }
            $items = [];
            foreach ($data['items'] as $item) {
                $url = $item['url'] ?? null;
                if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
                    $url = null;
                }
                $items[] = [
                    'supplier' => $item['supplier'] ?? null, 'product' => $item['product'] ?? null,
                    'price' => isset($item['price']) ? (string) $item['price'] : null,
                    'currency' => $item['currency'] ?? null, 'availability' => $item['availability'] ?? null,
                    'match_score' => isset($item['match_score']) ? (float) $item['match_score'] : null, 'url' => $url,
                ];
            }

            return new SupplierSearchResult('ok', $items);
        } catch (Throwable) {
            return new SupplierSearchResult('error', message: 'Nu am putut contacta sursa de furnizori. Încearcă din nou mai târziu.');
        }
    }
}
