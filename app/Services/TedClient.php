<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class TedClient
{
    public function search(string $from, string $to, int $page = 1, int $limit = 50): array
    {
        $payload = [
            'query' => 'buyer-country = ROU AND publication-date >= '.str_replace('-', '', $from)
                .' AND publication-date <= '.str_replace('-', '', $to).' SORT BY publication-number DESC',
            'fields' => ['publication-number', 'notice-title', 'publication-date', 'buyer-name',
                'classification-cpv', 'description-proc', 'description-lot', 'deadline-receipt-tender-date-lot',
                'deadline-receipt-tender-time-lot', 'estimated-value-proc', 'estimated-value-cur-proc',
                'buyer-city', 'place-of-performance', 'procedure-type', 'contract-nature', 'notice-type',
                'submission-url-lot'],
            'limit' => $limit, 'scope' => 'ALL', 'paginationMode' => 'PAGE_NUMBER', 'page' => $page,
        ];

        $data = Http::acceptJson()->connectTimeout(15)->timeout(60)->retry(3, 1000)
            ->post('https://api.ted.europa.eu/v3/notices/search', $payload)->throw()->json();
        if (! is_array($data) || ! is_array($data['notices'] ?? null)
            || ! is_numeric($data['totalNoticeCount'] ?? null) || ($data['timedOut'] ?? false)) {
            throw new RuntimeException('TED a returnat un răspuns incomplet. Reîncercați importul.');
        }

        return $data;
    }
}
