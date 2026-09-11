<?php

namespace App\Services;

use App\Models\NoticeSource;
use Carbon\CarbonImmutable;
use Closure;
use RuntimeException;
use Throwable;

class SeapImporter
{
    public function __construct(private SeapClient $client, private SeapNormalizer $normalizer, private NoticeIngestor $ingestor) {}

    /** @return array<string, array<string, mixed>> */
    public function scan(?string $from, ?string $to, array $families, Closure $progress): array
    {
        $report = [];
        $restricted = false;
        foreach ($families as $family) {
            $stats = ['reported' => 0, 'fetched' => 0, 'processed' => 0, 'new' => 0, 'updated' => 0,
                'unchanged' => 0, 'duplicates' => 0, 'skipped' => 0, 'unprocessed' => 0, 'detail_errors' => 0, 'errors' => [], 'complete' => true];
            $seen = [];
            try {
                if ($restricted) {
                    throw new SeapAccessRestricted('Familie neinterogata: accesul SEAP este restrictionat.');
                }
                foreach ($this->windows($from, $to) as [$start, $end]) {
                    $page = 0;
                    $received = 0;
                    do {
                        $data = $family === 'PI'
                            ? $this->client->getOpenNotices(18, $page, null, $start, $end)
                            : $this->client->getFamilyNotices($family, 18, $page, $start, $end);
                        $total = $data['total'];
                        if ($page === 0) {
                            $stats['reported'] += $total;
                        }
                        $items = $data['items'];
                        if ($items === [] && $received < $total) {
                            throw new RuntimeException('Pagina goala inainte de totalul raportat de SEAP.');
                        }
                        $received += count($items);
                        $stats['fetched'] += count($items);
                        $unique = [];
                        foreach ($items as $item) {
                            if ($family === 'SCN' && isset($item['sysNoticeType']['id']) && $item['sysNoticeType']['id'] !== 17) {
                                $stats['skipped']++;
                                $this->error($stats, 'Tip diferit de SCN returnat de SEAP; anuntul nu a fost importat.');

                                continue;
                            }
                            $id = $item[SeapClient::FAMILIES[$family]['id']] ?? null;
                            if (! is_numeric($id) || (int) $id <= 0) {
                                $stats['skipped']++;
                                $this->error($stats, $family.' anunt fara identificator valid.');

                                continue;
                            }
                            if (isset($seen[(string) $id])) {
                                $stats['duplicates']++;

                                continue;
                            }
                            $seen[(string) $id] = true;
                            $unique[] = $item;
                        }
                        $details = $family === 'PI' || $unique === [] ? [] : $this->client->getProcedureDetails($family, $unique);
                        foreach ($unique as $item) {
                            $id = (string) $item[SeapClient::FAMILIES[$family]['id']];
                            try {
                                $payload = [];
                                if ($family === 'PI') {
                                    try {
                                        $payload = $this->client->getNoticeDetails((int) $id);
                                    } catch (SeapAccessRestricted $exception) {
                                        throw $exception;
                                    } catch (Throwable $exception) {
                                        $stats['detail_errors']++;
                                        $this->error($stats, $exception->getMessage());
                                    }
                                } else {
                                    $payload = $details[$id]['data'] ?? [];
                                    foreach ($details[$id]['errors'] ?? [] as $error) {
                                        $stats['detail_errors']++;
                                        $this->error($stats, $error);
                                    }
                                }
                                $attributes = $this->normalizer->normalize($family, $item, $payload);
                                $externalId = $family === 'PI' ? $id : $family.':'.$id;
                                $before = NoticeSource::where('source', 'seap')->where('external_id', $externalId)->value('fingerprint');
                                $notice = $this->ingestor->ingest('seap', $externalId, $attributes, $payload + ['listing' => $item]);
                                $after = NoticeSource::where('source', 'seap')->where('external_id', $externalId)->value('fingerprint');
                                $stats['processed']++;
                                $stats[$notice->wasRecentlyCreated ? 'new' : ($before !== $after ? 'updated' : 'unchanged')]++;
                            } catch (SeapAccessRestricted $exception) {
                                throw $exception;
                            } catch (Throwable $exception) {
                                $stats['skipped']++;
                                $this->error($stats, $family.' '.$id.': '.$exception->getMessage());
                            }
                        }
                        $page++;
                        $stats['unprocessed'] = $stats['fetched'] - $stats['processed'] - $stats['duplicates'] - $stats['skipped'];
                        $progress($family.' '.($start ?? 'toate datele').' pagina '.$page.' / '.(int) ceil($total / 18).' — procesate '.$stats['processed'], $family, $stats);
                        if ($page >= 1000 && $received < $total) {
                            throw new RuntimeException('Limita de siguranta a paginarii SEAP a fost atinsa.');
                        }
                    } while ($received < $total);
                }
            } catch (Throwable $exception) {
                $this->error($stats, $exception->getMessage());
                $restricted = $restricted || $exception instanceof SeapAccessRestricted;
            }
            $stats['unprocessed'] = $stats['fetched'] - $stats['processed'] - $stats['duplicates'] - $stats['skipped'];
            $report[$family] = $stats;
            $progress($family.' — '.($stats['complete'] ? 'complet' : 'incomplet'), $family, $stats);
        }
        app(ScanContext::class)->families = $report;

        return $report;
    }

    /** @return array<array{?string, ?string}> */
    private function windows(?string $from, ?string $to): array
    {
        if ($from === null || $to === null) {
            return [[$from, $to]];
        }
        $windows = [];
        for ($day = CarbonImmutable::parse($from, 'Europe/Bucharest'); $day->toDateString() <= $to; $day = $day->addDay()) {
            $windows[] = [$day->startOfDay()->toIso8601String(), $day->endOfDay()->toIso8601String()];
        }

        return $windows;
    }

    private function error(array &$stats, string $message): void
    {
        $stats['complete'] = false;
        $stats['errors'][] = $message;
        app(ScanContext::class)->error($message);
    }
}
