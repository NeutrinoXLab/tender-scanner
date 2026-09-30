@props(['label', 'align' => 'center'])
@php($tooltipId = 'info-tooltip-'.str()->uuid())

<span class="info-tooltip info-tooltip--{{ $align }}" data-info-tooltip>
    <button
        type="button"
        class="info-tooltip__trigger"
        aria-label="Mai multe informații despre {{ $label }}"
        aria-describedby="{{ $tooltipId }}"
        aria-expanded="false"
        data-info-tooltip-trigger
    >
        <svg viewBox="0 0 20 20" aria-hidden="true" focusable="false">
            <circle cx="10" cy="10" r="8.25"></circle>
            <path d="M10 8.5v5M10 6.1h.01"></path>
        </svg>
    </button>
    <span id="{{ $tooltipId }}" class="info-tooltip__bubble" role="tooltip" data-info-tooltip-content>{{ $slot }}</span>
</span>
