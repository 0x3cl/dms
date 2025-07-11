@extends('layouts.app')

@section('content')
<style>
    body {
        background: linear-gradient(135deg, #031a3c, #0d3c7b);
        font-family: 'Poppins', sans-serif;
        min-height: 100vh;
        margin: 0;
    }

    .full-height {
        display: flex;
        justify-content: center;
        align-items: center;
        height: 100vh;
    }

    .login-card {
        background: rgba(255, 255, 255, 0.05);
        backdrop-filter: blur(12px);
        border-radius: 1.2rem;
        border: 1px solid rgba(255, 255, 255, 0.08);
        box-shadow: 0 8px 30px rgba(0, 0, 0, 0.25);
        padding: 2rem;
        width: 100%;
        max-width: 420px;
        color: #fff;
        animation: fadeIn 0.6s ease-in;
    }

    .login-card h4 {
        margin-bottom: 1.5rem;
        font-weight: 600;
        text-align: center;
    }

    .form-label {
        font-weight: 500;
        color: #cbd5e1;
    }

    .form-control {
        background-color: rgba(255, 255, 255, 0.08);
        border: none;
        color: #fff;
        border-radius: 0.5rem;
        transition: all 0.3s ease;
    }

    .form-control::placeholder {
        color: #cbd5e1;
    }

    .form-control:focus {
        box-shadow: 0 0 0 0.2rem rgba(42, 74, 139, 0.4);
        background-color: rgba(255, 255, 255, 0.12);
    }

    .btn-primary {
        background-color: #2a4a8b;
        border: none;
        border-radius: 0.5rem;
        padding: 0.6rem 1.5rem;
        font-weight: 600;
    }

    .btn-primary:hover {
        background-color: #3d5ca3;
    }

    .text-link {
        color: #a9c2f4;
        font-size: 0.95rem;
        text-align: center;
    }

    .text-link a {
        color: #c9def4;
        text-decoration: none;
    }

    .text-link a:hover {
        text-decoration: underline;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
</style>

<div class="full-height">
    <div class="login-card">
        <h4>{{ __('Login to DocTrack') }}</h4>

        @if(session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif

        <form method="POST" action="{{ route('login') }}">
            @csrf

            <!-- Employee ID -->
            <div class="mb-3">
                <label for="employee_id" class="form-label">{{ __('Employee ID') }}</label>
                <input id="employee_id" type="text"
                       class="form-control @error('employee_id') is-invalid @enderror"
                       name="employee_id" value="{{ old('employee_id') }}" required autofocus
                       placeholder="e.g. 2023-00123">
                @error('employee_id')
                    <div class="invalid-feedback text-danger">{{ $message }}</div>
                @enderror
            </div>

            <!-- Password -->
            <div class="mb-3">
                <label for="password" class="form-label">{{ __('Password') }}</label>
                <input id="password" type="password"
                       class="form-control @error('password') is-invalid @enderror"
                       name="password" required autocomplete="current-password"
                       placeholder="••••••••">
                @error('password')
                    <div class="invalid-feedback text-danger">{{ $message }}</div>
                @enderror
            </div>

            <!-- Remember Me -->
            <div class="mb-3 form-check">
                <input type="checkbox" name="remember" class="form-check-input"
                       id="remember" {{ old('remember') ? 'checked' : '' }}>
                <label class="form-check-label text-light" for="remember">{{ __('Remember Me') }}</label>
            </div>

            <div class="d-grid mb-3">
                <button type="submit" class="btn btn-primary">{{ __('Login') }}</button>
            </div>
        </form>

        <div class="text-link mt-3">
            <div>
                <a href="{{ route('register') }}">{{ __("Don't have an account? Register here") }}</a>
            </div>
            @if (Route::has('password.request'))
                <div class="mt-2">
                    <a href="{{ route('password.request') }}">{{ __('Forgot your password?') }}</a>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
