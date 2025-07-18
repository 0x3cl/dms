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

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" xintegrity="sha512-Avb2QiuDEEvB4bZJYdft2mNjVShBftLdPG8FJ0V7irTLQ8UoGqcPxhU9YAUckvVFeTTSkbcCGs2G1LVzmbcxzQ==" crossorigin="anonymous" referrerpolicy="no-referrer" />


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
        
        /* Styles for notification dropdown */
        .notification-dropdown .dropdown-menu {
            width: 350px;
            padding: 0;
        }
        .notification-dropdown .dropdown-item {
            white-space: normal;
            border-bottom: 1px solid #dee2e6;
        }
        .notification-dropdown .dropdown-item:last-child {
            border-bottom: none;
        }
    </style>

    @yield('head')
</head>
<body>
    {{-- You would typically have a navbar here. I'll add a placeholder one with the notification logic. --}}
    <nav class="navbar navbar-expand-lg navbar-dark navbar-custom">
        <div class="container-fluid">
            <a class="navbar-brand" href="{{ route('dashboard') }}">DocTrack</a>
            <div class="d-flex ms-auto">
                @auth
                    <!-- NEW: Notifications Dropdown -->
                    <div class="dropdown notification-dropdown">
                        <a class="nav-link text-white" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-bell"></i>
                            @if(auth()->user()->unreadNotifications->count())
                                <span class="badge rounded-pill bg-danger">
                                    {{ auth()->user()->unreadNotifications->count() }}
                                </span>
                            @endif
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li class="p-2">
                                <h6 class="dropdown-header">Notifications</h6>
                            </li>
                            @forelse(auth()->user()->unreadNotifications as $notification)
                                <li>
                                    <a class="dropdown-item p-2" href="{{ route('notifications.read', $notification->id) }}">
                                        <div class="small">{{ $notification->data['message'] }}</div>
                                        <div class="small text-muted">{{ $notification->created_at->diffForHumans() }}</div>
                                    </a>
                                </li>
                            @empty
                                <li><p class="text-center text-muted p-3">No new notifications.</p></li>
                            @endforelse
                            @if(auth()->user()->unreadNotifications->count())
                            <li class="p-2 text-center">
                                <a href="{{ route('notifications.markAllRead') }}" class="small">Mark all as read</a>
                            </li>
                            @endif
                        </ul>
                    </div>
                @endauth
            </div>
        </div>
    </nav>


    @yield('content')

    <!-- ✅ JS Libraries -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>

    <!-- ✅ Blade Stack for Page-specific Scripts -->
    @stack('scripts')
</body>
</html>
