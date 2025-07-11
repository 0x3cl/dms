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

    .auth-card {
        background: rgba(255,255,255,0.05);
        backdrop-filter: blur(12px);
        border-radius: 1.2rem;
        border: 1px solid rgba(255,255,255,0.08);
        box-shadow: 0 8px 30px rgba(0,0,0,0.25);
        padding: 2rem;
        width: 100%;
        max-width: 420px;
        color: #fff;
        animation: fadeIn 0.6s ease-in;
    }

    h4 {
        margin-bottom: 1.5rem;
        font-weight: 600;
        text-align: center;
        color: #fff;
    }

    .form-label {
        font-weight: 500;
        color: #cbd5e1;
    }

    .form-control {
        background-color: rgba(255,255,255,0.08);
        border: none;
        color: #fff;
        border-radius: 0.5rem;
        transition: all 0.3s ease;
    }

    .form-control::placeholder {
        color: #cbd5e1;
    }

    .form-control:focus {
        box-shadow: 0 0 0 0.2rem rgba(42,74,139,0.4);
        background-color: rgba(255,255,255,0.12);
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
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }
</style>

<div class="full-height">
    <div class="auth-card">
        <h4>{{ __('Register for DocTrack') }}</h4>

        <form method="POST" action="{{ route('register') }}">
            @csrf

            <div class="mb-3">
                <label for="name" class="form-label">{{ __('Full Name') }}</label>
                <input id="name" type="text" class="form-control @error('name') is-invalid @enderror"
                    name="name" value="{{ old('name') }}" required placeholder="Juan Dela Cruz">
                @error('name')
                    <div class="invalid-feedback text-danger">{{ $message }}</div>
                @enderror
            </div>

            <div class="mb-3">
                <label for="employee_id" class="form-label">{{ __('Employee ID') }}</label>
                <input id="employee_id" type="text" class="form-control @error('employee_id') is-invalid @enderror"
                    name="employee_id" value="{{ old('employee_id') }}" required placeholder="e.g. 2023-00123">
                @error('employee_id')
                    <div class="invalid-feedback text-danger">{{ $message }}</div>
                @enderror
            </div>

            <div class="mb-3">
                <label for="division_name" class="form-label">{{ __('Division Name') }}</label>
                <input id="division_name" type="text" class="form-control @error('division_name') is-invalid @enderror"
                    name="division_name" value="{{ old('division_name') }}" required placeholder="e.g. Finance Division">
                @error('division_name')
                    <div class="invalid-feedback text-danger">{{ $message }}</div>
                @enderror
            </div>

            <div class="mb-3">
                <label for="section_name" class="form-label">{{ __('Section Name') }}</label>
                <input id="section_name" type="text" class="form-control @error('section_name') is-invalid @enderror"
                    name="section_name" value="{{ old('section_name') }}" required placeholder="e.g. Budget Section">
                @error('section_name')
                    <div class="invalid-feedback text-danger">{{ $message }}</div>
                @enderror
            </div>

            <div class="mb-3">
                <label for="password" class="form-label">{{ __('Password') }}</label>
                <input id="password" type="password" class="form-control @error('password') is-invalid @enderror"
                    name="password" required placeholder="••••••••">
                @error('password')
                    <div class="invalid-feedback text-danger">{{ $message }}</div>
                @enderror
            </div>

            <div class="mb-3">
                <label for="password_confirmation" class="form-label">{{ __('Confirm Password') }}</label>
                <input id="password_confirmation" type="password" class="form-control"
                    name="password_confirmation" required placeholder="••••••••">
            </div>

            <div class="d-grid mb-3">
                <button type="submit" class="btn btn-primary">{{ __('Register') }}</button>
            </div>
        </form>

        <div class="text-link mt-2">
            <a href="{{ route('login') }}">{{ __('Already registered? Login here') }}</a>
        </div>
    </div>
</div>
@endsection
