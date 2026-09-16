<?php

namespace App\Services;

use App\Jobs\RunManualScan;
use App\Models\Notice;
use App\Models\ScanRun;
use Carbon\CarbonImmutable;
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
                $resume = ScanRun::where('mode', 'full')->whereIn('status', ['paused', 'partial', 'failed'])
                    ->whereNotNull('checkpoint')->orderBy('id')->get()->first(fn (ScanRun $run): bool => ! $run->seapComplete());
            }
            if ($resume) {
                if (! empty($options['from']) && ($options['from'] !== $resume->date_from || $options['to'] !== $resume->date_to)) {
                    throw ValidationException::withMessages(['from' => 'Există o sincronizare SEAP incompletă. Continuați intervalul '.$resume->date_from.' – '.$resume->date_to.'.']);
                }
                if (! $resume->seapComplete() && ! $resume->batchAvailable()) {
                    return $resume;
                }
                $resume->update(['status' => 'queued', 'active_key' => 'manual', 'finished_at' => null, 'message' => 'Continuare de la checkpoint-ul salvat.']);
                RunManualScan::dispatch($resume->id, $seapOnly)->onConnection('tender')->onQueue('tender-scans');

                return $resume;
            }
            if ($mode === 'full') {
                $recent = ScanRun::where('mode', 'full')->whereNotNull('finished_at')
                    ->whereNotNull('checkpoint->_next_batch_at')->orderByDesc('finished_at')->first();
                if ($recent && ! $recent->batchAvailable()) {
                    return $recent;
                }
            }
            $lastComplete = ScanRun::where('mode', 'full')->where('results->seap->status', 'completed')
                ->orderByDesc('date_to')->get()->first(fn (ScanRun $run): bool => $run->seapComplete());
            $today = CarbonImmutable::now('Europe/Bucharest');
            $from = $lastComplete ? CarbonImmutable::parse($lastComplete->date_to)->min($today)->toDateString() : $today->subDays(30)->toDateString();
            $run = ScanRun::create(['status' => 'queued', 'active_key' => 'manual', 'mode' => $mode,
                'date_from' => $options['from'] ?? $from, 'date_to' => $options['to'] ?? $today->toDateString(),
                'before_notice_id' => Notice::max('id') ?? 0]);
            RunManualScan::dispatch($run->id, $seapOnly)->onConnection('tender')->onQueue('tender-scans');

            return $run;
        }, 5));
    }
}
