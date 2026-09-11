<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Tender Scanner')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-100 text-gray-900">
    <x-navigation />
    <main class="max-w-5xl mx-auto px-4 py-8">
        @if(session('message'))<p role="status" class="mb-4 rounded-lg bg-green-50 p-4 text-green-800">{{ session('message') }}</p>@endif
        @if($errors->any())
            <div role="alert" class="mb-4 rounded-lg bg-red-50 p-4 text-red-700">
                @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
            </div>
        @endif
        @yield('content')
    </main>
</body>
</html>
