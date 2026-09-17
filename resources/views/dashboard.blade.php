@extends('layouts.private')
@section('title', 'Dashboard — Tender Scanner')
@section('content')
    <header class="page-heading">
        <div><p class="eyebrow">Privire de ansamblu</p><h1>Dashboard</h1><p>Oportunitățile din catalog și licitațiile pe care le urmărești.</p></div>
        <a class="primary-link" href="{{ route('notices.index') }}">Explorează licitațiile →</a>
    </header>
    <div class="kpi-grid">
        <a class="metric-card" href="{{ route('notices.index', ['source' => 'seap']) }}"><span>SEAP în catalog</span><strong>{{ number_format($sourceCounts['seap'] ?? 0, 0, ',', '.') }}</strong><small>Selecția implicită din Licitații</small></a>
        <a class="metric-card accent-purple" href="{{ route('notices.index', ['source' => 'ted']) }}"><span>TED în catalog</span><strong>{{ number_format($sourceCounts['ted'] ?? 0, 0, ',', '.') }}</strong><small>Anunțuri TED importate</small></a>
        <a class="metric-card accent-green" href="{{ route('notices.index', ['collection' => 'saved']) }}"><span>Salvate de tine</span><strong>{{ number_format($savedCount, 0, ',', '.') }}</strong><small>Inclusiv proceduri din istoric</small></a>
        <a class="metric-card accent-orange" href="{{ route('notices.index', ['personal_status' => 'analyzing', 'seap_history' => 1]) }}"><span>În analiza ta</span><strong>{{ number_format($analyzingCount, 0, ',', '.') }}</strong><small>Inclusiv proceduri din istoric</small></a>
    </div>
    <div class="dashboard-columns">
        <section class="surface"><div class="section-heading"><h2>Publicate recent</h2><a href="{{ route('notices.index') }}">Vezi toate →</a></div>
            <p class="muted mb-4">Cele mai recente date de publicare din catalogul local.</p>
            @forelse($recentNotices as $notice)
                <article class="dashboard-notice">
                    <div class="flex gap-2 items-center mb-2"><span class="source-badge">{{ strtoupper($notice->source) }}</span><span class="muted">{{ $notice->publication_date?->format('d.m.Y') ?? 'Dată indisponibilă' }}</span></div>
                    <h3><a href="{{ route('notices.show', $notice) }}">{{ $notice->title }}</a></h3>
                    <p class="muted mt-1">{{ $notice->authority_name ?? 'Autoritate indisponibilă' }}</p>
                    <p class="deadline-inline">Termen: {{ $notice->submission_deadline?->format('d.m.Y H:i') ?? 'Necomunicat' }}</p>
                </article>
            @empty
                <div class="empty-state"><h3>Catalogul este încă gol</h3><p>Anunțurile disponibile în baza locală vor apărea aici.</p></div>
            @endforelse
        </section>
        <div class="space-y-6">
            <section class="surface"><div class="section-heading"><h2>Salvate de tine</h2><a href="{{ route('notices.index', ['collection' => 'saved']) }}">Toate →</a></div>
                @forelse($savedNotices as $notice)
                    <article class="dashboard-notice"><h3><a href="{{ route('notices.show', $notice) }}">{{ $notice->title }}</a></h3><p class="muted mt-2">{{ \App\Models\Notice::PERSONAL_STATUSES[$notice->personalEntries->first()?->personal_status] ?? 'Nouă' }}</p></article>
                @empty
                    <div class="empty-state"><h3>Un loc pentru lista ta scurtă</h3><p>Deschide o licitație și apasă „Salvează” pentru a o regăsi aici.</p></div>
                @endforelse
            </section>
            <section class="tip-card"><p class="eyebrow">Sfat de utilizare</p><h2>Începe cu termenul de depunere.</h2><p>Verifică documentația din sursa oficială, apoi folosește statusul și notițele personale pentru pașii următori.</p></section>
        </div>
    </div>
@endsection
