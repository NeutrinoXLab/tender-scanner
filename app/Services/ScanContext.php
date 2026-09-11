<?php

namespace App\Services;

use App\Models\ScanRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ScanContext
{
    public ?int $runId = null;

    public array $errors = [];

    public array $families = [];

    public int $errorCount = 0;

    public function error(string $message): void
    {
        if ($this->runId === null) {
            return;
        }
        if (str_contains($message, 'SQLSTATE')) {
            Log::warning('Eroare la import', ['scan_id' => $this->runId, 'message' => $message]);
            $message = str_contains($message, 'locked')
                ? 'Baza locală a fost ocupată temporar. Reluați scanarea pentru anunțurile omise.'
                : 'Un anunț nu a putut fi salvat. Celelalte date au fost păstrate; detaliile sunt în jurnalul aplicației.';
        }
        $this->errorCount++;
        if (count($this->errors) < 5) {
            $this->errors[] = mb_substr($message, 0, 500);
        }
    }

    public function record(string $source, string $externalId, int $noticeId, bool $changed): void
    {
        if ($this->runId === null) {
            return;
        }
        $key = ['scan_run_id' => $this->runId, 'source' => $source, 'external_id' => $externalId];
        $existing = DB::table('scan_run_items')->where($key)->first();
        DB::table('scan_run_items')->updateOrInsert($key, ['notice_id' => $noticeId, 'changed' => $changed || ($existing?->changed ?? false)]);
        ScanRun::whereKey($this->runId)->update(['heartbeat_at' => now()]);
    }

    public function reset(): void
    {
        $this->families = [];
        $this->errors = [];
        $this->errorCount = 0;
    }
}
