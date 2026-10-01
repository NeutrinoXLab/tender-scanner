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
        'ADV' => ['api' => 'AdvNoticeCommon', 'id' => 'advNoticeId', 'type' => null, 'path' => 'adv-notices/view'],
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
        if ($family === 'ADV') {
            $payload = ['pageIndex' => $pageIndex, 'pageSize' => $pageSize,
                'publicationDateStart' => $from, 'publicationDateEnd' => $to];
        }
        if ($family === 'SCN') {
            $payload['sysNoticeType'] = ['id' => 17];
        }
        if ($cpv !== null) {
            $payload['cpv'] = $cpv;
        }
        $method = $family === 'ADV' ? 'GetAdvNoticeList' : 'GetAll';
        $request = $this->request();
        if ($family === 'ADV') {
            $request->replaceHeaders([
                'Referer' => 'https://e-licitatie.ro/pub/notices/adv-notices/',
                'Origin' => 'https://e-licitatie.ro',
                'Accept' => 'application/json, text/plain, */*',
            ]);
        }
        $data = $this->json($request->post($this->baseUrl.'/'.$config['api'].'/'.$method.'/', $payload));

        return $this->listingData($data);
    }

    protected function listingData(array $data): array
    {
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

    public function getAdvNoticeDetails(int $id): array
    {
        $request = $this->request();

        $request->replaceHeaders([
            'Referer' => 'https://e-licitatie.ro/pub/notices/adv-notices/',
            'Origin' => 'https://e-licitatie.ro',
            'Accept' => 'application/json, text/plain, */*',
        ]);

        $data = $this->json($request->get($this->baseUrl.'/PUBLICAdvNotice/getForView/'.$id));
        if (($data['advertisingNoticeId'] ?? null) !== $id || ! preg_match('/^ADV\d+$/', $data['noticeNo'] ?? '')) {
            throw new RuntimeException('Detaliu ADV invalid sau pentru alt identificator.');
        }

        return $data;
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
        if (config('services.seap_worker.server')) {
            throw new RuntimeException('SEAP live este dezactivat pe server; folosiți workerul autorizat.');
        }

        return ($request ?? Http::withOptions([]))->connectTimeout(10)->timeout(30)
            ->beforeSending(function (): void {
                Sleep::usleep(max(0, (int) config('services.seap.request_delay_ms', 1000)) * 1000);
            })
            ->retry(3, 500, function (Throwable $exception): bool {
                return $exception instanceof ConnectionException || ($exception instanceof RequestException
                    && $exception->response->serverError());
            }, throw: false)
            ->withHeaders(['Referer' => 'https://e-licitatie.ro/', 'Origin' => 'https://e-licitatie.ro',
                'Accept' => 'application/json, text/plain, */*']);
    }

    private function json(Response $response): array
    {
        if (in_array($response->status(), [403, 429], true)) {
            throw new SeapAccessRestricted('SEAP a raspuns HTTP '.$response->status().'. Scanarea SEAP a fost oprita imediat. Verificati accesul inainte de reluare.');
        }
        $data = $response->throw()->json();
        if (! is_array($data) || ($data['hasError'] ?? false)) {
            throw new RuntimeException('Raspuns JSON SEAP invalid sau cu eroare.');
        }

        return $data;
    }
}