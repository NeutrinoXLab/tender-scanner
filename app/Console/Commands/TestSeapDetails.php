<?php

namespace App\Console\Commands;

use App\Services\SeapClient;
use Illuminate\Console\Command;

class TestSeapDetails extends Command
{
    protected $signature = 'seap:test-details
                            {piNoticeId : ID-ul piNotice SEAP}';

    protected $description = 'Testeaza obtinerea detaliilor unui anunt SEAP';

    public function handle(SeapClient $seap): int
    {
        $piNoticeId = (int) $this->argument('piNoticeId');

        $this->info("Obtinem detaliile pentru piNoticeId: {$piNoticeId}");

        try {
            $data = $seap->getNoticeDetails($piNoticeId);

            $this->info('Detaliile au fost obtinute cu succes.');

            $this->line(
                json_encode(
                    $data,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
                )
            );

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Obtinerea detaliilor a esuat.');
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}