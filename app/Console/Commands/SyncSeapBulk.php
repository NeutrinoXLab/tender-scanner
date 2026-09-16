<?php

namespace App\Console\Commands;

use App\Services\SeapBulkSync;
use Illuminate\Console\Command;
use Throwable;

class SyncSeapBulk extends Command
{
    protected $signature = 'seap:bulk-sync {--year=2026} {--limit=1000 : Maximum rows in this invocation}';

    protected $description = 'Synchronize one resumable batch from official data.gov.ro initiation notices or contracts';

    public function handle(SeapBulkSync $sync): int
    {
        $year = filter_var($this->option('year'), FILTER_VALIDATE_INT);
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if (! $year || $year < 2026 || $year > (int) date('Y') || ! $limit || $limit < 1 || $limit > 10000) {
            $this->error('Year or limit invalid (1–10000 rows).');

            return self::FAILURE;
        }
        try {
            $result = $sync->sync($year, $limit);
            $this->info(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
