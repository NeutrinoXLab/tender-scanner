<section id="manual-scan" class="bg-white border rounded-xl p-5 mb-6" data-status-url="{{ route('scans.latest') }}">
    @php
        $scanSummary = $latestScan?->summary();
        $scanLabels = ['paused' => 'Lot încheiat — de continuat', 'cancelled' => 'Anulată', 'queued' => 'În așteptare', 'pending' => 'În așteptare', 'running' => 'În curs', 'completed' => 'Finalizată', 'partial' => 'Finalizată parțial', 'failed' => 'Cu erori', 'error' => 'Cu erori', 'not_run' => 'Neexecutată', 'unverified' => 'Acoperire neconfirmată', 'sample_ok' => 'Import limitat reușit', 'probe_ok' => 'API răspunde (test limitat)', 'tested' => 'Test limitat reușit'];
    @endphp
    <div class="flex flex-wrap justify-between gap-3 items-center">
        <div><h2 class="font-semibold text-gray-900">Actualizare SEAP + TED</h2>
            <p class="text-sm text-gray-600">Panoul urmărește scanarea activă sau cea mai veche sincronizare SEAP restantă; altfel, ultima scanare.</p></div>
        <form id="manual-scan-form" method="POST" action="{{ route('scans.store') }}">@csrf
            <div class="flex flex-wrap gap-3 mb-3">
                <label class="text-sm">De la <input type="date" name="from" class="border rounded p-1" aria-label="Scanare de la"></label>
                <label class="text-sm">Până la <input type="date" name="to" class="border rounded p-1" aria-label="Scanare până la"></label>
            </div>
            <p class="text-xs text-gray-500 mb-2">Fără date: continuăm sincronizarea incompletă sau verificăm perioada nouă, inclusiv ultima zi sincronizată. SEAP procesează maximum {{ max(1, (int) config('services.seap.batch_size', 100)) }} anunțuri per lot; TED are import separat de această limită.</p>
            <button id="manual-scan-button" type="submit" name="mode" value="full" class="bg-blue-600 text-white px-5 py-2 rounded-lg disabled:opacity-50" @disabled(in_array($latestScan?->status, ['queued', 'running'], true))>Scanează acum</button>
            <button id="manual-scan-probe" type="submit" name="mode" value="probe" class="border rounded-lg px-4 py-2 disabled:opacity-50" @disabled(in_array($latestScan?->status, ['queued', 'running'], true))>Testează PI/CN/SCN/ADV fără import</button>
            <button id="manual-scan-resume" type="submit" name="resume_id" value="{{ $latestScan?->id }}" class="border rounded-lg px-4 py-2" @if (!($scanSummary['can_resume'] ?? false)) hidden @endif>Continuă scanarea</button>
        </form>
    </div>
    <div id="manual-scan-status" role="status" aria-live="polite" class="text-sm text-gray-700 mt-3">{{ $scanSummary ? 'Scanare urmărită: '.$scanSummary['created_at'].' — '.$scanLabels[$scanSummary['status']].'. Perioadă: '.$scanSummary['from'].' – '.$scanSummary['to'] : 'Nu a fost pornită încă o scanare din browser.' }}</div>
    <div id="manual-scan-results" class="text-sm mt-3 space-y-2">
        @if ($latestScan)
            <p>{{ $scanSummary['counts']['new'] }} noi · {{ $scanSummary['counts']['updated'] }} actualizate · {{ $scanSummary['counts']['checked'] }} verificate</p>
            @foreach ($scanSummary['results'] as $scanSource => $result)
                @if ($scanSource === 'seap')
                    @include('notices.scan-family-results')
                @endif
                <p>{{ strtoupper($scanSource) }}: {{ $result['new'] }} noi, {{ $result['updated'] }} actualizate{{ ($result['error_count'] ?? 0) ? ' · '.$result['error_count'].' erori' : '' }}</p>
                @foreach ($result['errors'] ?? [] as $error)<p class="text-red-700">{{ $error }}</p>@endforeach
            @endforeach
            @if ($scanSummary['resume_after'])<p>Următorul lot SEAP este permis după {{ \Carbon\CarbonImmutable::parse($scanSummary['resume_after'])->timezone('Europe/Bucharest')->format('d.m.Y H:i') }}.</p>@endif
            @if ($scanSummary['mode'] === 'sample')<p>Test real limitat: maximum un anunț per familie, cu detalii și import; catalogul nu a fost sincronizat complet.</p>@endif
            @if ($scanSummary['mode'] === 'probe')<p>Test limitat: câte o pagină per familie, fără detalii sau import; TED nu este apelat.</p>@endif
        @endif
    </div>
    <a id="manual-scan-refresh" href="{{ route('notices.index', request()->query(), false) }}" class="text-sm text-blue-600 hover:underline mt-3 inline-block" @if (!$latestScan || in_array($latestScan->status, ['queued', 'running'], true)) hidden @endif>Actualizează lista licitațiilor</a>
    <noscript><p class="text-sm mt-2">Reîncarcă pagina pentru a vedea progresul și rezultatul scanării.</p></noscript>
</section>
