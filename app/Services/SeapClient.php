<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class SeapClient
{
    public const FAMILIES = [
        'PI' => ['api' => 'PUBLICPINotice', 'id' => 'piNoticeId', 'type' => 1, 'path' => 'pi-notice/view/v2'],
        'CN' => ['api' => 'PUBLICCNotice', 'id' => 'cNoticeID', 'type' => 2, 'path' => 'c-notice/v2/view'],
        'SCN' => ['api' => 'PublicRFQInvitation', 'id' => 'rfqInvitationId', 'type' => 17, 'path' => 'simplified-notice/v2/view'],
    ];

    private string $baseUrl = 'https://e-licitatie.ro/api-pub';

    public function getOpenNotices(int $pageSize = 18, int $pageIndex = 0, ?string $cpv = null, ?string $publicationDateFrom = null, ?string $publicationDateTo = null): array
    {
        return $this->getFamilyNotices('PI', $pageSize, $pageIndex, $publicationDateFrom, $publicationDateTo, $cpv);
    }

    public function getFamilyNotices(string $family, int $pageSize, int $pageIndex, ?string $from, ?string $to, ?string $cpv = null): array
    {
        $config = self::FAMILIES[$family] ?? throw new InvalidArgumentException('Familie SEAP nesuportata.');
        $payload = ['pageIndex' => $pageIndex, 'pageSize' => $pageSize, 'sortProperties' => [],
            'publicationDateFrom' => $from, 'publicationDateTo' => $to];
        if ($family === 'SCN') {
            $payload['sysNoticeType'] = ['id' => 17];
        }
        if ($cpv !== null) {
            $payload['cpv'] = $cpv;
        }
        $data = $this->json($this->request()->post($this->baseUrl.'/'.$config['api'].'/GetAll/', $payload));
        if (! isset($data['total'], $data['items']) || ! is_int($data['total']) || $data['total'] < 0 || ! is_array($data['items']) || ! array_is_list($data['items'])) {
            throw new RuntimeException('Raspuns de listare SEAP invalid.');
        }
        if ($data['searchTooLong'] ?? false) {
            throw new RuntimeException('SEAP a trunchiat cautarea; restrangeti intervalul.');
        }

        return $data;
    }

    public function getNoticeDetails(int $piNoticeId): array
    {
        return $this->json($this->request()->get($this->baseUrl.'/PUBLICPINotice/getView/'.$piNoticeId));
    }

    /** @return array<string, array{data: array, errors: array<string>}> */
    public function getProcedureDetails(string $family, array $items): array
    {
        $config = self::FAMILIES[$family] ?? throw new InvalidArgumentException('Familie SEAP nesuportata.');
        $result = [];
        foreach ($items as $item) {
            $id = (string) $item[$config['id']];
            $result[$id] = ['data' => [], 'errors' => []];
            $query = ['initNoticeId' => $id, 'sysNoticeTypeId' => $config['type']];
            $requests = [
                'general' => ['ComboPub/getNoticeGeneralInfo/', ['noticeId' => $id, 'sysNoticeTypeId' => $config['type']]],
                'section1' => ['NoticeCommon/GetSection1View/', $query],
                'section21' => ['NoticeCommon/GetSection21View/', $query + ['dfNoticeId' => $item['dfNoticeId'] ?? 0]],
                'section4' => ['NoticeCommon/GetSection4View/', $query],
            ];
            foreach ($requests as $section => [$path, $params]) {
                try {
                    $result[$id]['data'][$section] = $this->json($this->request()->get($this->baseUrl.'/'.$path, $params));
                } catch (SeapAccessRestricted $exception) {
                    throw $exception;
                } catch (Throwable $exception) {
                    $result[$id]['errors'][] = $family.' '.$id.' '.$section.': '.$exception->getMessage();
                }
            }
        }

        return $result;
    }

    private function request(?PendingRequest $request = null): PendingRequest
    {
        return ($request ?? Http::withOptions([]))->connectTimeout(10)->timeout(30)
            ->beforeSending(function (): void {
                Sleep::usleep(max(0, (int) config('services.seap.request_delay_ms', 1000)) * 1000);
            })
            ->retry(3, 500, function (Throwable $exception): bool {
                return $exception instanceof ConnectionException || ($exception instanceof RequestException
                    && ($exception->response->status() === 429 || $exception->response->serverError()));
            }, throw: false)
            ->withHeaders(['Referer' => 'https://e-licitatie.ro/', 'Origin' => 'https://e-licitatie.ro',
                'Accept' => 'application/json, text/plain, */*']);
    }

    private function json(Response $response): array
    {
        if ($response->status() === 403 && str_contains($response->body(), 'Acces restric')) {
            throw new SeapAccessRestricted('SEAP a restrictionat adresa IP din cauza traficului automat. Scanarea SEAP a fost oprita; este necesara deblocarea prin suportul SEAP.');
        }
        $data = $response->throw()->json();
        if (! is_array($data) || ($data['hasError'] ?? false)) {
            throw new RuntimeException('Raspuns JSON SEAP invalid sau cu eroare.');
        }

        return $data;
    }
}
