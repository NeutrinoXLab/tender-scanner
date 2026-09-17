@extends('layouts.private')
@section('title', "Caută furnizori — Tender Scanner")
@section('content')
        <a class="text-blue-600 hover:underline" href="{{ route('notices.show', $notice) }}">← Înapoi la licitație</a>
        <header class="my-6">
            <h1 class="text-2xl font-bold text-gray-900">🔎 Caută furnizori</h1>
            <p class="mt-3 font-medium text-gray-800 break-words">{{ $notice->notice_number }} · {{ $notice->title }}</p>
            <div class="flex flex-col sm:flex-row sm:gap-6 mt-2 text-sm text-gray-600">
                <p>CPV: {{ $notice->cpv_code ?: '—' }}</p>
                <p>Termen limită: {{ $notice->submission_deadline?->format('d.m.Y H:i') ?? '—' }}</p>
            </div>
        </header>
        <section class="bg-white rounded-lg border p-5 mb-6" aria-label="Termeni de căutare">
            <h2 class="font-semibold text-gray-900">Ce căutăm</h2>
            <p class="text-sm text-gray-600 mt-1 mb-4">Termeni propuși din datele licitației. Verifică-i și ajustează-i pentru produsul dorit.</p>
            @if ($suggestions !== [])
                <div class="flex flex-wrap gap-2 mb-4">
                    @foreach ($suggestions as $index => $suggestion)
                        <a href="{{ route('notices.suppliers', ['notice' => $notice, 'term' => $index]) }}" @if ($term === $index) aria-current="true" @endif
                           class="max-w-full break-words rounded-lg border px-3 py-2 text-sm {{ $term === $index ? 'border-blue-600 bg-blue-50 text-blue-800' : 'border-gray-300 text-gray-700 hover:bg-gray-50' }}">{{ $suggestion['query'] }}</a>
                    @endforeach
                </div>
                <p class="text-xs text-gray-500 mb-4">Originea propunerii selectate: {{ $suggestions[$term]['source'] }}. Fiecare termen poate fi căutat separat.</p>
            @else
                <p class="text-sm text-gray-600 mb-4">Nu am identificat termeni suficient de clari. Introdu manual produsul căutat.</p>
            @endif
            @if ($errors->any())
                <div role="alert" class="mb-4 text-sm text-red-700">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>
            @endif
            <form method="GET" action="{{ route('notices.suppliers', $notice) }}">
                <input type="hidden" name="term" value="{{ $term }}">
                <label for="supplier-query" class="block text-sm font-medium mb-1">Produs sau termeni de căutare</label>
                <div class="flex flex-col sm:flex-row gap-3">
                    <input id="supplier-query" name="q" type="text" value="{{ old('q', $query) }}" maxlength="240" required class="min-w-0 flex-1 w-full rounded-lg border border-gray-300 p-3">
                    <button type="submit" class="rounded-lg bg-blue-600 text-white font-semibold px-6 py-3 hover:bg-blue-700">Caută</button>
                </div>
            </form>
            @if ($context['ean'] || $context['quantity'])
                <p class="mt-3 text-sm text-gray-600">EAN: {{ $context['ean'] ?? '—' }} · Cantitate: {{ $context['quantity'] ? $context['quantity']['value'].' '.$context['quantity']['unit'] : '—' }}</p>
            @endif
        </section>
        <section class="bg-white rounded-lg border p-5 mb-6" aria-label="Experiență publică documentată">
            <h2 class="text-lg font-semibold">Experiență publică documentată — SEAP</h2>
            <p class="text-sm text-gray-600 my-3">Contracte, achiziții directe și notificări din exporturile oficiale. Numărul înregistrărilor indică experiență documentată, nu un scor de calitate. Potrivirea pe categorie CPV trebuie verificată pentru produsul dorit. Valorile pot reprezenta contracte comune mai multor câștigători. Nu presupunem că o achiziție DA și o notificare DAN reprezintă tranzacții diferite dacă sursa nu oferă legătura dintre ele.</p>
            @forelse ($history as $supplier)
                <article class="border rounded-lg p-4 my-3">
                    <h3 class="font-semibold">{{ $supplier['name'] }} · CUI {{ $supplier['cui'] ?? 'necunoscut' }}</h3>
                    <p>{{ $supplier['count'] }} înregistrări relevante · Cel mai recent: {{ $supplier['latest'] ?? 'dată necunoscută' }}</p>
                    <p class="text-sm text-gray-500">Sunt afișate maximum 5 înregistrări recente de contract / lot / achiziție directă.</p>
                    @foreach ($supplier['contracts'] as $contract)
                        <div class="mt-3 text-sm">
                            <p>{{ $contract['match'] }} · {{ $contract['cpv'] }} · {{ $contract['cpv_name'] }}</p>
                            <p>{{ $contract['title'] ?? 'Denumirea procedurii nu este disponibilă în exportul de contracte.' }}</p>
                            <p>{{ $contract['notice'] }} · {{ $contract['award'] }} · {{ $contract['type'] }} {{ $contract['number'] }} / {{ $contract['date'] }} · {{ $contract['value'] ?? '—' }} {{ $contract['currency'] }}</p>
                            <a class="text-blue-600 hover:underline" href="{{ $contract['url'] }}" target="_blank" rel="noopener noreferrer">Export oficial · rând {{ $contract['row'] }}</a>
                        </div>
                    @endforeach
                </article>
            @empty
                <p class="text-sm text-gray-600">Nu există contracte relevante în istoricul importat. Aceasta nu înseamnă că firma sau domeniul nu are experiență publică.</p>
            @endforelse
        </section>
        @if (! $configured)
            <p role="status" class="rounded-lg border bg-white p-5 text-gray-700">Nu este configurată încă o sursă live de furnizori. Poți pregăti și modifica termenii de căutare. Rezultatele vor apărea după conectarea unei surse comerciale.</p>
        @elseif ($result === null)
            <p class="text-gray-600">Alege un termen și apasă „Caută” pentru a consulta sursa de furnizori.</p>
        @endif
        @if ($result !== null && $result->status === 'error')
            <p role="alert" class="rounded-lg border border-red-200 bg-white p-5 text-red-700">{{ $result->message }}</p>
        @elseif ($result !== null && $result->status === 'ok')
            <section class="bg-white border rounded-lg p-5" aria-label="Rezultate furnizori">
                <h2 class="text-lg font-semibold mb-4">Rezultate furnizori</h2>
                @if ($result->items === [])
                    <p role="status" class="text-gray-600">Nu au fost găsiți furnizori pentru această căutare. Încearcă termeni mai preciși sau o altă formulare.</p>
                @else
                    <p class="text-sm text-gray-500 mb-4">{{ count($result->items) }} rezultate primite de la sursa conectată. Potrivirea, când există, este furnizată de aceasta.</p>
                    <div class="space-y-4">
                        @foreach ($result->items as $item)
                            <article class="rounded-lg border p-4">
                                <h3 class="font-semibold break-words">{{ $item['product'] ?? '—' }}</h3>
                                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm mt-3">
                                    <div><dt class="text-gray-500">Furnizor</dt><dd class="break-words">{{ $item['supplier'] ?? '—' }}</dd></div>
                                    <div><dt class="text-gray-500">Preț</dt><dd>{{ $item['price'] ?? '—' }} {{ $item['price'] !== null ? ($item['currency'] ?? '') : '' }}</dd></div>
                                    <div><dt class="text-gray-500">Disponibilitate</dt><dd>{{ $item['availability'] ?? '—' }}</dd></div>
                                    <div><dt class="text-gray-500">Potrivire</dt><dd>{{ $item['match_score'] !== null ? round($item['match_score'] * 100).'%' : '—' }}</dd></div>
                                </dl>
                                @if ($item['url'])
                                    <a href="{{ $item['url'] }}" target="_blank" rel="noopener noreferrer" class="inline-block mt-3 text-sm text-blue-600 hover:underline">Vezi produsul ↗</a>
                                @else
                                    <p class="text-sm mt-3 text-gray-500">Link produs: —</p>
                                @endif
                            </article>
                        @endforeach
                    </div>
                @endif
            </section>
        @endif
@endsection
