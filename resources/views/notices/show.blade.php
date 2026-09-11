<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8"><meta name="robots" content="noindex, nofollow">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $notice->notice_number }} - Tender Scanner</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="bg-gray-100">
    <x-navigation />
    <div class="max-w-5xl mx-auto px-4 py-8">

        <div class="mb-6">
            <a
                href="{{ route('notices.index') }}"
                class="text-blue-600 hover:underline"
            >
                ← Înapoi la anunțuri
            </a>
        </div>

        <div class="bg-white border rounded-lg p-6">

            <div class="flex flex-wrap items-center gap-3 mb-4">
                <span class="font-semibold text-gray-900">
                    {{ $notice->notice_number }}
                </span>

                                        <span class="text-sm text-gray-600">Sursă: {{ $notice->source === 'seap' ? 'SEAP' : 'TED' }}{{ $notice->source === 'seap' && $notice->ted_id ? ' + TED' : '' }}</span>
                        @if ($notice->ted_url)
                            <a href="{{ $notice->ted_url }}" target="_blank" rel="noopener noreferrer" class="text-sm text-blue-600 hover:underline">Vezi anunțul pe TED</a>
                        @endif
                @if ($notice->notice_type && $notice->source === 'seap')
                    <span class="text-sm text-gray-600">{{ $notice->notice_type }}</span>
                @endif
@if ($notice->status)
                    <span class="text-sm bg-gray-100 px-3 py-1 rounded">
                        {{ $notice->status }}
                    </span>
                @endif
            </div>

            <h1 class="text-2xl font-bold text-gray-900 mb-6">
                {{ $notice->title }}
            </h1>

            @include('notices.personal-panel')

            <div class="grid grid-cols-1 md:grid-cols-2 gap-5 text-sm">

                <div>
                    <strong>Autoritate:</strong><br>
                    {{ $notice->authority_name ?? '—' }}
                </div>

                <div>
                    <strong>Județ:</strong><br>
                    {{ $notice->county ?? '—' }}
                </div>

                <div>
                    <strong>Localitate:</strong><br>
                    {{ $notice->locality ?? '—' }}
                </div>

                <div>
                    <strong>CPV:</strong><br>
                    {{ $notice->cpv_code ?? '—' }}
                </div>

                <div>
                    <strong>Valoare estimată:</strong><br>
                    {{ $notice->estimated_value ?? '—' }}
                    {{ $notice->currency ?? '' }}
                </div>

                <div>
                    <strong>Tip contract:</strong><br>
                    {{ $notice->contract_type ?? '—' }}
                </div>

                <div>
                    <strong>Procedură:</strong><br>
                    {{ $notice->procedure_type ?? '—' }}
                </div>

                <div>
                    <strong>Tip atribuire:</strong><br>
                    {{ $notice->contract_assignment_type ?? '—' }}
                </div>

                <div>
                    <strong>Criteriu de atribuire:</strong><br>
                    {{ $notice->award_criteria ?? '—' }}
                </div>

                <div>
                    <strong>Durată contract:</strong><br>
                    {{ $notice->contract_duration_months
                        ? $notice->contract_duration_months . ' luni'
                        : '—' }}
                </div>

                <div>
                    <strong>Depunere electronică:</strong><br>
                    {{ $notice->is_electronic_submission === null
                        ? '—'
                        : ($notice->is_electronic_submission ? 'Da' : 'Nu') }}
                </div>

                <div>
                    <strong>Finanțare UE:</strong><br>
                    {{ $notice->is_eu_funded === null
                        ? '—'
                        : ($notice->is_eu_funded ? 'Da' : 'Nu') }}
                </div>

                <div>
                    <strong>Publicat:</strong><br>
                    {{ $notice->publication_date?->format('d.m.Y H:i') ?? '—' }}
                </div>

                <div>
                    <strong>Termen depunere:</strong><br>
                    {{ $notice->submission_deadline?->format('d.m.Y H:i') ?? '—' }}
                </div>

            </div>

            @if ($notice->funding_program)
                <div class="mt-6">
                    <strong>Program de finanțare:</strong>
                    <p class="mt-1 text-gray-700">
                        {{ $notice->funding_program }}
                    </p>
                </div>
            @endif

            @if ($notice->description)
                <div class="mt-6 pt-6 border-t">
                    <h2 class="text-lg font-semibold text-gray-900 mb-2">
                        Descriere
                    </h2>

                    <div class="text-gray-700 whitespace-pre-line">
                        {{ $notice->description }}
                    </div>
                </div>
            @endif

            <section class="mt-6 pt-6 border-t">
                <h2 class="text-lg font-semibold mb-3">Surse și actualizări</h2>
                @foreach ($notice->sources as $sourceEntry)
                    <p class="mb-2"><strong>{{ strtoupper($sourceEntry->source) }} · {{ $sourceEntry->external_id }}</strong>
                        — verificat la {{ $sourceEntry->last_seen_at?->format('d.m.Y H:i') }}
                        @if ($sourceEntry->url)
                            <a class="text-blue-600 hover:underline" href="{{ $sourceEntry->url }}" target="_blank" rel="noopener noreferrer">Vezi sursa originală</a>
                        @endif
                    </p>
                @endforeach
                <h3 class="font-semibold mt-4 mb-2">Istoric</h3>
                @forelse ($revisions as $revision)
                    <div class="border-t py-2 text-sm">
                        <strong>{{ ['baseline' => 'Date existente la unificare', 'source_added' => 'Sursă importată', 'updated' => 'Date actualizate', 'merged' => 'Înregistrări asociate prin referință exactă'][$revision->event_type] ?? 'Actualizare' }}</strong>
                        · {{ $revision->created_at?->format('d.m.Y H:i') }}
                        @if ($revision->event_type === 'updated')
                            <details><summary class="cursor-pointer text-blue-600">Vezi modificările</summary>
                            @foreach ($revision->changed_fields ?? [] as $field)
                                <p class="mt-2"><strong>{{ ['title' => 'Titlu', 'description' => 'Descriere', 'submission_deadline' => 'Termen de depunere', 'estimated_value' => 'Valoare', 'status' => 'Status', 'currency' => 'Monedă', 'county' => 'Județ', 'publication_date' => 'Data publicării'][$field] ?? str_replace('_', ' ', $field) }}:</strong>
                                {{ is_scalar($revision->before_data[$field] ?? null) ? $revision->before_data[$field] : '—' }} → {{ is_scalar($revision->after_data[$field] ?? null) ? $revision->after_data[$field] : '—' }}</p>
                            @endforeach
                            </details>
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-gray-600">Istoricul va fi înregistrat la următorul import.</p>
                @endforelse
                {{ $revisions->links() }}
            </section>
            @if ($notice->seap_url)
                <div class="mt-6">
                    <a
                        href="{{ $notice->seap_url }}"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-block bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700"
                    >
                        Vezi anunțul pe SEAP
                    </a>
                </div>
            @endif

        </div>
    </div>
</body>
</html>
