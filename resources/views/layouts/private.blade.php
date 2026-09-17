<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Tender Scanner')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="app-shell @guest guest-shell @endguest">
    <x-navigation />
    <main id="main-content" class="app-main" tabindex="-1">
        @if(session('message'))<p role="status" class="mb-4 rounded-lg bg-green-50 p-4 text-green-800">{{ session('message') }}</p>@endif
        @if($errors->any() && !request()->routeIs('notices.*'))
            <div role="alert" class="mb-4 rounded-lg bg-red-50 p-4 text-red-700">
                @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
            </div>
        @endif
        @yield('content')
    </main>
</body>
</html>
