@props(['notice'])
@php
    // Imported timestamps are stored as Bucharest wall time, without a timezone.
    $deadline = $notice->submission_deadline
        ? \Carbon\CarbonImmutable::parse($notice->submission_deadline->format('Y-m-d H:i:s'), 'Europe/Bucharest')
        : null;
    $now = now('Europe/Bucharest');
    $state = ! $deadline ? 'unknown' : ($deadline->lte($now) ? 'expired' : ($deadline->lte($now->copy()->addDays(7)) ? 'soon' : 'normal'));
    $labels = ['unknown' => 'Termen necomunicat', 'expired' => 'Termen expirat', 'soon' => 'În următoarele 7 zile', 'normal' => 'Termen de depunere'];
@endphp
<div class="tender-deadline deadline-{{ $state }}">
    <span class="fact-label">Termen limită</span>
    <strong>{{ $deadline?->format('d.m.Y H:i') ?? 'Necomunicat' }}</strong>
    <span class="deadline-caption">{{ $labels[$state] }}</span>
</div>
