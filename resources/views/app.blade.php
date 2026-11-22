<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        {{--
            The tab icon, at the sizes a browser actually asks for.

            This pointed at public/ptri.png, which is the full-resolution seal
            (810x706, ~139 KB) — downloaded on every page load to be drawn into
            a 16px slot, and letterboxed by the browser because it is not
            square. public/favicon.ico was separately a zero-byte file, which
            is worse than absent: a browser requests /favicon.ico unprompted
            and an empty body is a broken image, not a fallback.

            These are all derived from public/ptri.png and regenerated from it
            if the seal is ever replaced; see documentation/design-system.md.
        --}}
        <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('icon-32.png') }}">
        <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('icon-16.png') }}">
        <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('icon-180.png') }}">
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
