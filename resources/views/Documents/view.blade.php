@extends('layouts.app')

@section('content')
<div class="container py-5">
    <h2>Document Details</h2>

    <p><strong>Title:</strong> {{ $document->title }}</p>
    <p><strong>Type:</strong> {{ $document->type ?? 'N/A' }}</p>
    <p><strong>Tracking Code:</strong> {{ $document->code }}</p>
    <p><strong>Uploaded By:</strong> {{ $document->user->name ?? 'N/A' }}</p>


    <hr>
    <h4>Tracking History</h4>
    @if ($logs->isEmpty())
        <p>No tracking logs yet.</p>
    @else
        <ul class="list-group">
            @foreach ($logs as $log)
                <li class="list-group-item">
                    <strong>{{ $log->department }}</strong> - {{ $log->section }}<br>
                    <small class="text-muted">{{ $log->logged_at->format('F d, Y h:i A') }}</small>
                </li>
            @endforeach
        </ul>
    @endif

    <hr>
    <h4>Download History</h4>
    @if ($downloads->isEmpty())
        <p>No one has downloaded this document yet.</p>
    @else
        <ul class="list-group">
            @foreach ($downloads as $download)
                <li class="list-group-item">
                    <strong>{{ $download->user->name ?? 'Unknown User' }}</strong><br>
                    <small>Department:</small> {{ $download->department }}<br>
                    <small>Section:</small> {{ $download->section }}<br>
                    <small class="text-muted">{{ \Carbon\Carbon::parse($download->downloaded_at)->format('F d, Y h:i A') }}</small>
                </li>
            @endforeach
        </ul>
    @endif

    <hr>
    @auth
    {{-- Log Movement Form --}}
    <h4 class="mt-4">Log Document Movement</h4>
    <form action="{{ route('document.log') }}" method="POST" class="mt-3">
        @csrf
        <input type="hidden" name="document_id" value="{{ $document->id }}">
        <div class="mb-2">
            <input type="text" name="department" class="form-control" placeholder="Department" required>
        </div>
        <div class="mb-2">
            <input type="text" name="section" class="form-control" placeholder="Section" required>
        </div>
        <button class="btn btn-success">Log Activity</button>
    </form>
    @endauth
</div>
@endsection
