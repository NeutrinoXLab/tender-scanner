<?php

namespace App\Services;

use App\Jobs\RunManualScan;
use App\Models\Notice;
use App\Models\ScanRun;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ScanDispatcher
{
    /** @param array{mode?: string, from?: ?string, to?: ?string, resume_id?: ?int} $options */
    public function dispatch(array $options = [], bool $seapOnly = false): ScanRun
    {
        return Cache::lock('tender-scan-dispatch', 10)->block(5, fn (): ScanRun => DB::transaction(function () use ($options, $seapOnly): ScanRun {
            if ($active = ScanRun::where('active_key', 'manual')->first()) {
                return $active;
            }
            $mode = $options['mode'] ?? 'full';
            $resume = ! empty($options['resume_id']) ? ScanRun::findOrFail($options['resume_id']) : null;
            if ($resume && ! ($resume->mode === 'full' && in_array($resume->status, ['paused', 'partial', 'failed'], true) && ! empty($resume->checkpoint))) {
                throw ValidationException::withMessages(['resume_id' => 'Scanarea nu poate fi reluată.']);
            }
            if (! $resume && $mode === 'full') {
                $resume = ScanRun::oldestIncomplete();
            }
            if ($mode === 'full' && config('services.seap_worker.server')) {
                $cooling = ScanRun::remoteCooldown();
                if ($cooling) {
                    return $cooling;
                }
                $backfill = $seapOnly ? ScanRun::nextBackfill() : null;
                if ($resume && $backfill && $this->normalRunBlockedByOtherFamilyCooldown($resume, $backfill)) {
                    $resume = null;
                }
                if (! $resume && $seapOnly && ($backfill = ScanRun::nextBackfill())) {
                    if ($cooling = ScanRun::familyCooldownFor($backfill->families())) {
                        return $cooling;
                    }
                    $backfill->update(['status' => 'queued', 'active_key' => 'manual', 'finished_at' => null,
                        'message' => 'Backfill pregătit pentru workerul autorizat.']);

                    return $backfill;
                }
            }
            if ($resume) {
                if (! empty($options['from']) && ($options['from'] !== $resume->date_from || $options['to'] !== $resume->date_to)) {
                    throw ValidationException::withMessages(['from' => 'Există o sincronizare SEAP incompletă. Continuați intervalul '.$resume->date_from.' – '.$resume->date_to.'.']);
                }
                if (! $resume->seapComplete() && ! $resume->batchAvailable()) {
                    return $resume;
                }
                $resume->update(['status' => 'queued', 'active_key' => 'manual', 'finished_at' => null, 'message' => 'Continuare de la checkpoint-ul salvat.']);
                $this->queue($resume, $seapOnly);

                return $resume;
            }
            if ($mode === 'full') {
                $recent = ScanRun::where('mode', 'full')->whereNotNull('finished_at')
                    ->whereNotNull('checkpoint->_next_batch_at')->orderByDesc('finished_at')->first();
                if ($recent && ! $recent->batchAvailable()) {
                    return $recent;
                }
            }
            $today = CarbonImmutable::now('Europe/Bucharest');
            $ranges = [];
            foreach (array_keys(SeapClient::FAMILIES) as $family) {
                $lastDay = ScanRun::where('mode', 'full')->where('results->seap->families->'.$family.'->complete', true)
                    ->where('status', '!=', 'cancelled')
                    ->where(fn (Builder $query) => $query->where('results->seap->families->'.$family.'->status', 'completed')
                        ->orWhere(fn (Builder $legacy) => $legacy->where('status', 'completed')->where('results->seap->status', 'completed')))
                    ->max('date_to');
                $from = $lastDay ? CarbonImmutable::parse($lastDay)->min($today)->toDateString() : $today->subDay()->toDateString();
                $ranges[$family] = ['from' => $options['from'] ?? $from, 'to' => $options['to'] ?? $today->toDateString()];
            }
            $verificationDay = $mode === 'full' ? app(SeapBulkSync::class)->verificationDay() : null;
            $checkpoint = ['_families' => array_keys(SeapClient::FAMILIES), '_ranges' => $ranges];
            if ($verificationDay !== null) {
                $checkpoint['_refresh_day'] = $verificationDay;
            }
            $runFrom = min(array_column($ranges, 'from'));
            if (! $seapOnly) {
                $lastComplete = ScanRun::where('mode', 'full')->where('results->seap->status', 'completed')
                    ->orderByDesc('date_to')->get()->first(fn (ScanRun $run): bool => $run->seapComplete());
                $tedFrom = $options['from'] ?? ($lastComplete ? CarbonImmutable::parse($lastComplete->date_to)->min($today)->toDateString() : $today->subDays(30)->toDateString());
                $checkpoint['_ted_from'] = $tedFrom;
                $runFrom = min($runFrom, $tedFrom);
            }
            $run = ScanRun::create(['status' => 'queued', 'active_key' => 'manual', 'mode' => $mode,
                'date_from' => $runFrom, 'date_to' => $options['to'] ?? $today->toDateString(),
                'checkpoint' => $checkpoint,
                'before_notice_id' => Notice::max('id') ?? 0]);
            $this->queue($run, $seapOnly);

            return $run;
        }, 5));
    }

    private function queue(ScanRun $run, bool $seapOnly): void
    {
        if (config('services.seap_worker.server')) {
            $run->update(['checkpoint' => array_replace($run->checkpoint ?? [], ['_worker_ready' => $seapOnly])]);
            if ($seapOnly) {
                $run->update(['message' => 'Pregătit pentru workerul SEAP, fără job Laravel. Cererea se emite la claim.']);

                return;
            }
        }
        RunManualScan::dispatch($run->id, $seapOnly)->onConnection('tender')->onQueue('tender-scans');
    }

    private function normalRunBlockedByOtherFamilyCooldown(ScanRun $normal, ScanRun $backfill): bool
    {
        if (! $normal->waitingOnlyOnFamilyCooldowns()) {
            return false;
        }

        $backfillFamiliesAreAvailable = collect($backfill->families())->every(
            fn (string $family): bool => ScanRun::familyCooldown($family) === null
        );

        return $backfillFamiliesAreAvailable;
    }
}
