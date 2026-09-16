<?php

namespace App\Jobs;

use App\Models\ScanRun;
use App\Services\ScanContext;
use App\Services\SeapClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\NullOutput;
use Throwable;

class RunManualScan implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 21600;

    public function __construct(public int $runId, public bool $seapOnly = false) {}

    public function handle(ScanContext $context): void
    {
        $claimed = ScanRun::whereKey($this->runId)->where('status', 'queued')->update([
            'status' => 'running', 'started_at' => now(), 'heartbeat_at' => now(),
        ]);
        if (! $claimed) {
            return;
        }
        $run = ScanRun::findOrFail($this->runId);
        $context->runId = $run->id;
        $previous = $run->results ?? [];
        $results = $previous;
        $probe = $run->mode === 'probe';
        $sample = $run->mode === 'sample';
        $limited = $probe || $sample;
        $expected = $probe ? 'probe_ok' : ($sample ? 'sample_ok' : 'completed');
        try {
            foreach (['ted', 'seap'] as $source) {
                if (($limited || ($this->seapOnly ?? false)) && $source === 'ted') {
                    $results['ted'] = $previous['ted'] ?? ['status' => 'not_run', 'errors' => []];

                    continue;
                }
                if ($source === 'ted' && ($previous['ted']['status'] ?? null) === 'completed') {
                    $results['ted'] = $previous['ted'];

                    continue;
                }
                $context->reset();
                $results[$source] = ['status' => 'running', 'errors' => []];
                $run->update(['results' => $results, 'heartbeat_at' => now()]);
                try {
                    $options = ['--from' => $run->date_from, '--to' => $run->date_to];
                    if ($source === 'seap') {
                        $options['--family'] = ['PI', 'CN', 'SCN'];
                        if ($sample) {
                            $options['--limit'] = 1;
                        }
                        if ($probe) {
                            $options['--probe'] = true;
                        }
                    }
                    if ($source === 'ted') {
                        $options['--max-pages'] = 300;
                    }
                    $exit = Artisan::call($source.':scan', $options, new NullOutput);
                    if ($exit !== 0 && $context->errorCount === 0) {
                        $context->error('Importul nu s-a încheiat complet. Datele preluate până la eroare au fost păstrate.');
                    }
                } catch (Throwable $e) {
                    $context->error($e->getMessage());
                }
                if ($source === 'seap') {
                    foreach (array_keys(SeapClient::FAMILIES) as $family) {
                        $stats = $context->families[$family] ?? null;
                        $waitingForBatch = ! $limited && ($stats['status'] ?? null) === 'paused';
                        if (! $waitingForBatch && ($stats === null || ($stats['status'] ?? null) !== $expected || (! $limited && ! ($stats['complete'] ?? false)))) {
                            $context->error($family.': familia nu are confirmarea unei execuții complete'.($probe ? ' a testului limitat.' : '.'));
                        }
                    }
                }
                $results[$source] = ['status' => $context->errorCount === 0 ? $expected : 'error',
                    'error_count' => $context->errorCount, 'errors' => $context->errors];
                if ($source === 'seap') {
                    if ($context->errorCount === 0 && collect($context->families)->contains('status', 'paused')) {
                        $results[$source]['status'] = 'paused';
                    }
                    $results[$source]['families'] = $context->families;
                }
                $run->update(['results' => $results, 'heartbeat_at' => now()]);
            }
            $errors = count(array_filter($results, fn ($result) => $result['status'] === 'error'));
            $status = $errors === 0 ? ($limited ? 'tested' : 'completed') : ($limited || $errors === 2 ? 'failed' : 'partial');
            $message = $errors === 0 ? 'Scanarea s-a încheiat.' : 'Scanarea s-a încheiat cu erori. Datele importate au fost păstrate.';
            $run->refresh();
            $checkpoint = $run->checkpoint ?? [];
            if (! $limited) {
                $checkpoint['_next_batch_at'] = now()->addMinutes(max(1, (int) config('services.seap.batch_interval_minutes', 60)))->toIso8601String();
                if (($results['seap']['status'] ?? null) === 'paused' && $errors === 0) {
                    $status = 'paused';
                    $message = 'Lot SEAP încheiat. Sincronizarea continuă de la checkpoint la următoarea rulare programată.';
                }
            }
            if ($sample) {
                $message = 'Test real limitat: maximum un anunț per familie, cu detalii și import. TED nu este apelat; catalogul nu a fost sincronizat complet.';
            }
            if ($probe) {
                $message = 'Test limitat SEAP: maximum o pagină per familie, fără detalii sau import. Nu reprezintă o scanare completă.';
            }
            $run->update(['results' => $results, 'checkpoint' => $checkpoint, 'status' => $status,
                'active_key' => null, 'finished_at' => now(), 'message' => $message]);

        } finally {
            $context->runId = null;
            $context->reset();
        }
    }

    public function failed(?Throwable $exception): void
    {
        ScanRun::whereKey($this->runId)->update(['status' => 'failed', 'active_key' => null, 'finished_at' => now(),
            'message' => 'Procesul de scanare s-a oprit. Puteți relua scanarea; datele deja preluate sunt păstrate.']);
    }
}
