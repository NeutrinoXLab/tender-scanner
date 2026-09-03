<?php

namespace App\Console\Commands;

use App\Services\SeapClient;
use Illuminate\Console\Command;

class TestSeapConnection extends Command
{
    protected $signature = 'seap:test';

    protected $description = 'Testeaza conexiunea cu API-ul public SEAP';

    public function handle(SeapClient $seap): int
    {
        $this->info('Conectare la SEAP...');

        try {
            $data = $seap->getOpenNotices();

            $this->info('Conexiunea cu SEAP functioneaza.');

            $this->line(
                json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            );

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Conexiunea a esuat.');
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}