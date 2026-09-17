<?php

namespace App\Services;

use RuntimeException;

class SeapWorkerClient extends SeapClient
{
    public ?array $pending = null;

    public bool $consumed = false;

    public function __construct(private ?array $expected = null, private ?array $response = null) {}

    private function receive(array $request): array
    {
        if (! $this->consumed && $this->expected === $request && $this->response !== null) {
            $this->consumed = true;
            if ($this->response['status'] !== 'ok') {
                throw new SeapAccessRestricted('Workerul SEAP s-a oprit: '.$this->response['status'].'.');
            }

            return $this->response['data'];
        }
        $this->pending ??= $request;
        throw new SeapRequestPending($request['kind'] === 'listing');
    }

    public function getFamilyNotices(string $family, int $pageSize, int $pageIndex, ?string $from, ?string $to, ?string $cpv = null): array
    {
        return $this->listingData($this->receive(['kind' => 'listing', 'family' => $family, 'page_size' => $pageSize, 'page' => $pageIndex, 'from' => $from, 'to' => $to]));
    }

    public function getNoticeDetails(int $piNoticeId): array
    {
        return $this->receive(['kind' => 'pi_detail', 'family' => 'PI', 'id' => $piNoticeId]);
    }

    public function getAdvNoticeDetails(int $id): array
    {
        return $this->receive(['kind' => 'adv_detail', 'family' => 'ADV', 'id' => $id]);
    }

    public function getProcedureDetails(string $family, array $items): array
    {
        return $this->receive(['kind' => 'procedure_details', 'family' => $family, 'items' => $items]);
    }

    public static function execute(SeapClient $client, array $request): array
    {
        return match ($request['kind']) {
            'listing' => $client->getFamilyNotices($request['family'], $request['page_size'], $request['page'], $request['from'], $request['to']),
            'pi_detail' => $client->getNoticeDetails($request['id']),
            'adv_detail' => $client->getAdvNoticeDetails($request['id']),
            'procedure_details' => $client->getProcedureDetails($request['family'], $request['items']),
            default => throw new RuntimeException('Operație worker necunoscută.'),
        };
    }
}
