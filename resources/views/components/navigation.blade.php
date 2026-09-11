<nav class="bg-white border-b px-4 sm:px-6 py-4 flex flex-wrap items-center justify-between gap-3" aria-label="Navigare principală">
    <a href="{{ auth()->check() ? route('notices.index') : route('login') }}" class="font-bold text-blue-700">Tender Scanner</a>
    @auth
        <div class="flex flex-wrap items-center gap-4 text-sm">
            <a href="{{ route('notices.index') }}" class="text-blue-700 hover:underline">Licitații</a>
            @can('manage-users')<a href="{{ route('admin.users.index') }}" class="text-blue-700 hover:underline">Utilizatori</a>@endcan
            <span class="text-gray-600 break-words">{{ auth()->user()->name }}</span>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="rounded-lg border px-3 py-2 hover:bg-gray-50">Ieșire din cont</button>
            </form>
        </div>
    @endauth
</nav>
