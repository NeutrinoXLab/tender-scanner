<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class SeapClient
{
    private string $baseUrl = 'https://e-licitatie.ro/api-pub';

    public function getOpenNotices(
        int $pageSize = 18,
        int $pageIndex = 0,
        ?string $cpv = null,
        ?string $publicationDateFrom = null,
        ?string $publicationDateTo = null
    ): array {
        $payload = [
            'pageIndex' => $pageIndex,
            'pageSize' => $pageSize,
            'sortProperties' => [],
            'cpv' => $cpv,
            'cpvCode' => $cpv
                ? [
                    'id' => 16110,
                    'text' => $cpv,
                    'localeKey' => null,
                    'apiActionResult' => null,
                ]
                : null,
            'publicationDateFrom' => $publicationDateFrom,
            'publicationDateTo' => $publicationDateTo,
        ];

        $response = Http::timeout(30)
            ->withHeaders([
                'Referer' => 'https://e-licitatie.ro/',
                'Origin' => 'https://e-licitatie.ro',
                'Accept' => 'application/json, text/plain, */*',
                'Content-Type' => 'application/json',
            ])
            ->post(
                $this->baseUrl . '/PUBLICPINotice/GetAll/',
                $payload
            );

        $response->throw();

        return $response->json();
    }

    public function getNoticeDetails(int $piNoticeId): array
    {
        $response = Http::timeout(30)
            ->withHeaders([
                'Referer' => 'https://e-licitatie.ro/',
                'Origin' => 'https://e-licitatie.ro',
                'Accept' => 'application/json, text/plain, */*',
            ])
            ->get(
                $this->baseUrl . '/PUBLICPINotice/getView/' . $piNoticeId
            );

        $response->throw();

        return $response->json();
    }
}