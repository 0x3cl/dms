<!DOCTYPE html>
<html lang="en" style="height: 100%; background-color: #073263;">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- ✅ Important: CSRF Token -->
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', 'DocTrack Dashboard')</title>

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Google Fonts: Poppins -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">

    <style>
        body {
            background-color: #073263;
            color: #e1e7f0;
            font-family: 'Poppins', sans-serif;
        }

        .navbar-custom {
            background-color: #051e40;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
        }

        .sidebar {
            background-color: #051e40;
            color: white;
            height: 100vh;
        }

        .nav-link.active {
            background-color: #2a4a8b;
            color: white !important;
        }

        .nav-link {
            color: #a9c0e2;
        }

        .btn-primary {
            background-color: #2a4a8b;
            border: none;
        }

        .btn-primary:hover {
            background-color: #4064a9;
        }
    </style>

    @yield('head')
</head>
<body>
    @yield('content')

    <!-- ✅ JS Libraries -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>

    <!-- ✅ Blade Stack for Page-specific Scripts -->
    @stack('scripts')
</body>
</html>
