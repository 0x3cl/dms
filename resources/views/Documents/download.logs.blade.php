@extends('layouts.app')

@section('content')
<div class="container mt-5">
    <h1 class="mb-4">Download Logs</h1>

    <table class="table table-bordered">
        <thead class="table-light">
            <tr>
                <th>Document</th>
                <th>Downloaded By</th>
                <th>Department</th>
                <th>Section</th>
                <th>Date & Time</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($downloads as $log)
                <tr>
                    <td>{{ $log->document->title ?? 'Deleted Document' }}</td>
                    <td>{{ $log->user->name ?? 'Guest' }}</td>
                    <td>{{ $log->department }}</td>
                    <td>{{ $log->section }}</td>
                    <td>{{ \Carbon\Carbon::parse($log->downloaded_at)->format('F j, Y - g:i A') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="text-center text-muted">No downloads yet.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <a href="{{ url()->previous() }}" class="btn btn-secondary mt-3">← Back</a>
</div>
@endsection
