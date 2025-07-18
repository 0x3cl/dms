@extends('layouts.app')

@section('content')
<div class="container py-5">

    {{-- Session Alerts --}}
    @if (session('success'))
        <div class="alert alert-success" role="alert">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger" role="alert">{{ session('error') }}</div>
    @endif

    {{-- Document Information --}}
    <div class="d-flex justify-content-between align-items-center">
        <h2>Document Details</h2>
        @if ($document->status === 'ended')
            <span class="badge bg-danger fs-6">Status: Ended</span>
        @else
            <span class="badge bg-success fs-6">Status: Active</span>
        @endif
    </div>

    <p><strong>Title:</strong> {{ $document->title }}</p>
    <p><strong>Type:</strong> {{ $document->type ?? 'N/A' }}</p>
    <p><strong>Tracking Code:</strong> {{ $document->code }}</p>
    <p><strong>Uploaded By:</strong> {{ $document->user->name ?? 'N/A' }}</p>
    <p><strong>Uploader Division:</strong> {{ $document->user->division->name ?? 'N/A' }}</p>
    <p><strong>Uploader Section:</strong> {{ $document->user->section->name ?? 'N/A' }}</p>
    
    {{-- NEW: Visibility and Recipients List --}}
    <hr>
    <div>
        <h5>Visibility</h5>
        @if($document->visibility === 'private')
            <p class="mb-1">
                <span class="badge bg-warning text-dark">Private</span> This document is visible only to the uploader and the following recipients:
            </p>
            <div class="d-flex flex-wrap gap-2 mt-2">
                @forelse($document->recipients as $recipient)
                    <span class="badge bg-secondary">{{ $recipient->name }}</span>
                @empty
                    <span class="badge bg-secondary">No recipients were assigned.</span>
                @endforelse
            </div>
        @else
            <p><span class="badge bg-info">Public</span> This document is visible to everyone in the system.</p>
        @endif
    </div>
    <hr>


    @auth
    {{-- Actions Section --}}
    <h4 class="mt-4">Actions</h4>
    <div class="d-flex flex-wrap align-items-center gap-2 mb-4">
        
        {{-- "View File" button is now always visible to everyone --}}
        <a href="{{ route('documents.accessFile', ['id' => $document->id, 'action' => 'view']) }}" class="btn btn-info" target="_blank">View File</a>

        {{-- Logic to check if the current user has already received this document --}}
        @php
            $hasReceived = $logs->where('user_id', auth()->id())->where('action', 'Received and Downloaded')->isNotEmpty();
        @endphp

        {{-- Receive Button: Show only if doc is active and user hasn't received it yet --}}
        @if ($document->status !== 'ended' && !$hasReceived)
            <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#receiveModal">
                Receive
            </button>
        @elseif($hasReceived)
             <p class="text-white mb-0">You have already received this document.</p>
        @else
            {{-- Hide the "no further actions" message for the uploader on their own ended doc --}}
            @if(auth()->id() !== $document->user_id)
                <p class="text-white mb-0">This document has been ended. No further actions can be taken.</p>
            @endif
        @endif

        {{-- End Document Button: Show to uploader if doc is active --}}
        @if (auth()->id() === $document->user_id && $document->status !== 'ended')
            <button type="button" class="btn btn-danger ms-md-auto" data-bs-toggle="modal" data-bs-target="#endDocumentModal">
                End Document Lifecycle
            </button>
        @endif
        
        <a href="{{ $backUrl ?? route('dashboard') }}" class="btn btn-outline-secondary ms-auto">← Return</a>
    </div>
    <hr>
    @endauth

    {{-- Tracking History --}}
    <h4 class="mt-4">Tracking History</h4>
    @if ($logs->isEmpty())
        <p>No tracking logs yet.</p>
    @else
        <ul class="list-group">
            @foreach ($logs as $log)
                <li class="list-group-item">
                    <div class="d-flex w-100 justify-content-between">
                        <h6 class="mb-1">
                            <strong>{{ $log->division->name ?? 'Unknown Division' }}</strong> - 
                            {{ $log->section->name ?? 'Unknown Section' }}
                        </h6>
                        <small>{{ \Carbon\Carbon::parse($log->received_at)->diffForHumans() }}</small>
                    </div>
                    <p class="mb-1">
                        <em>Action:</em> {{ $log->action ?? 'N/A' }} by {{ $log->user->name ?? 'Unknown User' }}
                    </p>
                    
                    @if($log->remarks)
                        <p class="mb-1 mt-2 p-2 bg-light border rounded" style="white-space: pre-wrap;"><strong>Remarks:</strong> {{ $log->remarks }}</p>
                    @endif
                    
                    <small class="text-muted">{{ \Carbon\Carbon::parse($log->received_at)->format('F d, Y h:i A') }}</small>
                </li>
            @endforeach
        </ul>
    @endif
</div>

@auth
{{-- Modals --}}

{{-- Receive and Download Modal --}}
<div class="modal fade" id="receiveModal" tabindex="-1" aria-labelledby="receiveModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="receiveModalLabel">Receive Document</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form action="{{ route('documents.receiveFile', $document->id) }}" method="POST">
          @csrf
          <div class="modal-body">
              <p>This will log that you have received the document and start the file download.</p>
              <div class="mb-3">
                  <label for="remarks" class="form-label">Remarks (Optional)</label>
                  <textarea class="form-control" id="remarks" name="remarks" rows="4" placeholder="Add any notes or comments here..."></textarea>
              </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-success">Receive and Download</button>
          </div>
      </form>
    </div>
  </div>
</div>

{{-- End Document Confirmation Modal (only rendered for the uploader if document is active) --}}
@if (auth()->id() === $document->user_id && $document->status !== 'ended')
<div class="modal fade" id="endDocumentModal" tabindex="-1" aria-labelledby="endDocumentModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="endDocumentModalLabel">Confirm End Document Lifecycle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to mark this document as ended?</p>
                <p class="text-danger"><strong>This action cannot be undone.</strong> Other users will no longer be able to receive this file.</p>
            </div>
            <div class="modal-footer">
                <form action="{{ route('documents.end', $document->id) }}" method="POST">
                    @csrf
                    @method('PATCH')
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Yes, End Document</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endif

@endauth
@endsection
