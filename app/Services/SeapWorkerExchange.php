<?php

namespace App\Services;

use App\Models\ScanRun;
use App\Models\SeapWorkerBatch;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SeapWorkerExchange
{
    public function claim(): ?SeapWorkerBatch
    {
        return Cache::lock('seap-worker-exchange', 120)->block(5, function (): ?SeapWorkerBatch {
            if ($pending = SeapWorkerBatch::whereNull('response_hash')->oldest()->first()) {
                abort_if(ScanRun::findOrFail($pending->scan_run_id)->status === 'cancelled', 409, 'Scanare anulată.');

                return $pending;
            }
            $run = app(ScanDispatcher::class)->dispatch(seapOnly: true);
            if (! ($run->checkpoint['_worker_ready'] ?? false) || ! $run->batchAvailable() || $run->seapComplete()) {
                return null;
            }

            return DB::transaction(function () use ($run): ?SeapWorkerBatch {
                $run = ScanRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
                $checkpoint = $run->checkpoint ?? [];
                if (isset($checkpoint['_next_batch_at'])) {
                    $checkpoint['_worker_used'] = 0;
                    $checkpoint['_worker_requests'] = 0;
                    unset($checkpoint['_next_batch_at'], $checkpoint['_worker_options']);
                }
                $run->update(['checkpoint' => $checkpoint]);
                $client = new SeapWorkerClient;
                $this->advance($run, $client);

                return $client->pending && $run->fresh()->status === 'running'
                    ? SeapWorkerBatch::create(['id' => (string) Str::uuid(), 'active_key' => 'worker', 'scan_run_id' => $run->id, 'request' => $client->pending])
                    : null;
            });
        });
    }

    public function accept(string $id, array $payload): array
    {
        return Cache::lock('seap-worker-exchange', 120)->block(5, fn (): array => DB::transaction(function () use ($id, $payload): array {
            $batch = SeapWorkerBatch::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->validate($batch, $payload);
            $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
            if ($batch->response_hash !== null) {
                abort_unless(hash_equals($batch->response_hash, $hash), 409, 'Lot confirmat cu alt conținut.');

                return $batch->receipt;
            }
            $run = ScanRun::whereKey($batch->scan_run_id)->lockForUpdate()->firstOrFail();
            abort_if($run->status === 'cancelled', 409, 'Scanare anulată.');
            $checkpoint = $run->checkpoint ?? [];
            $checkpoint['_worker_requests'] = ($checkpoint['_worker_requests'] ?? 0) + 1;
            $run->update(['checkpoint' => $checkpoint]);
            $client = new SeapWorkerClient($batch->request, $payload);
            $this->advance($run, $client);
            abort_unless($client->consumed, 409, 'Checkpoint diferit; lotul nu a fost aplicat.');
            $receipt = ['batch_id' => $batch->id, 'accepted' => true, 'run_id' => $run->id, 'status' => $run->fresh()->status];
            $batch->update(['response_hash' => $hash, 'receipt' => $receipt, 'active_key' => null]);

            return $receipt;
        }));
    }

    private function advance(ScanRun $run, SeapWorkerClient $client): void
    {
        $context = app(ScanContext::class);
        $originalOptions = collect(['batch_size', 'fetch_adv_details', 'fetch_procedure_details'])
            ->mapWithKeys(fn ($key) => ['services.seap.'.$key => config('services.seap.'.$key)])->all();
        $checkpoint = $run->checkpoint ?? [];
        $checkpoint['_worker_options'] ??= $originalOptions;
        $run->update(['checkpoint' => $checkpoint]);
        config($checkpoint['_worker_options']);
        $budget = min(100, max(1, (int) config('services.seap.batch_size')));
        $context->reset();
        $context->runId = $run->id;
        $run->update(['status' => 'running', 'active_key' => 'manual', 'heartbeat_at' => now(), 'started_at' => $run->started_at ?? now()]);
        try {
            foreach ($run->families() as $family) {
                $run->refresh();
                $checkpoint = $run->checkpoint ?? [];
                if (($checkpoint['_worker_used'] ?? 0) >= $budget || ($checkpoint['_worker_requests'] ?? 0) > $budget) {
                    break;
                }
                if (($run->results['seap']['families'][$family]['status'] ?? null) === 'completed') {
                    continue;
                }
                config(['services.seap.batch_size' => $budget - ($checkpoint['_worker_used'] ?? 0)]);
                $stats = (new SeapImporter($client, app(SeapNormalizer::class), app(NoticeIngestor::class)))
                    ->scan($run->date_from, $run->date_to, [$family], fn () => null)[$family];
                $run->refresh();
                $checkpoint = $run->checkpoint;
                $checkpoint['_worker_used'] = ($checkpoint['_worker_used'] ?? 0) + $stats['batch_attempted'];
                $run->update(['checkpoint' => $checkpoint]);
                if ($client->pending !== null || $stats['errors'] !== [] || $stats['status'] === 'paused') {
                    break;
                }
            }
            $run->refresh();
            $results = $run->results ?? [];
            $results['ted'] ??= ['status' => 'not_run', 'errors' => []];
            $families = $results['seap']['families'] ?? [];
            $complete = collect($run->families())->every(fn ($family) => ($families[$family]['complete'] ?? false) && ($families[$family]['status'] ?? null) === 'completed');
            /** Stored errors of families not resumed yet belong to earlier attempts. */
            $failed = $context->errorCount > 0;
            $results['seap']['status'] = $complete ? 'completed' : ($failed ? 'error' : 'paused');
            $results['seap']['errors'] = $failed ? ['Lotul workerului nu s-a încheiat complet. Progresul a fost păstrat.'] : [];
            $results['seap']['error_count'] = $failed ? 1 : 0;
            $checkpoint = $run->checkpoint;
            $pause = $complete || $failed || ($checkpoint['_worker_used'] ?? 0) >= $budget || ($checkpoint['_worker_requests'] ?? 0) >= $budget || $client->pending === null;
            if ($pause) {
                $interval = max(1, (int) config('services.seap_worker.batch_interval_minutes', 60));
                if ($failed) {
                    $interval = max($interval, 360, (int) config('services.seap.batch_interval_minutes', 60));
                }
                $checkpoint['_next_batch_at'] = now()->addMinutes($interval)->toIso8601String();
            }
            $run->update(['results' => $results, 'checkpoint' => $checkpoint,
                'status' => $complete ? ($results['ted']['status'] === 'error' ? 'partial' : 'completed') : ($failed ? 'partial' : ($pause ? 'paused' : 'running')),
                'active_key' => $pause ? null : 'manual', 'finished_at' => $pause ? now() : null,
                'message' => $pause ? 'Lot worker încheiat; progresul este salvat pe server.' : 'Așteaptă răspunsul workerului SEAP autorizat.']);
        } finally {
            config($originalOptions);
            $context->runId = null;
            $context->reset();
        }
    }

    private function validate(SeapWorkerBatch $batch, array $payload): void
    {
        validator(['payload' => $payload], [
            'payload' => 'required|array:family,status,data',
            'payload.family' => 'required|in:PI,CN,SCN,ADV',
            'payload.status' => 'required|in:ok,403,429,unavailable',
            'payload.data' => 'present|array',
        ])->validate();
        $plan = $batch->request;
        if ($payload['family'] !== $plan['family']) {
            throw ValidationException::withMessages(['family' => 'Familia nu corespunde lotului emis de server.']);
        }
        if ($payload['status'] !== 'ok') {
            if ($payload['data'] !== []) {
                throw ValidationException::withMessages(['data' => 'Un răspuns de eroare nu poate conține date.']);
            }

            return;
        }
        if ($plan['kind'] === 'listing') {
            $id = SeapClient::FAMILIES[$plan['family']]['id'];
            validator($payload, [
                'data' => 'required|array:total,items,searchTooLong',
                'data.total' => 'required|integer|min:0',
                'data.items' => 'present|array|list|max:'.$plan['page_size'],
                'data.items.*' => 'required|array',
                'data.items.*.'.$id => 'required|integer|min:1',
                'data.searchTooLong' => 'sometimes|boolean',
                ...($plan['family'] === 'ADV' ? ['data.items.*.noticeNo' => ['required', 'string', 'regex:/^ADV\d+$/'],
                    'data.items.*.contractObject' => 'required|string|max:65535'] : []),
            ])->validate();
        } elseif ($plan['kind'] === 'adv_detail') {
            validator($payload, ['data.advertisingNoticeId' => 'required|integer|in:'.$plan['id'],
                'data.noticeNo' => ['required', 'string', 'regex:/^ADV\d+$/']])->validate();
        } elseif ($plan['kind'] === 'procedure_details') {
            $id = (string) $plan['items'][0][SeapClient::FAMILIES[$plan['family']]['id']];
            validator($payload, ['data' => 'required|array:'.$id, 'data.'.$id => 'required|array:data,errors',
                'data.'.$id.'.data' => 'present|array', 'data.'.$id.'.errors' => 'present|array|max:4',
                'data.'.$id.'.errors.*' => 'string|max:500'])->validate();
        }
    }
}
