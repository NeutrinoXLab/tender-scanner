<?php

namespace App\Http\Controllers;

use App\Models\ScanRun;
use App\Services\ScanDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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
        $run = app(ScanDispatcher::class)->dispatch($validated);

        return $request->expectsJson() ? response()->json($run->summary(), 202) : redirect()->route('notices.index');
    }

    public function latest(): JsonResponse
    {
        return response()->json(ScanRun::latest('id')->first()?->summary())->header('Cache-Control', 'no-store');
    }
}
