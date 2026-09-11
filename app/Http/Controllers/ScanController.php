<?php

namespace App\Http\Controllers;

use App\Jobs\RunManualScan;
use App\Models\Notice;
use App\Models\ScanRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ScanController extends Controller
{
    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $run = DB::transaction(function (): ScanRun {
            $existing = ScanRun::where('active_key', 'manual')->first();
            if ($existing) {
                return $existing;
            }
            $from = now()->subDays(7)->toDateString();
            $oldest = Notice::whereNull('canonical_notice_id')->min('publication_date');
            if ($oldest && substr($oldest, 0, 10) < $from) {
                $from = substr($oldest, 0, 10);
            }
            $run = ScanRun::create(['status' => 'queued', 'active_key' => 'manual', 'date_from' => $from,
                'date_to' => now()->toDateString(), 'before_notice_id' => Notice::max('id') ?? 0]);
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
