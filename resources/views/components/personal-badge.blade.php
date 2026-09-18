@props(['status' => 'new'])
@php($status = array_key_exists($status ?? '', \App\Models\Notice::PERSONAL_STATUSES) ? $status : 'new')
<span class="personal-badge status-{{ $status }}">Status personal: {{ \App\Models\Notice::PERSONAL_STATUSES[$status] }}</span>
