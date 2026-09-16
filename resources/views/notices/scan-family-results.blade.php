<div class="rounded-lg border p-3 space-y-1" aria-label="Statistici familii SEAP">
    @foreach ($result['families'] as $family => $stats)
        <p><strong>{{ $family }}:</strong>
            {{ $stats['processed'] ?? '—' }} verificate · {{ $scanLabels[$stats['status']] ?? $stats['status'] }}
            · {{ $stats['fetched'] ?? '—' }} preluate / {{ $stats['reported'] ?? '—' }} raportate
            · {{ $stats['new'] ?? 0 }} noi · {{ $stats['updated'] ?? 0 }} actualizate · {{ $stats['duplicates'] ?? 0 }} duplicate
        </p>
        @if (isset($stats['batch_attempted']))<p>{{ $family }}: {{ $stats['batch_attempted'] }} încercate în acest lot.</p>@endif
        @if ($stats['bulk_days'] ?? 0)<p>{{ $family }}: {{ $stats['bulk_days'] }} zile acoperite de exportul oficial, fără interogare API.</p>@endif
        @if ($stats['refresh_day'] ?? null)<p>{{ $family }}: reverificare anunțuri mai vechi din {{ $stats['refresh_day'] }}.</p>@endif
        @foreach ($stats['errors'] ?? [] as $error)<p class="text-red-700">{{ $family }}: {{ $error }}</p>@endforeach
    @endforeach
    <p class="font-semibold">Total SEAP: {{ $result['checked'] }} verificate</p>
    @if (collect($result['families'])->contains('status', 'unverified'))
        <p class="text-amber-800">Lipsește confirmarea executării unei familii. Raportul vechi nu dovedește acoperirea PI/CN/SCN; verificați versiunea procesului de fundal.</p>
    @endif
</div>
