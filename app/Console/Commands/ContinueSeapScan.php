<?php

namespace App\Console\Commands;

use App\Services\ScanDispatcher;
use Illuminate\Console\Command;

class ContinueSeapScan extends Command
{
    protected $signature = 'seap:tick';

    protected $description = 'Pregateste urmatorul lot SEAP sau continua sincronizarea incompleta';

    public function handle(ScanDispatcher $dispatcher): int
    {
        $run = $dispatcher->dispatch(seapOnly: true);
        $remoteReady = config('services.seap_worker.server') && ($run->checkpoint['_worker_ready'] ?? false);
        $state = $remoteReady && $run->status === 'queued' ? 'pregătit pentru workerul SEAP (fără job Laravel)' : $run->status;
        $this->info('SEAP run '.$run->id.': '.$state.'; interval '.$run->date_from.' – '.$run->date_to.'.');
        if ($remoteReady) {
            $this->line('Următoarea cerere SEAP este emisă când laptopul face claim; serverul nu contactează SEAP.');
        }
        if (! $run->batchAvailable()) {
            $this->line('Urmatorul lot este permis dupa '.$run->checkpoint['_next_batch_at'].'.');
        }

        return self::SUCCESS;
    }
}
