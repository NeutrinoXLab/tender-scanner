@extends('layouts.private')
@section('title', 'Licitații — Tender Scanner')
@section('content')

        {{-- Header --}}
        <header class="page-heading"><div>
            <h1 class="text-2xl font-bold text-gray-900">
                Licitații
            </h1>

            <p class="text-gray-600 mt-1">
                {{ $notices->total() }} anunțuri corespund selecției tale
            </p>
        </div></header>

        <details class="surface mb-6" @if($latestScan && in_array($latestScan->status, ['queued', 'running'])) open @endif>
            <summary class="font-semibold text-sm text-blue-700">Sincronizare SEAP + TED · comenzi și progres</summary>
            <div class="mt-4">@include('notices.scan-panel')</div>
        </details>

        <nav class="flex flex-wrap items-center gap-2 mb-4" aria-label="Filtrare după sursă">
            <span class="text-sm font-semibold text-gray-700 mr-2">Sursă</span>
            @foreach (['' => 'Toate', 'seap' => 'SEAP', 'ted' => 'TED'] as $value => $label)
                <a href="{{ route('notices.index', array_merge(request()->except($value === 'ted' ? ['page', 'source', 'family'] : ['page', 'source']), $value === '' ? [] : ['source' => $value])) }}"
                   @if (($source ?? '') === $value) aria-current="page" @endif
                   class="rounded-lg border px-4 py-2 text-sm font-medium {{ ($source ?? '') === $value ? 'bg-blue-600 border-blue-600 text-white' : 'bg-white border-gray-300 text-gray-700 hover:bg-gray-50' }}">{{ $label }}</a>
            @endforeach
        </nav>

        <nav class="flex flex-wrap gap-2 mb-4" aria-label="Organizarea licitațiilor">
            @foreach (['all' => 'Toate', 'saved' => '⭐ Salvate', 'ignored' => '🚫 Ignorate'] as $value => $label)
                <a href="{{ route('notices.index', array_merge(request()->except(['page', 'collection']), ['collection' => $value])) }}"
                   @if ($collection === $value) aria-current="page" @endif
                   class="rounded-lg border px-4 py-2 text-sm font-medium {{ $collection === $value ? 'bg-blue-600 border-blue-600 text-white' : 'bg-white border-gray-300 text-gray-700 hover:bg-gray-50' }}">{{ $label }}</a>
            @endforeach
        </nav>
        @if ($collection !== 'all')
            <p class="text-sm text-gray-600 mb-4">Această listă include și procedurile SEAP expirate sau inactive.</p>
        @endif

        {{-- Panou filtre --}}
        <div class="filter-panel bg-white border rounded-xl p-5 mb-6">

            <form
                method="GET"
                action="{{ route('notices.index') }}"
            >

                {{-- Căutare generală --}}
                @if ($source)<input type="hidden" name="source" value="{{ $source }}">@endif
                <input type="hidden" name="collection" value="{{ $collection }}">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6">
                @if ($source !== 'ted')
                    <div class="mb-4">
                        <label for="family-filter" class="block text-sm font-medium text-gray-700 mb-1">Tip SEAP</label>
                        <select name="family" id="family-filter" class="w-full sm:max-w-xs border border-gray-300 bg-white p-2 rounded-lg">
                            <option value="">Toate tipurile</option>
                            @foreach (['ADV', 'PI', 'CN', 'SCN'] as $family)
                                <option value="{{ $family }}" @selected(request('family') === $family)>{{ $family }}</option>
                            @endforeach
                        </select>
                        <p class="text-xs text-gray-500 mt-1">ADV sunt anunțuri de publicitate. Achizițiile DA/DAN finalizate rămân în istoricul furnizorilor.</p>
                    </div>
                @endif
                @if ($errors->any())
                    <div role="alert" class="text-sm text-red-700 mb-3">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>
                @endif
                <div class="mb-4">
                    <label for="personal-status-filter" class="block text-sm font-medium text-gray-700 mb-1">Status personal</label>
                    <select name="personal_status" id="personal-status-filter" class="w-full sm:max-w-xs border border-gray-300 bg-white p-2 rounded-lg">
                        <option value="">Toate etapele personale</option>
                        @foreach (\App\Models\Notice::PERSONAL_STATUSES as $value => $label)
                            <option value="{{ $value }}" @selected($personalStatus === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                </div>
                <label class="block text-sm text-gray-700 mb-4">
                    <input type="checkbox" name="seap_history" value="1" @checked(request()->boolean('seap_history'))>
                    Include istoricul SEAP și procedurile fără termen de participare confirmat
                </label>
                <div class="mb-5">
                    <label
                        for="search"
                        class="block text-sm font-medium text-gray-700 mb-1"
                    >
                        Căutare
                    </label>

                    <input
                        type="text"
                        id="search"
                        name="search"
                        value="{{ $search }}"
                        placeholder="Titlu, număr, autoritate sau descriere..."
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                    >
                </div>

                <details class="filter-disclosure" @if($county || $cpv || $minValue !== null || $maxValue !== null || $deadlineFrom || $deadlineTo || $status || $contractType || $procedureType || $assignmentType || $euFunded !== null || $fundingProgram) open @endif><summary>Filtre avansate · locație, valoare, termen și procedură</summary>
                <div class="border-t pt-5">

                    <div class="mb-3">
                        <h2 class="text-sm font-semibold text-gray-800">
                            Filtre
                        </h2>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">

                        {{-- Județ --}}
                        <div>
                            <label
                                for="county"
                                class="block text-sm font-medium text-gray-700 mb-1"
                            >
                                Județ
                            </label>

                            <select
                                id="county"
                                name="county"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                            >
                                <option value="">Toate județele</option>

                                @foreach ($counties as $countyOption)
                                    <option
                                        value="{{ $countyOption }}"
                                        @selected($county === $countyOption)
                                    >
                                        {{ $countyOption }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        {{-- CPV --}}
                        <div>
                            <label
                                for="cpv"
                                class="block text-sm font-medium text-gray-700 mb-1"
                            >
                                CPV
                            </label>

                            <input
                                type="text"
                                id="cpv"
                                name="cpv"
                                value="{{ $cpv }}"
                                placeholder="Cod sau denumire CPV..."
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                            >
                        </div>

                        {{-- Status --}}
                        <div>
                            <label
                                for="status"
                                class="block text-sm font-medium text-gray-700 mb-1"
                            >
                                Status
                            </label>

                            <select
                                id="status"
                                name="status"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                            >
                                <option value="">Toate statusurile</option>

                                @foreach ($statuses as $statusOption)
                                    <option
                                        value="{{ $statusOption }}"
                                        @selected($status === $statusOption)
                                    >
                                        {{ $statusOption }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        {{-- Tip contract --}}
                        <div>
                            <label
                                for="contract_type"
                                class="block text-sm font-medium text-gray-700 mb-1"
                            >
                                Tip contract
                            </label>

                            <select
                                id="contract_type"
                                name="contract_type"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                            >
                                <option value="">Toate tipurile</option>

                                @foreach ($contractTypes as $contractTypeOption)
                                    <option
                                        value="{{ $contractTypeOption }}"
                                        @selected($contractType === $contractTypeOption)
                                    >
                                        {{ $contractTypeOption }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        {{-- Procedură --}}
                        <div>
                            <label
                                for="procedure_type"
                                class="block text-sm font-medium text-gray-700 mb-1"
                            >
                                Procedură
                            </label>

                            <select
                                id="procedure_type"
                                name="procedure_type"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                            >
                                <option value="">Toate procedurile</option>

                                @foreach ($procedureTypes as $procedureTypeOption)
                                    <option
                                        value="{{ $procedureTypeOption }}"
                                        @selected($procedureType === $procedureTypeOption)
                                    >
                                        {{ $procedureTypeOption }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        {{-- Tip atribuire --}}
                        <div>
                            <label
                                for="assignment_type"
                                class="block text-sm font-medium text-gray-700 mb-1"
                            >
                                Tip atribuire
                            </label>

                            <select
                                id="assignment_type"
                                name="assignment_type"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                            >
                                <option value="">Toate tipurile</option>

                                @foreach ($assignmentTypes as $assignmentTypeOption)
                                    <option
                                        value="{{ $assignmentTypeOption }}"
                                        @selected($assignmentType === $assignmentTypeOption)
                                    >
                                        {{ $assignmentTypeOption }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        {{-- Finanțare UE --}}
                        <div>
                            <label for="is_eu_funded" class="block text-sm font-medium text-gray-700 mb-1">Finanțare UE</label>
                            <select id="is_eu_funded" name="is_eu_funded" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="">Toate anunțurile</option>
                                <option value="1" @selected($euFunded === '1')>Da</option>
                                <option value="0" @selected($euFunded === '0')>Nu</option>
                                <option value="unknown" @selected($euFunded === 'unknown')>Nespecificat</option>
                            </select>
                        </div>
                        <div>
                            <label for="funding_program" class="block text-sm font-medium text-gray-700 mb-1">Program de finanțare</label>
                            <input type="text" id="funding_program" name="funding_program" value="{{ $fundingProgram }}" maxlength="255" placeholder="Denumirea programului..." class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        {{-- Valoare minimă --}}
                        <div>
                            <label
                                for="min_value"
                                class="block text-sm font-medium text-gray-700 mb-1"
                            >
                                Valoare minimă
                            </label>

                            <input
                                type="number"
                                id="min_value"
                                name="min_value"
                                value="{{ $minValue }}"
                                min="0"
                                step="0.01"
                                placeholder="RON"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                            >
                        </div>

                        {{-- Valoare maximă --}}
                        <div>
                            <label
                                for="max_value"
                                class="block text-sm font-medium text-gray-700 mb-1"
                            >
                                Valoare maximă
                            </label>

                            <input
                                type="number"
                                id="max_value"
                                name="max_value"
                                value="{{ $maxValue }}"
                                min="0"
                                step="0.01"
                                placeholder="RON"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                            >
                        </div>

                        {{-- Termen de la --}}
                        <div>
                            <label
                                for="deadline_from"
                                class="block text-sm font-medium text-gray-700 mb-1"
                            >
                                Termen de la
                            </label>

                            <input
                                type="date"
                                id="deadline_from"
                                name="deadline_from"
                                value="{{ $deadlineFrom }}"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                            >
                        </div>

                        {{-- Termen până la --}}
                        <div>
                            <label
                                for="deadline_to"
                                class="block text-sm font-medium text-gray-700 mb-1"
                            >
                                Termen până la
                            </label>

                            <input
                                type="date"
                                id="deadline_to"
                                name="deadline_to"
                                value="{{ $deadlineTo }}"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                            />
                        </div>

                    </div>
                </div>

                </details><!-- Filter actions -->
                <div class="border-t mt-5 pt-5 flex flex-col sm:flex-row gap-3">

                    <button
                        type="submit"
                        class="inline-flex justify-center items-center px-6 py-2.5 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700"
                    >
                        Caută
                    </button>

                    @if (
                        $collection !== 'all' || $personalStatus || request()->boolean('seap_history') ||
                        $source || request('family') ||
                        $euFunded !== null ||
                        $fundingProgram ||
                        $search ||
                        $county ||
                        $cpv ||
                        ($minValue !== null && $minValue !== '') ||
                        ($maxValue !== null && $maxValue !== '') ||
                        $deadlineFrom ||
                        $deadlineTo ||
                        $status ||
                        $contractType ||
                        $procedureType ||
                        $assignmentType
                    )
                        <a
                            href="{{ route('notices.index') }}"
                            class="inline-flex justify-center items-center px-6 py-2.5 bg-gray-200 text-gray-700 font-medium rounded-lg hover:bg-gray-300"
                        >
                            Resetează
                        </a>
                    @endif

                </div>

            </form>

        </div>

        {{-- Lista anunțurilor --}}
        <div class="space-y-4">

            @forelse ($notices as $notice)

                @php($entry = $notice->personalEntries->first())
                <article class="notice-card tender-card" aria-labelledby="notice-title-{{ $notice->id }}">
                    <div class="tender-identity">
                        <span class="source-badge {{ $notice->source === 'ted' ? 'ted' : 'seap' }}">Sursă: {{ strtoupper($notice->source ?? 'seap') }}</span>
                        @if($notice->source === 'seap' && $notice->ted_id)<span class="source-badge ted">+ TED</span>@endif
                        @if($notice->source === 'seap' && data_get($notice->seap_metadata, 'family'))
                            <span class="family-badge family-{{ strtolower(data_get($notice->seap_metadata, 'family')) }}">{{ data_get($notice->seap_metadata, 'family') }}</span>
                        @endif
                    </div>
                    <div class="tender-description">
                        <h2 id="notice-title-{{ $notice->id }}" class="notice-title"><a href="{{ route('notices.show', $notice) }}">{{ $notice->title }}</a></h2>
                        <p class="tender-authority">{{ $notice->authority_name ?? 'Autoritate indisponibilă' }}</p>
                        <p class="muted mt-2">{{ collect([$notice->county, $notice->locality])->filter()->unique()->implode(' · ') ?: 'Localizare necomunicată' }}</p>
                        <div class="tender-tags">
                            @if($notice->cpv_code)<span>CPV {{ $notice->cpv_code }}</span>@endif
                            @if($notice->contract_type)<span>{{ $notice->contract_type }}</span>@endif
                            @if($notice->procedure_type)<span>{{ $notice->procedure_type }}</span>@endif
                        </div>
                        <p class="muted">{{ $notice->notice_number }} · Publicat: {{ $notice->publication_date?->format('d.m.Y H:i') ?? '—' }}</p>
                        @if($notice->notice_type)<p class="muted">{{ $notice->notice_type }}</p>@endif
                        @if($notice->status)<p class="muted">Stare procedură: {{ $notice->status }}</p>@endif
                    </div>
                    <div class="tender-decision">
                        <div class="tender-value"><span class="fact-label">Valoare estimată</span><strong>{{ $notice->estimated_value !== null ? number_format((float) $notice->estimated_value, 2, ',', '.') : '—' }} {{ $notice->currency ?? '' }}</strong></div>
                        <x-notice-deadline :notice="$notice" />
                        <div class="tender-personal">
                            <x-personal-badge :status="$entry?->personal_status" />
                            @if($entry?->is_saved)<span class="collection-badge">Salvată</span>@endif
                            @if($entry?->is_ignored)<span class="collection-badge">Ignorată</span>@endif
                        </div>
                    </div>
                    <div class="tender-bottom">
                        <div class="tender-actions">
                            <a class="primary-link" href="{{ route('notices.show', $notice) }}">Vezi detalii →</a>
                            <a class="quiet-action" href="{{ route('notices.suppliers', $notice) }}">Caută furnizori</a>
                            @if($notice->seap_url)<a class="quiet-action" href="{{ $notice->seap_url }}" target="_blank" rel="noopener noreferrer">Anunț oficial SEAP ↗</a>@endif
                            @if($notice->ted_url)<a class="quiet-action" href="{{ $notice->ted_url }}" target="_blank" rel="noopener noreferrer">Vezi anunțul pe TED ↗</a>@endif
                            <form method="POST" action="{{ route('notices.personal.update', $notice) }}">
                                @csrf @method('PATCH')
                                <input type="hidden" name="action" value="{{ $entry?->is_saved ? 'unsave' : 'save' }}">
                                <button class="quiet-action" aria-label="{{ $entry?->is_saved ? 'Elimină din salvate' : 'Salvează' }}: {{ $notice->title }}">{{ $entry?->is_saved ? 'Elimină din salvate' : 'Salvează' }}</button>
                            </form>
                            <form method="POST" action="{{ route('notices.personal.update', $notice) }}">
                                @csrf @method('PATCH')
                                <input type="hidden" name="action" value="{{ $entry?->is_ignored ? 'restore' : 'ignore' }}">
                                <button class="quiet-action" aria-label="{{ $entry?->is_ignored ? 'Nu mai ignora' : 'Ignoră' }}: {{ $notice->title }}">{{ $entry?->is_ignored ? 'Nu mai ignora' : 'Ignoră' }}</button>
                            </form>
                        </div>
                    <details class="mb-4"><summary class="text-sm text-blue-700">Sumar și descriere</summary>
                        <section class="text-sm text-gray-700 border-t pt-3 mt-2" aria-label="Sumar anunț">
                            <h3 class="font-semibold mb-2">Sumar anunț</h3>
                            @php($summaryItems = $notice->summaryItems())
                            @forelse (array_slice($summaryItems, 0, 3) as $item)
                                <p class="mb-2"><strong>{{ $item['label'] }}:</strong> {{ \Illuminate\Support\Str::limit($item['text'], 450) }}</p>
                            @empty
                                <p>Descrierea nu este disponibilă în datele importate.</p>
                            @endforelse
                            @if (count($summaryItems) > 3 || collect($summaryItems)->contains(fn ($item) => mb_strlen($item['text']) > 450))
                                <details class="mt-2">
                                    <summary class="cursor-pointer text-blue-600">Vezi toate informațiile din sumar</summary>
                                    @foreach ($summaryItems as $item)
                                        <p class="mt-2"><strong>{{ $item['label'] }}:</strong> {{ $item['text'] }}</p>
                                    @endforeach
                                </details>
                            @endif
                            @if ($summaryItems !== [])
                                <p class="text-xs text-gray-500 mt-2">Extras din anunțul {{ strtoupper($notice->source ?? 'seap') }}.</p>
                            @endif
                        </section>
                    </details>

                    </div>
                </article>

            @empty

                <div class="surface empty-state">
                    <h2 class="font-semibold mb-2">Niciun rezultat pentru această selecție</h2>
                    <p>Nu există anunțuri care să corespundă criteriilor.</p>
                    <a class="inline-block mt-4 text-blue-700" href="{{ route('notices.index') }}">Resetează filtrele →</a>
                </div>

            @endforelse

        </div>

        {{-- Paginare --}}
        <div class="mt-6">
            {{ $notices->links() }}
        </div>
@endsection
