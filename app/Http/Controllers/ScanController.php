<?php

namespace App\Http\Controllers;

use App\Jobs\RunManualScan;
use App\Models\Notice;
use App\Models\ScanRun;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ScanController extends Controller
{
    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'resume_id' => ['nullable', 'integer', 'exists:scan_runs,id'],
            'mode' => ['sometimes', 'string', 'in:full,probe,sample'],
            'from' => ['nullable', 'required_with:to', 'date_format:Y-m-d'],
            'to' => ['nullable', 'required_with:from', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $run = DB::transaction(function () use ($validated): ScanRun {
            $existing = ScanRun::where('active_key', 'manual')->first();
            if ($existing) {
                return $existing;
            }
            if (! empty($validated['resume_id'])) {
                $run = ScanRun::lockForUpdate()->findOrFail($validated['resume_id']);
                abort_unless($run->summary()['can_resume'], 422, 'Scanarea nu poate fi reluata.');
                $run->update(['status' => 'queued', 'active_key' => 'manual', 'finished_at' => null, 'message' => 'Reluare de la progresul salvat.']);
                RunManualScan::dispatch($run->id)->onConnection('tender')->onQueue('tender-scans');

                return $run;
            }
            $lastComplete = ScanRun::where('mode', 'full')->where('results->seap->status', 'completed')
                ->orderByDesc('date_to')->get()->first(fn (ScanRun $run): bool => collect(['PI', 'CN', 'SCN'])
                ->every(fn (string $family): bool => (bool) data_get($run->results, "seap.families.$family.complete", false)));
            $from = $lastComplete
                ? CarbonImmutable::parse($lastComplete->date_to)->subDays(3)->min(now())->toDateString()
                : now()->subDays(30)->toDateString();
            $refreshDay = null;
            if ($lastComplete && ! isset($validated['from']) && ($validated['mode'] ?? 'full') === 'full') {
                $previousRefreshes = ScanRun::where('mode', 'full')->where('results->seap->status', 'completed')
                    ->whereNotNull('checkpoint')->get()->groupBy(fn (ScanRun $scan): string => $scan->checkpoint['_refresh_day'] ?? '')
                    ->map(fn ($scans) => $scans->max('finished_at')?->timestamp ?? 0);
                $refreshDay = Notice::where('source', 'seap')->whereNull('canonical_notice_id')->seapOpportunities()
                    ->whereDate('publication_date', '<', $from)->pluck('publication_date')
                    ->map(fn ($date): string => substr((string) $date, 0, 10))->unique()->sort()
                    ->sortBy(fn (string $day): int => $previousRefreshes[$day] ?? 0)->first();
            }
            $run = ScanRun::create(['checkpoint' => $refreshDay ? ['_refresh_day' => $refreshDay] : null, 'status' => 'queued', 'active_key' => 'manual',
                'date_from' => $validated['from'] ?? $from, 'date_to' => $validated['to'] ?? now()->toDateString(),
                'before_notice_id' => Notice::max('id') ?? 0, 'mode' => $validated['mode'] ?? 'full']);
            RunManualScan::dispatch($run->id)->onConnection('tender')->onQueue('tender-scans');

            return $run;
        }, 5);

        return $request->expectsJson() ? response()->json($run->summary(), 202) : redirect()->route('notices.index');
    }

    public function latest(): JsonResponse
    {
        return response()->json(ScanRun::latest('id')->first()?->summary())->header('Cache-Control', 'no-store');
    }
}
