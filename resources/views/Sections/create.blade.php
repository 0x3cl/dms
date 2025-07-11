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
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4);
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

    .error-list {
        background-color: #5a1e1e;
        border-left: 4px solid #ff4f4f;
        padding: 1rem;
        margin-bottom: 1rem;
        border-radius: 0.5rem;
    }

    .error-list li {
        color: #ffbfbf;
    }
</style>

<div class="container mt-5">
    <div class="card card-custom mx-auto" style="max-width: 600px;">
        <h1 class="text-center mb-4">Add New Section</h1>

        <a href="{{ route('sections.index') }}" class="btn btn-secondary mb-4">← Back to Sections List</a>

        @if ($errors->any())
            <div class="error-list">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form action="{{ route('sections.store') }}" method="POST" autocomplete="off">
            @csrf
            <div class="mb-3">
                <label for="name" class="form-label">Section Name</label>
                <input type="text" id="name" name="name" class="form-control" required>
            </div>

            <div class="mb-4">
                <label for="department_id" class="form-label">Department</label>
                <select id="department_id" name="department_id" class="form-control" required>
                    <option value="">-- Select Department --</option>
                    @foreach ($departments as $department)
                        <option value="{{ $department->id }}">{{ $department->name }}</option>
                    @endforeach
                </select>
            </div>

            <button type="submit" class="btn btn-primary px-4">Save</button>
        </form>
    </div>
</div>
@endsection
