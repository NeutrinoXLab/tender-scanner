<?php

namespace App\Console\Commands;

use App\Services\SeapWorkerTransport;
use Illuminate\Console\Command;
use Throwable;

class RunSeapWorker extends Command
{
    protected $signature = 'seap:worker {--check : Test HTTPS autentificat, fără acces SEAP}';

    protected $description = 'Execută cererile SEAP emise de server și transferă răspunsurile idempotent';

    public function handle(SeapWorkerTransport $transport): int
    {
        try {
            $count = $transport->run((bool) $this->option('check'));
            $this->info($this->option('check') ? 'Conexiune worker-server confirmată.' : $count.' transferuri confirmate; progresul este pe server.');

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Workerul s-a oprit fără alte cereri. Verificați configurația/conectivitatea; orice lot salvat rămâne pentru retransmitere.');

            return self::FAILURE;
        }
    }
}
