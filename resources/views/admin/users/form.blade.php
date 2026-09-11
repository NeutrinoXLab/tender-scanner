@extends('layouts.private')
@section('title', ($user->exists ? 'Modifică utilizator' : 'Creează utilizator').' — Tender Scanner')
@section('content')
    <a href="{{ route('admin.users.index') }}" class="text-blue-700 hover:underline">← Utilizatori</a>
    <section class="max-w-xl mt-5 bg-white rounded-lg border p-6">
        <h1 class="text-2xl font-bold mb-6">{{ $user->exists ? 'Modifică utilizator' : 'Creează utilizator' }}</h1>
        <form method="POST" action="{{ $user->exists ? route('admin.users.update', $user) : route('admin.users.store') }}" class="space-y-4">
            @csrf
            @if($user->exists) @method('PATCH') @endif
            <div><label for="name" class="block text-sm font-medium mb-1">Nume</label><input id="name" name="name" value="{{ old('name', $user->name) }}" required maxlength="255" autocomplete="name" class="w-full border rounded-lg p-3"></div>
            <div><label for="email" class="block text-sm font-medium mb-1">Email</label><input id="email" name="email" type="email" value="{{ old('email', $user->email) }}" required maxlength="255" autocomplete="off" class="w-full border rounded-lg p-3"></div>
            <div>
                <label for="password" class="block text-sm font-medium mb-1">{{ $user->exists ? 'Parolă nouă (opțional)' : 'Parolă' }}</label>
                <input id="password" name="password" type="password" autocomplete="new-password" minlength="12" maxlength="72" @required(! $user->exists) class="w-full border rounded-lg p-3">
                <p class="text-xs text-gray-500 mt-1">Minimum 12 caractere. {{ $user->exists ? 'Lasă gol pentru a păstra parola. Schimbarea parolei închide sesiunile existente la următoarea cerere.' : '' }}</p>
            </div>
            <div><label for="password_confirmation" class="block text-sm font-medium mb-1">Confirmă parola</label><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" maxlength="72" @required(! $user->exists) class="w-full border rounded-lg p-3"></div>
            <div><label for="role" class="block text-sm font-medium mb-1">Rol</label>
                <select id="role" name="role" class="w-full border rounded-lg bg-white p-3">
                    <option value="user" @selected(old('role', $user->role ?? 'user') === 'user')>User</option>
                    <option value="admin" @selected(old('role', $user->role) === 'admin')>Admin</option>
                </select>
            </div>
            <input type="hidden" name="is_active" value="0">
            <label class="flex gap-2 items-center"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $user->is_active))> Cont activ</label>
            <p class="text-xs text-gray-500">Dezactivarea oprește accesul inclusiv pentru sesiunile deschise. Datele utilizatorului se păstrează.</p>
            <button class="rounded-lg bg-blue-600 text-white px-5 py-3 font-semibold">Salvează utilizatorul</button>
        </form>
    </section>
@endsection
