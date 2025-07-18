@extends('layouts.app')

@section('content')
<div class="container mt-5">
    <h2 class="mb-4 text-center">Document Logs</h2>

    <div class="card mb-4">
        <div class="card-body">
            <h5>Document Title: {{ $document->title }}</h5>
            <p>Code: <strong>{{ $document->code }}</strong></p>
        </div>
    </div>

    <div class="card">
        <div class="card-header">Logs History</div>
        <div class="table-responsive">
            <table class="table table-bordered mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>User</th>
                        <th>Division</th>
                        <th>Section</th>
                        <th>Action</th>
                        <th>Received At</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($document->logs as $index => $log)
                        <tr>
                            <td>{{ $index + 1 }}</td>
                            <td>{{ $log->user->name ?? '—' }}</td>
                            <td>{{ $log->division->name ?? '—' }}</td>
                            <td>{{ $log->section->name ?? '—' }}</td>
                            <td>{{ $log->action }}</td>
                            <td>{{ $log->received_at ? $log->received_at->format('Y-m-d H:i') : '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center">No logs found for this document.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
