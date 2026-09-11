<section id="manual-scan" class="bg-white border rounded-xl p-5 mb-6" data-status-url="{{ route('scans.latest') }}">
    <div class="flex flex-wrap justify-between gap-3 items-center">
        <div><h2 class="font-semibold text-gray-900">Actualizare SEAP + TED</h2>
            <p class="text-sm text-gray-600">Verifică anunțurile noi și modificările celor deja importate.</p></div>
        <form id="manual-scan-form" method="POST" action="{{ route('scans.store') }}">@csrf
            <button id="manual-scan-button" type="submit" class="bg-blue-600 text-white px-5 py-2 rounded-lg disabled:opacity-50" @disabled(in_array($latestScan?->status, ['queued', 'running'], true))>Scanează acum</button>
        </form>
    </div>
    <div id="manual-scan-status" role="status" aria-live="polite" class="text-sm text-gray-700 mt-3">{{ $latestScan ? 'Ultima scanare: '.$latestScan->created_at->timezone('Europe/Bucharest')->format('d.m.Y H:i').' — '.(['queued' => 'În așteptare', 'running' => 'În curs', 'completed' => 'Finalizată', 'partial' => 'Finalizată parțial', 'failed' => 'Cu erori'][$latestScan->status] ?? $latestScan->status) : 'Nu a fost pornită încă o scanare din browser.' }}</div>
    <div id="manual-scan-results" class="text-sm mt-3 space-y-2">
        @if ($latestScan)
            @php($scanSummary = $latestScan->summary())
            <p>{{ $scanSummary['counts']['new'] }} noi · {{ $scanSummary['counts']['updated'] }} actualizate · {{ $scanSummary['counts']['checked'] }} verificate</p>
            @foreach ($scanSummary['results'] as $scanSource => $result)
                <p>{{ strtoupper($scanSource) }}: {{ $result['new'] }} noi, {{ $result['updated'] }} actualizate{{ ($result['error_count'] ?? 0) ? ' · '.$result['error_count'].' erori' : '' }}</p>
                @foreach ($result['errors'] ?? [] as $error)<p class="text-red-700">{{ $error }}</p>@endforeach
            @endforeach
        @endif
    </div>
    <a id="manual-scan-refresh" href="{{ request()->fullUrl() }}" class="text-sm text-blue-600 hover:underline mt-3 inline-block" @if (!$latestScan || in_array($latestScan->status, ['queued', 'running'], true)) hidden @endif>Actualizează lista licitațiilor</a>
    <noscript><p class="text-sm mt-2">Reîncarcă pagina pentru a vedea progresul și rezultatul scanării.</p></noscript>
</section>
