<?php

namespace App\Jobs;

use App\Models\Notice;
use App\Models\ScanRun;
use App\Services\ScanContext;
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

    public function __construct(public int $runId) {}

    public function handle(ScanContext $context): void
    {
        $claimed = ScanRun::whereKey($this->runId)->where('status', 'queued')->update([
            'status' => 'running', 'started_at' => now(), 'heartbeat_at' => now(),
            'before_notice_id' => Notice::max('id') ?? 0,
        ]);
        if (! $claimed) {
            return;
        }
        $run = ScanRun::findOrFail($this->runId);
        $context->runId = $run->id;
        $results = [];
        try {
            foreach (['seap', 'ted'] as $source) {
                $context->reset();
                $results[$source] = ['status' => 'running', 'errors' => []];
                $run->update(['results' => $results, 'heartbeat_at' => now()]);
                try {
                    $options = ['--from' => $run->date_from, '--to' => $run->date_to];
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
                $results[$source] = ['status' => $context->errorCount === 0 ? 'completed' : 'error',
                    'error_count' => $context->errorCount, 'errors' => $context->errors];
                if ($source === 'seap') {
                    $results[$source]['families'] = $context->families;
                }
                $run->update(['results' => $results, 'heartbeat_at' => now()]);
            }
            $errors = count(array_filter($results, fn ($result) => $result['status'] === 'error'));
            $run->update(['status' => $errors === 0 ? 'completed' : ($errors === 2 ? 'failed' : 'partial'),
                'active_key' => null, 'finished_at' => now(), 'message' => $errors === 0 ? 'Scanarea s-a încheiat.' : 'Scanarea s-a încheiat cu erori. Datele importate au fost păstrate.']);
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
