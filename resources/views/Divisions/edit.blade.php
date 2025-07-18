@extends('layouts.app')

@section('content')
<style>
    body {
        background-color: #073263;
        color: #e1e7f0;
        font-family: 'Poppins', sans-serif;
    }

    .card-custom {
        background-color: #051e40;
        border: none;
        border-radius: 1rem;
        padding: 2.5rem;
        box-shadow: 0 4px 12px rgba(0,0,0,0.4);
    }

    .form-label {
        font-weight: 600;
        color: #d7e2f3;
    }

    .form-control {
        background-color: #0b2f59;
        border: 1px solid #395278;
        color: #fff;
    }

    .form-control:focus {
        border-color: #648fd3;
        box-shadow: 0 0 0 0.2rem rgba(100, 143, 211, 0.25);
    }

    .btn-primary {
        background-color: #2a4a8b;
        border: none;
        box-shadow: 0 4px 12px rgba(42, 74, 139, 0.5);
    }

    .btn-primary:hover {
        background-color: #3c5eb3;
    }

    .btn-secondary {
        background-color: #4d648d;
        border: none;
    }
</style>

<div class="container mt-5">
    <div class="card card-custom mx-auto" style="max-width: 600px;">
        <h1 class="text-center mb-3">Department of Science and Technology</h1>
        <h2 class="text-center mb-4">Edit Division</h2>

        <a href="{{ url()->previous() }}" class="btn btn-secondary mb-4">← Return</a>

        <form method="POST" action="{{ route('divisions.update', $division->id) }}">
            @csrf
            @method('PUT')

            <div class="mb-3">
                <label for="name" class="form-label">Division Name</label>
                <input type="text" name="name" id="name" value="{{ old('name', $division->name) }}" class="form-control" required autocomplete="off">
            </div>

            <button type="submit" class="btn btn-primary px-4">Update</button>
        </form>
    </div>
</div>
@endsection
