<?php

namespace App\Console\Commands;

use App\Services\ScanDispatcher;
use Illuminate\Console\Command;

class ContinueSeapScan extends Command
{
    protected $signature = 'seap:tick';

    protected $description = 'Pune in coada un singur lot SEAP sau continua sincronizarea incompleta';

    public function handle(ScanDispatcher $dispatcher): int
    {
        $run = $dispatcher->dispatch(seapOnly: true);
        $this->info('SEAP run '.$run->id.': '.$run->status.'; interval '.$run->date_from.' – '.$run->date_to.'.');
        if (! $run->batchAvailable()) {
            $this->line('Urmatorul lot este permis dupa '.$run->checkpoint['_next_batch_at'].'.');
        }

        return self::SUCCESS;
    }
}
