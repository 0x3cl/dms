@extends('layouts.app')

@section('content')
<style>
    body {
        background-color: #073263;
        color: #e1e7f0;
        font-family: 'Poppins', sans-serif;
    }

    h1, h2 {
        color: #d7e2f3;
        text-shadow: 0 1px 3px rgba(0, 0, 0, 0.5);
    }

    .card-custom {
        background-color: #051e40;
        border: none;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4);
        border-radius: 1rem;
        padding: 2rem;
    }

    .btn-primary {
        background-color: #2a4a8b;
        border: none;
        box-shadow: 0 4px 12px rgba(42, 74, 139, 0.5);
        transition: background-color 0.3s ease;
    }

    .btn-primary:hover {
        background-color: #3c5eb3;
    }

    .btn-secondary {
        background-color: #4d648d;
        border: none;
    }

    .table {
        color: #e1e7f0;
        border-color: #395278;
    }

    .table thead {
        background-color: #2c4168;
    }

    .table td, .table th {
        vertical-align: middle;
    }

    .btn-warning {
        background-color: #f0ad4e;
        border: none;
        color: #000;
    }

    .btn-danger {
        background-color: #d9534f;
        border: none;
    }
</style>

<div class="container mt-5">
    <div class="card card-custom">
        <h1 class="mb-2 text-center">Department of Science and Technology</h1>
        <h2 class="mb-4 text-center">Divisions List</h2>

        <div class="d-flex justify-content-between mb-4">
            <a href="{{ url('/') }}" class="btn btn-secondary">← Back to Dashboard</a>
            <a href="{{ route('divisions.create') }}" class="btn btn-primary">+ Add Division</a>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered rounded">
                <thead>
                    <tr>
                        <th>Division ID</th>
                        <th>Division Name</th>
                        <th style="width: 180px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($divisions as $division)
                        <tr>
                            <td>{{ $division->id }}</td>
                            <td>{{ $division->name }}</td>
                            <td>
                                <a href="{{ route('divisions.edit', $division->id) }}" class="btn btn-sm btn-warning">Edit</a>

                                <form action="{{ route('divisions.destroy', $division->id) }}" method="POST" style="display:inline;">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-danger"
                                            onclick="return confirm('Are you sure you want to delete this division?')">
                                        Delete
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="text-center">No divisions found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
