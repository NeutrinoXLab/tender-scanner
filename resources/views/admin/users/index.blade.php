@extends('layouts.private')
@section('title', 'Utilizatori — Tender Scanner')
@section('content')
    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <h1 class="text-2xl font-bold">Utilizatori</h1>
        <a href="{{ route('admin.users.create') }}" class="rounded-lg bg-blue-600 text-white px-4 py-3 font-medium">Creează utilizator</a>
    </div>
    <div class="space-y-3">
        @foreach($users as $user)
            <article class="rounded-lg border bg-white p-5 flex flex-wrap justify-between items-center gap-4">
                <div class="min-w-0">
                    <h2 class="font-semibold break-words">{{ $user->name }}</h2>
                    <p class="text-sm text-gray-600 break-all">{{ $user->email }}</p>
                    <p class="text-sm mt-2">{{ $user->role === 'admin' ? 'Admin' : 'User' }} · {{ $user->is_active ? 'Activ' : 'Dezactivat' }}</p>
                </div>
                <a href="{{ route('admin.users.edit', $user) }}" class="text-blue-700 hover:underline" aria-label="Modifică {{ $user->email }}">Modifică</a>
            </article>
        @endforeach
    </div>
@endsection
