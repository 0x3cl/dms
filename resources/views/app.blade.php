<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <link rel="icon" href="{{ asset('ptri.png') }}">
        {{--
            Applies the saved theme before the first paint, so a dark-mode user never sees a flash of
            the light theme while the bundle loads. Mirrors the key and the `theme` shape in
            resources/js/preferences.js — change one and change the other.
        --}}
        <script>
            try {
                if (JSON.parse(localStorage.getItem('dms.preferences') || '{}').theme === 'dark') {
                    document.documentElement.classList.add('dark');
                }
            } catch (e) {}
        </script>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
