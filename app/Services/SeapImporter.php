<?php

namespace App\Services;

use App\Models\NoticeSource;
use App\Models\ScanRun;
use Carbon\CarbonImmutable;
use Closure;
use RuntimeException;
use Throwable;

class SeapImporter
{
    public function __construct(private SeapClient $client, private SeapNormalizer $normalizer, private NoticeIngestor $ingestor) {}

    /** @return array<string, array<string, mixed>> */
    public function scan(?string $from, ?string $to, array $families, Closure $progress, bool $probe = false, int $limit = 0): array
    {
        $report = [];
        $restricted = false;
        $context = app(ScanContext::class);
        $backfill = $context->runId !== null && ScanRun::findOrFail($context->runId)->mode === 'backfill';
        $batchSize = ! $probe && $limit === 0 && $context->runId !== null
            ? max(1, (int) config('services.seap.batch_size', 100)) : PHP_INT_MAX;
        $attempted = 0;
        $listingRequests = 0;
        $requestedFrom = $from;
        $requestedTo = $to;
        foreach ($families as $family) {
            $requiresWorkerCnDetails = $family === 'CN' && $this->client instanceof SeapWorkerClient;
            $fetchProcedureDetails = $requiresWorkerCnDetails || config('services.seap.fetch_procedure_details', false);
            $range = $context->runId !== null ? (ScanRun::findOrFail($context->runId)->checkpoint['_ranges'][$family] ?? []) : [];
            $from = $range['from'] ?? $requestedFrom;
            $to = $range['to'] ?? $requestedTo;
            $pageSize = $family === 'ADV' ? 100 : 18;
            if ($probe) {
                $pageSize = $family === 'ADV' ? 5 : 1;
            } elseif ($limit > 0) {
                $pageSize = $family === 'ADV' ? ($limit <= 5 ? 5 : ($limit <= 10 ? 10 : 25)) : min($limit, 18);
            }
            $stats = ['reported' => 0, 'fetched' => 0, 'processed' => 0, 'new' => 0, 'updated' => 0,
                'unchanged' => 0, 'duplicates' => 0, 'skipped' => 0, 'unprocessed' => 0, 'detail_errors' => 0, 'errors' => [], 'complete' => true,
                'status' => 'running', 'from' => $from, 'to' => $to, 'listing_requests' => 0];
            $context = app(ScanContext::class);
            $saved = $probe || $limit > 0 ? [] : $context->checkpoint($family);
            $completedWindows = $saved['windows'] ?? [];
            $completedIds = $saved['ids'] ?? [];
            $totals = $saved['totals'] ?? [];
            $outcomes = $saved['outcomes'] ?? [];
            $pending = $saved['pending'] ?? [];
            $cursor = $saved['cursor'] ?? null;
            $paused = false;
            if (isset($saved['stats'])) {
                $stats = array_replace($stats, $saved['stats'], ['errors' => [], 'complete' => true, 'status' => 'running']);
            }
            $refreshDay = in_array($family, ['CN', 'SCN'], true) && ! $probe && $limit === 0 && $context->runId !== null
                ? (ScanRun::findOrFail($context->runId)->checkpoint['_refresh_day'] ?? null) : null;
            $stats['batch_attempted'] = 0;
            $stats['batch_listing_requests'] = 0;
            $stats['refresh_day'] = $refreshDay;
            $stats['cached'] ??= 0;
            $stats['resumed'] = count($completedIds);
            $save = function () use ($context, $family, &$stats, &$completedWindows, &$completedIds, &$totals, &$outcomes, &$pending, &$cursor, $probe, $limit): void {
                if (! $probe && $limit === 0) {
                    $context->saveCheckpoint($family, ['windows' => $completedWindows, 'ids' => $completedIds, 'stats' => $stats, 'totals' => $totals, 'outcomes' => $outcomes, 'pending' => $pending, 'cursor' => $cursor]);
                }
            };
            $recordOutcome = function (string $id, string $outcome) use (&$stats, &$outcomes, &$pending): void {
                $previous = $outcomes[$id] ?? null;
                $outcomes[$id] = $previous === 'new' || ($previous === 'updated' && $outcome === 'unchanged') ? $previous : $outcome;
                $stats['processed'] = count($outcomes);
                foreach (['new', 'updated', 'unchanged'] as $kind) {
                    $stats[$kind] = count(array_filter($outcomes, fn (string $value): bool => $value === $kind));
                }
                unset($pending[$id]);
            };
            $publish = function (string $message) use ($family, &$stats, $progress): void {
                app(ScanContext::class)->familyProgress($family, $stats);
                $progress($message, $family, $stats);
            };
            $save();
            $publish($family.' — pornire');
            $seen = [];
            try {
                if ($restricted) {
                    throw new SeapAccessRestricted('Familie neinterogata: accesul SEAP este restrictionat.');
                }
                $windows = $probe || $limit > 0 ? [[
                    $from ? CarbonImmutable::parse($from, 'Europe/Bucharest')->startOfDay()->format($family === 'SCN' ? 'Y-m-d H:i:s' : DATE_ATOM) : null,
                    $to ? $this->windowEnd($family, CarbonImmutable::parse($to, 'Europe/Bucharest')) : null,
                ]] : $this->windows($family, $from, $to);
                if ($refreshDay !== null && $family !== 'PI') {
                    $windows = array_merge($windows, $this->windows($family, $refreshDay, $refreshDay));
                }
                foreach ($windows as [$start, $end]) {
                    if (in_array($start, $completedWindows, true)) {
                        continue;
                    }
                    if (! $backfill && ! $probe && $limit === 0 && in_array($family, ['CN', 'SCN'], true) && $start !== null
                        && $pending === []
                        && ! $fetchProcedureDetails
                        && substr($start, 0, 10) !== $refreshDay
                        && app(SeapBulkSync::class)->covers(substr($start, 0, 10))) {
                        $completedWindows[] = $start;
                        $stats['bulk_days'] = ($stats['bulk_days'] ?? 0) + 1;
                        $cursor = null;
                        $save();

                        continue;
                    }
                    $windowErrors = count($stats['errors']);
                    if ($cursor === null || ($cursor['day'] ?? null) !== $start) {
                        $cursor = ['day' => $start, 'page' => 0, 'received' => 0, 'data' => null];
                    }
                    $page = $cursor['page'];
                    $received = $cursor['received'];
                    do {
                        if ($cursor['data'] === null) {
                            if ($attempted >= $batchSize || $listingRequests >= $batchSize) {
                                $paused = true;
                                break 2;
                            }
                            $stats['listing_requests']++;
                            $stats['batch_listing_requests']++;
                            $listingRequests++;
                            $data = $family === 'PI'
                                ? $this->client->getOpenNotices($pageSize, $page, null, $start, $end)
                                : $this->client->getFamilyNotices($family, $pageSize, $page, $start, $end);
                            if ($page === 0) {
                                $totals[$start ?? 'all'] = $data['total'];
                                $stats['reported'] = array_sum($totals);
                            }
                            $data['items'] = $limit > 0 ? array_slice($data['items'], 0, $limit) : $data['items'];
                            if ($data['items'] === [] && $received < $data['total']) {
                                throw new RuntimeException('Pagina goala inainte de totalul raportat de SEAP.');
                            }
                            $received += count($data['items']);
                            $stats['fetched'] += count($data['items']);
                            $cursor = ['day' => $start, 'page' => $page, 'received' => $received, 'data' => $data];
                            $save();
                        }
                        $total = $cursor['data']['total'];
                        $items = $cursor['data']['items'];
                        if ($probe) {
                            break;
                        }
                        $unique = [];
                        foreach ($items as $item) {
                            $noticeType = $item['sysNoticeTypeId'] ?? data_get($item, 'sysNoticeType.id');
                            if ($family !== 'ADV' && $noticeType !== null && (int) $noticeType !== SeapClient::FAMILIES[$family]['type']) {
                                $stats['skipped']++;
                                $this->error($stats, 'Tip diferit de familia solicitata returnat de SEAP; anuntul nu a fost importat.');

                                continue;
                            }
                            $id = $item[SeapClient::FAMILIES[$family]['id']] ?? null;
                            if (! is_numeric($id) || (int) $id <= 0) {
                                $stats['skipped']++;
                                $this->error($stats, $family.' anunt fara identificator valid.');

                                continue;
                            }
                            if (isset($saved['ids'][(string) $id])) {
                                continue;
                            }
                            if (isset($seen[(string) $id])) {
                                $stats['duplicates']++;

                                continue;
                            }
                            $seen[(string) $id] = true;
                            $unique[] = $item;
                            $pending[(string) $id] = true;
                        }

                        foreach ($unique as $item) {
                            $id = (string) $item[SeapClient::FAMILIES[$family]['id']];
                            if ($attempted >= $batchSize) {
                                $paused = true;
                                break 3;
                            }
                            $attempted++;
                            $stats['batch_attempted']++;
                            try {
                                $externalId = $family === 'PI' ? $id : $family.':'.$id;
                                $existing = NoticeSource::where('source', 'seap')->where('external_id', $externalId)->first();
                                if ($limit === 0 && $existing && ($existing->payload['listing'] ?? null) === $item
                                    && ($existing->payload['_details_complete'] ?? false)
                                    && $existing->last_seen_at?->gt(now()->subDays(3))) {
                                    $context->record('seap', $externalId, $existing->notice_id, false);
                                    $recordOutcome($id, 'unchanged');
                                    $stats['cached']++;
                                    $completedIds[$id] = true;
                                    $save();
                                    $publish($family.' '.$id.' — detalii recente, lista neschimbata');

                                    continue;
                                }
                                $itemErrors = count($stats['errors']);
                                $payload = [];
                                if ($family === 'PI') {
                                    try {
                                        $payload = $this->client->getNoticeDetails((int) $id);
                                    } catch (SeapAccessRestricted|SeapRequestPending $exception) {
                                        throw $exception;
                                    } catch (Throwable $exception) {
                                        $stats['detail_errors']++;
                                        $this->error($stats, $exception->getMessage());
                                    }
                                } elseif ($family === 'ADV') {
                                    if ($limit > 0 || config('services.seap.fetch_adv_details', false)) {
                                        $payload = $this->client->getAdvNoticeDetails((int) $id);
                                    }
                                } elseif ($limit > 0 || $fetchProcedureDetails) {
                                    $details = $this->client->getProcedureDetails($family, [$item]);
                                    $payload = $details[$id]['data'] ?? [];
                                    foreach ($details[$id]['errors'] ?? [] as $error) {
                                        $stats['detail_errors']++;
                                        $this->error($stats, $error);
                                    }
                                    if ($requiresWorkerCnDetails) {
                                        $missingSections = array_diff(['general', 'section1', 'section21', 'section4'], array_keys($payload));
                                        $description = data_get($payload, 'section21.shortDescription');
                                        $noticeNumber = data_get($payload, 'section21.noticeNo');
                                        if ($missingSections !== []) {
                                            $stats['detail_errors']++;
                                            $this->error($stats, 'CN '.$id.': lipsesc secțiunile '.implode(', ', $missingSections).'.');
                                        }
                                        if (! is_string($description) || trim(strip_tags($description)) === '') {
                                            $stats['detail_errors']++;
                                            $this->error($stats, 'CN '.$id.': descrierea nu este disponibilă în section21.');
                                        }
                                        if (! is_string($noticeNumber) || ! preg_match('/^CN\d+$/', $noticeNumber)) {
                                            $stats['detail_errors']++;
                                            $this->error($stats, 'CN '.$id.': section21 nu confirmă numărul anunțului.');
                                        }
                                    }
                                }
                                $attributes = $this->normalizer->normalize($family, $item, $payload);
                                $contractType = $attributes['seap_metadata']['contract_type_id'];
                                if ($contractType !== null && ! in_array((int) $contractType, [1, 2, 3], true)) {
                                    $stats['skipped']++;
                                    unset($pending[$id]);
                                    $this->error($stats, $family.' '.$id.': tip de contract neconfirmat ca furnizare, servicii sau lucrari; nu a fost importat.');

                                    continue;
                                }
                                $externalId = $family === 'PI' ? $id : $family.':'.$id;
                                $before = NoticeSource::where('source', 'seap')->where('external_id', $externalId)->value('fingerprint');
                                $detailsRequested = $family === 'PI' || $limit > 0 || ($family === 'ADV'
                                    ? config('services.seap.fetch_adv_details', false) : $fetchProcedureDetails);
                                $detailsComplete = $detailsRequested && count($stats['errors']) === $itemErrors;
                                $notice = $this->ingestor->ingest('seap', $externalId, $attributes, $payload + ['listing' => $item, '_details_complete' => $detailsComplete]);
                                $after = NoticeSource::where('source', 'seap')->where('external_id', $externalId)->value('fingerprint');
                                if (! $requiresWorkerCnDetails || $detailsComplete) {
                                    $recordOutcome($id, $notice->wasRecentlyCreated ? 'new' : ($before !== $after ? 'updated' : 'unchanged'));
                                }
                                if (count($stats['errors']) === $itemErrors) {
                                    $completedIds[$id] = true;
                                }
                                $save();
                                $publish($family.' '.$id.' — procesat');
                            } catch (SeapRequestPending $exception) {
                                $attempted--;
                                $stats['batch_attempted']--;
                                throw $exception;
                            } catch (SeapAccessRestricted $exception) {
                                throw $exception;
                            } catch (Throwable $exception) {
                                $stats['skipped']++;
                                $this->error($stats, $family.' '.$id.': '.$exception->getMessage());
                            }
                        }
                        if ($limit > 0) {
                            break;
                        }
                        if (count($stats['errors']) > $windowErrors) {
                            break 2;
                        }
                        $page++;
                        $cursor = ['day' => $start, 'page' => $page, 'received' => $received, 'data' => null];
                        $save();
                        $stats['unprocessed'] = count($pending);
                        $publish($family.' '.($start ?? 'toate datele').' pagina '.$page.' / '.(int) ceil($total / $pageSize).' — procesate '.$stats['processed']);
                        if ($page >= 1000 && $received < $total) {
                            throw new RuntimeException('Limita de siguranta a paginarii SEAP a fost atinsa.');
                        }
                    } while ($received < $total);
                    if (! $probe && $limit === 0 && count($stats['errors']) === $windowErrors) {
                        if (in_array($family, ['CN', 'SCN'], true) && $start !== null) {
                            app(SeapBulkSync::class)->verifiedDay($family, substr($start, 0, 10));
                        }
                        $completedWindows[] = $start;
                        $cursor = null;
                        $save();
                    }
                }
            } catch (SeapRequestPending $exception) {
                $paused = true;
                if ($exception->listing) {
                    $stats['listing_requests']--;
                    $stats['batch_listing_requests']--;
                }
            } catch (Throwable $exception) {
                $this->error($stats, $exception->getMessage());
                $restricted = $restricted || $exception instanceof SeapAccessRestricted;
            }
            $stats['unprocessed'] = count($pending);
            if ($pending !== [] && $stats['complete'] && ! $paused) {
                $this->error($stats, 'Exista anunturi din progresul salvat care nu au putut fi reverificate.');
            }
            $stats['status'] = $stats['complete'] ? ($probe ? 'probe_ok' : ($limit > 0 ? 'sample_ok' : 'completed')) : ($stats['listing_requests'] === 0 ? 'not_run' : 'error');
            if ($paused) {
                $stats['status'] = 'paused';
                $stats['complete'] = false;
            }
            if ($probe || $limit > 0) {
                $stats['complete'] = false;
            }
            $save();
            $report[$family] = $stats;
            $publish($family.' — '.$stats['status']);
        }
        app(ScanContext::class)->families = $report;

        return $report;
    }

    /** @return array<array{?string, ?string}> */
    private function windows(string $family, ?string $from, ?string $to): array
    {
        if ($from === null || $to === null) {
            return [[$from, $to]];
        }
        $windows = [];
        for ($day = CarbonImmutable::parse($from, 'Europe/Bucharest'); $day->toDateString() <= $to; $day = $day->addDay()) {
            $windows[] = [$day->startOfDay()->format($family === 'SCN' ? 'Y-m-d H:i:s' : DATE_ATOM), $this->windowEnd($family, $day)];
        }

        return $windows;
    }

    private function windowEnd(string $family, CarbonImmutable $day): string
    {
        return $family === 'SCN'
            ? $day->startOfDay()->addDay()->format('Y-m-d H:i:s')
            : $day->endOfDay()->toIso8601String();
    }

    private function error(array &$stats, string $message): void
    {
        $stats['complete'] = false;
        $stats['errors'][] = $message;
        app(ScanContext::class)->error($message);
    }
}
