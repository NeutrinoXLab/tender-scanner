<a class="skip-link" href="#main-content">Sari la conținut</a>
<aside class="app-sidebar">
    <a class="brand-slot" href="{{ auth()->check() ? route('dashboard') : route('login') }}">
        {{-- Final shared brand asset: public/images/brand/elegant-premium-5.svg --}}
        <span class="brand-name">Tender Scanner<span class="brand-dot">.</span></span>
        <span class="brand-tagline">Oportunități azi. Afaceri mâine.</span>
    </a>
    @auth
        <nav class="sidebar-links" aria-label="Navigare principală">
            <span class="nav-caption">Spațiul tău de lucru</span>
            <a href="{{ route('dashboard') }}" @if(request()->routeIs('dashboard')) aria-current="page" @endif>Dashboard</a>
            <a href="{{ route('notices.index') }}" @if(request()->routeIs('notices.*') && !request('collection') && !request('personal_status')) aria-current="page" @endif>Licitații</a>
            <a href="{{ route('notices.index', ['collection' => 'saved']) }}" @if(request('collection') === 'saved') aria-current="page" @endif>Salvate</a>
            <a href="{{ route('notices.index', ['personal_status' => 'analyzing', 'seap_history' => 1]) }}" @if(request('personal_status') === 'analyzing') aria-current="page" @endif>În analiză</a>
            <a href="{{ route('notices.index', ['collection' => 'ignored']) }}" @if(request('collection') === 'ignored') aria-current="page" @endif>Ignorate</a>
            @can('manage-users')
                <span class="nav-caption">Administrare</span>
                <a href="{{ route('admin.users.index') }}" @if(request()->routeIs('admin.users.*')) aria-current="page" @endif>Utilizatori</a>
            @endcan
        </nav>
        <div class="sidebar-help">Furnizori și istoric comercial<span>Deschide o licitație și alege „Caută furnizori”.</span></div>
        <div class="sidebar-account">
            <span class="account-name">{{ auth()->user()->name }}</span>
            <span>{{ auth()->user()->isAdmin() ? 'Administrator' : 'Utilizator' }} · Cont privat</span>
            <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit">Ieșire din cont →</button></form>
        </div>
    @endauth
</aside>
