@extends('layouts.private')
@section('title', 'Login — Tender Scanner')
@section('content')
    <section class="max-w-md mx-auto mt-6 sm:mt-12 rounded-xl border bg-white p-6 sm:p-8">
        <h1 class="text-2xl font-bold">Login</h1>
        <p class="text-sm text-gray-600 mt-2 mb-6">Acces privat, doar pentru utilizatori autorizați.</p>
        <form method="POST" action="{{ route('login.store') }}" class="space-y-5">
            @csrf
            <div>
                <label for="email" class="block text-sm font-medium mb-1">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus maxlength="255" class="w-full border rounded-lg p-3">
            </div>
            <div>
                <label for="password" class="block text-sm font-medium mb-1">Parolă</label>
                <input id="password" name="password" type="password" autocomplete="current-password" required maxlength="72" class="w-full border rounded-lg p-3">
            </div>
            <button class="w-full rounded-lg bg-blue-600 text-white font-semibold p-3 hover:bg-blue-700">Autentificare</button>
        </form>
        <p class="text-sm text-gray-500 mt-6">Pentru acces sau schimbarea parolei, contactează administratorul.</p>
    </section>
@endsection
