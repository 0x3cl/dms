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
        padding: 2rem;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4);
    }

    .btn-primary {
        background-color: #2a4a8b;
        border: none;
        box-shadow: 0 4px 12px rgba(42, 74, 139, 0.5);
    }

    .btn-primary:hover {
        background-color: #3c5eb3;
    }

    .btn-danger {
        background-color: #d9534f;
        border: none;
    }

    .btn-warning {
        background-color: #f0ad4e;
        border: none;
        color: #000;
    }

    .table {
        color: #e1e7f0;
        border-color: #395278;
    }

    .table thead {
        background-color: #2c4168;
    }

    .alert-success {
        background-color: #3d6c3d;
        color: #d1f0d1;
        border-left: 4px solid #4fe14f;
        padding: 0.75rem 1rem;
        margin-bottom: 1rem;
        border-radius: 0.5rem;
    }
</style>

<div class="container mt-5">
    <div class="card card-custom">
        <h1 class="text-center mb-4">Sections</h1>

        <div class="d-flex justify-content-between mb-3">
            <a href="{{ url('/') }}" class="btn btn-secondary">← Back to Dashboard</a>
            <a href="{{ route('sections.create') }}" class="btn btn-primary">+ Add Section</a>
        </div>

        @if (session('success'))
            <div class="alert-success">
                {{ session('success') }}
            </div>
        @endif

        <div class="table-responsive">
            <table class="table table-bordered rounded">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Section Name</th>
                        <th>Department</th>
                        <th style="width: 180px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($sections as $section)
                        <tr>
                            <td>{{ $section->id }}</td>
                            <td>{{ $section->name }}</td>
                            <td>{{ $section->department->name ?? 'N/A' }}</td>
                            <td>
                                <a href="{{ route('sections.edit', $section->id) }}" class="btn btn-sm btn-warning">Edit</a>

                                <form action="{{ route('sections.destroy', $section->id) }}" method="POST" style="display:inline;">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Delete this section?')">Delete</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center">No sections found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
