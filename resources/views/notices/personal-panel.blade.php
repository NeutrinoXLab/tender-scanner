<section class="personal-workspace-panel" aria-label="Organizarea mea">
    <h2 class="text-lg font-semibold mb-2">Spațiul meu de lucru</h2>
    <p class="muted mb-4">Statusul și notițele sunt personale.</p>
    <div class="mb-5"><x-personal-badge :status="$personal->personal_status" /></div>
    @if (session('personal_message'))
        <p role="status" class="mb-4 text-sm text-green-800">{{ session('personal_message') }}</p>
    @endif
    @if ($errors->any())
        <div role="alert" class="mb-4 text-sm text-red-700">
            @foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach
        </div>
    @endif
    <div class="flex flex-col sm:flex-row flex-wrap gap-3 mb-5">
        <form method="POST" action="{{ route('notices.personal.update', $notice) }}">
            @csrf @method('PATCH')
            <input type="hidden" name="action" value="{{ $personal->is_saved ? 'unsave' : 'save' }}">
            <button type="submit" class="w-full rounded-lg border border-blue-600 px-4 py-2.5 text-blue-700 bg-white font-medium hover:bg-blue-50">{{ $personal->is_saved ? '⭐ Salvată · Elimină din salvate' : '⭐ Salvează' }}</button>
        </form>
        <span class="inline-flex items-center">
            <a href="{{ route('notices.suppliers', $notice) }}" class="inline-flex justify-center items-center rounded-lg bg-blue-600 px-5 py-2.5 text-white font-semibold hover:bg-blue-700">🔎 Caută furnizori</a>
            <x-info-tooltip label="Caută furnizori" align="end">Aplicația pregătește termenii de căutare, dar nu există încă o sursă live de furnizori conectată.</x-info-tooltip>
        </span>
        <form method="POST" action="{{ route('notices.personal.update', $notice) }}">
            @csrf @method('PATCH')
            <input type="hidden" name="action" value="{{ $personal->is_ignored ? 'restore' : 'ignore' }}">
            <button type="submit" class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-gray-700 bg-white hover:bg-gray-100">{{ $personal->is_ignored ? '↩ Nu mai ignora' : '🚫 Ignoră' }}</button>
        </form>
    </div>
    @if ($personal->is_ignored)
        <p class="mb-4 text-sm text-gray-600">Licitație ignorată. Datele rămân disponibile și poți reveni oricând.</p>
    @endif
    <form method="POST" action="{{ route('notices.personal.update', $notice) }}" class="space-y-4">
        @csrf @method('PATCH')
        <input type="hidden" name="action" value="update">
        <div>
            <div class="flex items-center mb-1">
                <label for="personal_status" class="block text-sm font-semibold">Status personal</label>
                <x-info-tooltip label="Status personal">Etapa ta personală: Nouă, Analizez, Cer ofertă, Particip sau Renunț. Nu schimbă statusul procedurii.</x-info-tooltip>
            </div>
            <select id="personal_status" name="personal_status" class="w-full sm:max-w-xs rounded-lg border border-gray-300 bg-white p-2.5">
                @foreach (\App\Models\Notice::PERSONAL_STATUSES as $value => $label)
                    <option value="{{ $value }}" @selected(old('personal_status', $personal->personal_status) === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <p class="text-xs text-gray-500 mt-1">Etapa ta de lucru, separată de statusul procedurii SEAP/TED.</p>
        </div>
        <div>
            <div class="flex items-center mb-1">
                <label for="personal_notes" class="block text-sm font-semibold">Notițele mele</label>
                <x-info-tooltip label="Notițe">Sunt personale, vizibile doar în contul tău și rămân asociate anunțului.</x-info-tooltip>
            </div>
            <textarea id="personal_notes" name="personal_notes" rows="3" maxlength="20000" placeholder="De exemplu: Am cerut ofertă distribuitorului X." class="block w-full rounded-lg border border-gray-300 bg-white p-3">{{ old('personal_notes', $personal->personal_notes) }}</textarea>
        </div>
        <button type="submit" class="rounded-lg border border-blue-600 bg-white px-4 py-2 text-blue-700 font-medium hover:bg-blue-50">Salvează statusul și notițele</button>
    </form>
</section>
