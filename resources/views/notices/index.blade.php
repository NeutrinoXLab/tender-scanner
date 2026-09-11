<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Tender Scanner — Licitații</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="bg-gray-100">
    <nav class="bg-white border-b px-6 py-4"><a href="{{ route('notices.index') }}" class="font-bold text-blue-700">Tender Scanner</a><span class="ml-6 text-gray-600">Licitații</span></nav>

    <div class="max-w-7xl mx-auto px-4 py-8">

        {{-- Header --}}
        <div class="mb-6">
            <h1 class="text-2xl font-bold text-gray-900">
                Tender Scanner — Licitații
            </h1>

            <p class="text-gray-600 mt-1">
                {{ $notices->total() }} anunțuri în baza locală
            </p>
        </div>

        @include('notices.scan-panel')

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
        <div class="bg-white border rounded-xl p-5 mb-6">

            <form
                method="GET"
                action="{{ route('notices.index') }}"
            >

                {{-- Căutare generală --}}
                <input type="hidden" name="collection" value="{{ $collection }}">
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
                <label class="block text-sm text-gray-700 mb-4">
                    <input type="checkbox" name="seap_history" value="1" @checked(request()->boolean('seap_history'))>
                    Include și procedurile SEAP cu termen încheiat sau status inactiv
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

                {{-- Filtre --}}
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

                        <div>
                            <label for="source" class="block text-sm font-medium text-gray-700 mb-1">Sursă</label>
                            <select id="source" name="source" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="">Toate sursele</option>
                                <option value="seap" @selected($source === 'seap')>SEAP</option>
                                <option value="ted" @selected($source === 'ted')>TED</option>
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

                {{-- Butoane --}}
                <div class="border-t mt-5 pt-5 flex flex-col sm:flex-row gap-3">

                    <button
                        type="submit"
                        class="inline-flex justify-center items-center px-6 py-2.5 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700"
                    >
                        Caută
                    </button>

                    @if (
                        $collection !== 'all' || $personalStatus || request()->boolean('seap_history') ||
                        $source ||
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

                <article class="bg-white border rounded-lg p-5">

                    <div class="flex flex-col gap-2">

                        {{-- Număr și status --}}
                        <p class="text-xs text-gray-600">
                            {{ $notice->is_saved ? '⭐ Salvată · ' : ($notice->is_ignored ? '🚫 Ignorată · ' : '') }}Status personal: {{ \App\Models\Notice::PERSONAL_STATUSES[$notice->personal_status] ?? 'Nouă' }}
                        </p>
                        <div class="flex flex-wrap items-center gap-2">

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
                                <span class="text-sm text-gray-600">
                                    {{ $notice->status }}
                                </span>
                            @endif

                        </div>

                        {{-- Titlu --}}
                        <h2 class="text-lg font-semibold text-gray-900">
                            <a
                                href="{{ route('notices.show', $notice) }}"
                                class="hover:text-blue-600"
                            >
                                {{ $notice->title }}
                            </a>
                        </h2>

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
                        {{-- Link detalii --}}
                        <a
                            href="{{ route('notices.show', $notice) }}"
                            class="text-sm text-blue-600 hover:underline"
                        >
                            Vezi detalii →
                        </a>

                        {{-- Informații --}}
                        <div class="text-sm text-gray-600 space-y-1">

                            <div>
                                <strong>Autoritate:</strong>
                                {{ $notice->authority_name ?? '—' }}
                            </div>

                            <div>
                                <strong>Județ:</strong>
                                {{ $notice->county ?? '—' }}
                            </div>

                            <div>
                                <strong>CPV:</strong>
                                {{ $notice->cpv_code ?? '—' }}
                            </div>

                            <div>
                                <strong>Valoare:</strong>
                                {{ $notice->estimated_value ?? '—' }}
                                {{ $notice->currency ?? '' }}
                            </div>

                            <div>
                                <strong>Publicat:</strong>
                                {{ $notice->publication_date?->format('d.m.Y H:i') ?? '—' }}
                            </div>

                            <div>
                                <strong>Termen:</strong>
                                {{ $notice->submission_deadline?->format('d.m.Y H:i') ?? '—' }}
                            </div>

                        </div>

                    </div>

                </article>

            @empty

                <div class="bg-white border rounded-lg p-6 text-gray-600">
                    Nu există anunțuri care să corespundă criteriilor.
                </div>

            @endforelse

        </div>

        {{-- Paginare --}}
        <div class="mt-6">
            {{ $notices->links() }}
        </div>

    </div>

</body>
</html>
