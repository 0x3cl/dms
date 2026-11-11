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
        max-width: 700px;
        margin: auto;
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
    <div class="card card-custom">
        <h1 class="text-center mb-4">Edit Employee</h1>

        <a href="{{ route('employees.index') }}" class="btn btn-secondary mb-4">← Back to Employee List</a>

        @if ($errors->any())
            <div class="error-list">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form action="{{ route('employees.update', $employee->id) }}" method="POST" autocomplete="off">
            @csrf
            @method('PUT')

            <div class="mb-3">
                <label for="employee_id" class="form-label">Employee ID</label>
                <input type="text" id="employee_id" name="employee_id" value="{{ old('employee_id', $employee->employee_id) }}" class="form-control" required>
            </div>

            <div class="mb-3">
                <label for="first_name" class="form-label">First Name</label>
                <input type="text" id="first_name" name="first_name" value="{{ old('first_name', $employee->first_name) }}" class="form-control" required>
            </div>

            <div class="mb-3">
                <label for="last_name" class="form-label">Last Name</label>
                <input type="text" id="last_name" name="last_name" value="{{ old('last_name', $employee->last_name) }}" class="form-control" required>
            </div>

            <div class="mb-3">
                <label for="email" class="form-label">Email</label>
                <input type="email" id="email" name="email" value="{{ old('email', $employee->email) }}" class="form-control" required>
            </div>

            <div class="mb-4">
                <label for="section_id" class="form-label">Section</label>
                <select id="section_id" name="section_id" class="form-control" required>
                    <option value="">-- Select Section --</option>
                    @foreach ($sections as $section)
                        <option value="{{ $section->id }}" {{ $employee->section_id == $section->id ? 'selected' : '' }}>
                            {{ $section->name }} ({{ $section->department->name ?? 'No Department' }})
                        </option>
                    @endforeach
                </select>
            </div>

            <button type="submit" class="btn btn-primary px-4">Update</button>
        </form>
    </div>
</div>
@endsection
